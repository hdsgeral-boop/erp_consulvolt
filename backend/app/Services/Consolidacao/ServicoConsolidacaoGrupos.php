<?php

namespace App\Services\Consolidacao;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\ExecucaoConsolidacao;
use App\Models\GrupoConsolidacao;
use App\Models\MembroConsolidacao;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Grupos de consolidação (holdings) — js/consolidacao.js:745-1023.
 * A holding é uma empresa do sistema (empresas.e_consolidacao) criada com o grupo; o grupo, os membros e as execuções pertencem
 * à holding (empresa_id = holding). Os membros são consolidados a 100% (método INTEGRAL), como no legado.
 * Acesso: um grupo só é visível a quem tem acesso à holding (consolidacao.js:765) e só se gravam membros a que o utilizador
 * tem acesso (empresasPermitidas, consolidacao.js:746-753); o superadministrador vê todos.
 * Correcções face ao legado:
 *   - o NIF da holding é obrigatório e único entre as empresas activas (regra do esquema; o legado aceitava vazio ou repetido);
 *   - uma holding não pode ser membro de outro grupo nem de si própria (o legado só a escondia da lista);
 *   - eliminar o grupo apaga só as linhas GERADAS pela consolidação e recusa se a holding tiver lançamentos manuais; o legado apagava
 *     todas as linhas, o plano e as tabelas da holding (consolidacao.js:999-1023). A holding fica eliminada logicamente;
 *   - os prefixos excluídos das eliminações cabem em 10 caracteres (tamanho da coluna; ver o relatório do agente).
 */
final class ServicoConsolidacaoGrupos
{
    public const MOEDA_BASE = 'AOA';

    public const CONTA_RESERVA_PADRAO = '5.9.9';

    public const CONTA_DIFERENCA_PADRAO = '5.9.8';

    public const PREFIXOS_EXCLUIDOS_PADRAO = '4, 34';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** Grupos visíveis ao utilizador, com membros, última execução e as cinco execuções mais recentes. */
    public function listar(): array
    {
        $acessiveis = $this->empresas->idsAcessiveis($this->utilizador());

        return $this->contexto->semIsolamento(fn () => GrupoConsolidacao::query()->whereIn('empresa_holding_id', $acessiveis)->orderBy('nome')->get()
            ->map(fn (GrupoConsolidacao $g) => $this->apresentar($g))->all());
    }

    public function obter(int $id): array
    {
        return $this->apresentar($this->grupo($id));
    }

    /**
     * Grupo com acesso verificado à holding (e, se pedido, a todas as empresas do grupo).
     */
    public function grupo(int $id, bool $exigirMembros = false): GrupoConsolidacao
    {
        $g = $this->contexto->semIsolamento(fn () => GrupoConsolidacao::query()->find($id));
        $u = $this->utilizador();
        if (! $g || ! $this->empresas->podeAceder($u, (int) $g->empresa_holding_id)) {
            throw new ErroNegocio('Grupo de consolidação não encontrado.', 'GRUPO_INEXISTENTE', 404);
        }
        if ($exigirMembros) {
            $semAcesso = array_values(array_filter($this->membros($g), fn ($e) => ! $this->empresas->podeAceder($u, $e)));
            if ($semAcesso) {
                throw new ErroNegocio('Não tem acesso a todas as empresas do grupo.', 'SEM_ACESSO_EMPRESAS_GRUPO', 403, ['empresas' => $semAcesso]);
            }
        }

        return $g;
    }

    /** @return list<int> ids das empresas membro */
    public function membros(GrupoConsolidacao $g): array
    {
        return $this->contexto->semIsolamento(fn () => MembroConsolidacao::query()->where('grupo_consolidacao_id', $g->id)->orderBy('id')
            ->pluck('empresa_membro_id')->filter()->map(fn ($x) => (int) $x)->unique()->values()->all());
    }

    /**
     * Cria a holding (empresa de consolidação), o grupo e os membros (consolGravarGrupo, consolidacao.js:895-934).
     *
     * @param  array{nome: string, nif: string, moeda_apresentacao?: ?string, conta_reserva_cambial?: ?string, eliminacao_ativa?: bool,
     *               prefixos_excluidos_eliminacao?: ?string, conta_diferenca_eliminacao?: ?string, membros: list<int>}  $d
     */
    public function criar(array $d): array
    {
        $membros = $this->validarMembros($d['membros'], null);
        $this->exigirNifLivre($d['nif'], null);

        $grupo = DB::transaction(function () use ($d, $membros) {
            $holding = Empresa::create(['nome' => $d['nome'], 'nif' => $d['nif'], 'endereco' => 'Holding — contas consolidadas', 'e_consolidacao' => true,
                'moeda_consolidacao' => $d['moeda_apresentacao'] ?? self::MOEDA_BASE, 'estado' => Empresa::ESTADO_ATIVO]);
            $u = $this->utilizador();
            if (! $u->eSuperAdministrador() && ! $u->acesso_todas_empresas) {
                $u->empresas()->syncWithoutDetaching([$holding->id]);
                $this->empresas->invalidarUtilizador((int) $u->getKey());
            }

            return $this->contexto->executarComo($holding->id, function () use ($d, $holding, $membros) {
                $g = GrupoConsolidacao::create(['empresa_holding_id' => $holding->id, 'nome' => $d['nome']] + $this->configuracao($d));
                $this->gravarMembros($g, $membros);
                $this->auditoria->registar('Contabilidade', 'Criou grupo de consolidação', "Holding {$holding->id} com as empresas ".implode(', ', $membros), 'grupos_consolidacao', $g->id);

                return $g;
            });
        });

        return $this->apresentar($grupo);
    }

    /** Edita a holding (nome, NIF), a configuração e substitui os membros. */
    public function atualizar(int $id, array $d): array
    {
        $g = $this->grupo($id);
        $membros = $this->validarMembros($d['membros'], (int) $g->empresa_holding_id);
        $this->exigirNifLivre($d['nif'], (int) $g->empresa_holding_id);

        DB::transaction(function () use ($g, $d, $membros) {
            // por DB e não pelo modelo: o Auditavel lê empresa_id de Empresa e falha em modo estrito (ver o relatório do agente)
            DB::table('empresas')->where('id', $g->empresa_holding_id)->update(['nome' => $d['nome'], 'nif' => $d['nif'], 'atualizado_em' => now()]);
            $this->contexto->executarComo((int) $g->empresa_holding_id, function () use ($g, $d, $membros) {
                $g->update(['nome' => $d['nome']] + $this->configuracao($d));
                MembroConsolidacao::query()->where('grupo_consolidacao_id', $g->id)->get()->each->delete();
                $this->gravarMembros($g, $membros);
                $this->auditoria->registar('Contabilidade', 'Alterou grupo de consolidação', 'Empresas: '.implode(', ', $membros), 'grupos_consolidacao', $g->id);
            });
        });

        return $this->apresentar($g->refresh());
    }

    /**
     * Elimina o grupo: linhas geradas pela consolidação, saldos históricos consolidados, execuções, membros, grupo e (logicamente) a holding.
     */
    public function eliminar(int $id): void
    {
        $g = $this->grupo($id);
        $holding = (int) $g->empresa_holding_id;
        if ($this->contexto->id() === $holding) {
            throw new ErroNegocio('Mude primeiro para outra empresa activa antes de eliminar esta holding.', 'HOLDING_ACTIVA', 422);
        }
        DB::transaction(function () use ($g, $holding) {
            DB::table('grupos_consolidacao')->where('id', $g->id)->lockForUpdate()->first(['id']);
            $manuais = DB::table('lancamentos_contabeis')->where('empresa_id', $holding)->whereNull('execucao_consolidacao_id')->whereNull('tipo_consolidacao')->count();
            if ($manuais > 0) {
                throw new ErroNegocio("A holding tem {$manuais} linhas de lançamentos manuais: estorne-as ou mantenha a holding.", 'HOLDING_COM_LANCAMENTOS', 422);
            }
            $linhas = DB::table('lancamentos_contabeis')->where('empresa_id', $holding)->delete();
            DB::table('saldos_historicos')->where('empresa_id', $holding)->delete();
            $this->contexto->executarComo($holding, function () use ($g) {
                $g->update(['ultima_execucao_id' => null]);
                DB::table('empresas')->where('id', $g->empresa_holding_id)->update(['execucao_consolidacao_id' => null]);
                ExecucaoConsolidacao::query()->where('grupo_consolidacao_id', $g->id)->get()->each->delete();
                MembroConsolidacao::query()->where('grupo_consolidacao_id', $g->id)->get()->each->delete();
                $g->delete();
            });
            DB::table('empresas')->where('id', $holding)->update(['eliminado_em' => now(), 'atualizado_em' => now()]);
            $this->empresas->invalidarTodos();
            $this->auditoria->registar('Contabilidade', 'Eliminou grupo de consolidação', "Grupo {$g->id}, holding {$holding}: {$linhas} linhas consolidadas apagadas.", 'grupos_consolidacao', $g->id);
        });
    }

    /** Execução com os totais e o mapa de divergências das eliminações. */
    public function execucao(int $id): array
    {
        $e = $this->contexto->semIsolamento(fn () => ExecucaoConsolidacao::query()->find($id));
        if (! $e) {
            throw new ErroNegocio('Execução de consolidação não encontrada.', 'EXECUCAO_INEXISTENTE', 404);
        }
        $this->grupo((int) $e->grupo_consolidacao_id);

        return $this->apresentarExecucao($e, true);
    }

    // ───────────── Auxiliares ─────────────

    private function apresentar(GrupoConsolidacao $g): array
    {
        return $this->contexto->semIsolamento(function () use ($g) {
            $holding = Empresa::withTrashed()->find($g->empresa_holding_id);
            $membros = MembroConsolidacao::query()->where('grupo_consolidacao_id', $g->id)->orderBy('id')->get();
            $nomes = Empresa::withTrashed()->whereIn('id', $membros->pluck('empresa_membro_id'))->get(['id', 'nome', 'nif'])->keyBy('id');
            $execucoes = ExecucaoConsolidacao::query()->where('grupo_consolidacao_id', $g->id)->orderByDesc('id')->limit(5)->get();

            return ['id' => $g->id, 'nome' => $g->nome, 'holding' => $holding ? ['id' => $holding->id, 'nome' => $holding->nome, 'nif' => $holding->nif] : null,
                'moeda_apresentacao' => $g->moeda_apresentacao ?? self::MOEDA_BASE, 'conta_reserva_cambial' => $g->conta_reserva_cambial ?? self::CONTA_RESERVA_PADRAO,
                'eliminacao_ativa' => $g->eliminacao_ativa !== false,
                'prefixos_excluidos_eliminacao' => $g->prefixos_excluidos_eliminacao ?? self::PREFIXOS_EXCLUIDOS_PADRAO,
                'conta_diferenca_eliminacao' => $g->conta_diferenca_eliminacao ?? self::CONTA_DIFERENCA_PADRAO,
                'membros' => $membros->map(fn ($m) => ['empresa_id' => $m->empresa_membro_id, 'nome' => $nomes[$m->empresa_membro_id]->nome ?? "Empresa {$m->empresa_membro_id}",
                    'nif' => $nomes[$m->empresa_membro_id]->nif ?? null, 'percentagem' => (string) $m->percentagem, 'metodo' => $m->metodo])->all(),
                'ultima_execucao_id' => $g->ultima_execucao_id,
                'execucoes' => $execucoes->map(fn ($e) => $this->apresentarExecucao($e, false))->all()];
        });
    }

    public function apresentarExecucao(ExecucaoConsolidacao $e, bool $completo): array
    {
        $t = $e->totais ?? [];
        $base = ['id' => $e->id, 'grupo_consolidacao_id' => $e->grupo_consolidacao_id, 'data_execucao' => $e->data_execucao?->toDateString(),
            'executado_em' => $e->executado_em?->toIso8601String(), 'data_fim' => $e->data_fim?->toDateString(), 'moeda' => $e->codigo_moeda, 'estado' => $e->estado,
            'utilizador' => $e->nome_utilizador, 'linhas' => $t['linhas'] ?? null];

        return $completo ? $base + ['totais' => $t] : $base;
    }

    private function configuracao(array $d): array
    {
        return ['moeda_apresentacao' => strtoupper($d['moeda_apresentacao'] ?? self::MOEDA_BASE),
            'conta_reserva_cambial' => ($d['conta_reserva_cambial'] ?? '') !== '' ? $d['conta_reserva_cambial'] : self::CONTA_RESERVA_PADRAO,
            'eliminacao_ativa' => (bool) ($d['eliminacao_ativa'] ?? true),
            'prefixos_excluidos_eliminacao' => array_key_exists('prefixos_excluidos_eliminacao', $d) ? trim((string) $d['prefixos_excluidos_eliminacao']) : self::PREFIXOS_EXCLUIDOS_PADRAO,
            'conta_diferenca_eliminacao' => ($d['conta_diferenca_eliminacao'] ?? '') !== '' ? $d['conta_diferenca_eliminacao'] : self::CONTA_DIFERENCA_PADRAO];
    }

    private function gravarMembros(GrupoConsolidacao $g, array $membros): void
    {
        foreach ($membros as $e) {
            MembroConsolidacao::create(['grupo_consolidacao_id' => $g->id, 'empresa_membro_id' => $e, 'percentagem' => 100, 'metodo' => 'INTEGRAL']);
        }
    }

    /** @return list<int> */
    private function validarMembros(array $membros, ?int $holding): array
    {
        $membros = array_values(array_unique(array_map('intval', $membros)));
        if (! $membros) {
            throw new ErroNegocio('Escolha pelo menos uma empresa a consolidar.', 'GRUPO_SEM_EMPRESAS', 422);
        }
        $u = $this->utilizador();
        $empresas = Empresa::query()->whereIn('id', $membros)->get(['id', 'e_consolidacao'])->keyBy('id');
        $invalidas = array_values(array_filter($membros, fn ($e) => ! isset($empresas[$e]) || $empresas[$e]->e_consolidacao || $e === $holding
            || ! $this->empresas->podeAceder($u, $e)));
        if ($invalidas) {
            throw new ErroNegocio('Só se consolidam empresas activas a que tem acesso e que não sejam holdings.', 'MEMBROS_INVALIDOS', 422, ['empresas' => $invalidas]);
        }

        return $membros;
    }

    private function exigirNifLivre(string $nif, ?int $holding): void
    {
        if (Empresa::query()->where('nif', $nif)->when($holding, fn ($q) => $q->whereKeyNot($holding))->exists()) {
            throw new ErroNegocio('Já existe uma empresa activa com este NIF.', 'NIF_DUPLICADO', 422);
        }
    }

    private function utilizador(): Utilizador
    {
        /** @var Utilizador $u */
        $u = Auth::user();

        return $u;
    }
}
