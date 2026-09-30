<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\CentroCusto;
use App\Models\ConfiguracaoProjeto;
use App\Models\LogAtividadeProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ficha do projecto/obra, estados, carteira, Gantt global, registo de actividade e configuração
 * (showCreateProjectModal / saveNewProject / changeProjectState / renderGlobalGantt, js/ui_projects.js:210-392, 1780, 3511).
 * Mantém do legado:
 *   - INTERNO exige unidade de negócio e centro de custo; EXTERNO exige cliente e encomenda (NE) do cliente;
 *   - estados PREPARACAO → ACTIVO → ENCERRADO / CANCELADO (EM_CURSO lido como ACTIVO);
 *   - projectos encerrados ou cancelados não aceitam imputações (ProjectAPI.postLedgerEntry).
 * Correcções:
 *   - código único por empresa, verificado (o legado aceitava códigos repetidos) e, sem código, numerado
 *     PRJ-AAAA-NNNN por ServicoNumeracao (o legado usava «PRJ-» + Date.now());
 *   - encerrar/cancelar e reabrir exigem `proj_estado` também pela edição da ficha (o modal «Editar Projecto / Estado»
 *     deixava `proj_gerir` encerrar, contornando a permissão sensível);
 *   - a encomenda tem de ser uma NE não anulada do mesmo cliente (o legado só o garantia no ecrã);
 *   - a regra «encerrado não aceita imputações» vale em todas as imputações (horas, equipamentos, autos, requisições,
 *     facturação); no legado estava só em postLedgerEntry, que nenhum módulo chamava.
 */
final class ServicoProjetos
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
    ) {}

    /** @param  array<string, mixed>  $d */
    public function guardar(array $d, ?Projeto $p = null): Projeto
    {
        $empresa = $this->contexto->obrigatorio();
        $tipo = $d['tipo'] ?? $p?->tipo ?? 'INTERNO';
        if (! in_array($tipo, Projeto::TIPOS, true)) {
            throw new ErroNegocio('Tipo de projecto inválido (INTERNO ou EXTERNO).', 'TIPO_INVALIDO', 422);
        }
        $nome = trim((string) ($d['nome'] ?? $p?->nome ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique a designação do projecto.', 'NOME_OBRIGATORIO', 422);
        }
        $estado = $d['estado'] ?? $p?->estado ?? 'ACTIVO';
        if ($estado === 'EM_CURSO') {
            $estado = 'ACTIVO';
        }
        if (! in_array($estado, Projeto::ESTADOS, true)) {
            throw new ErroNegocio('Estado inválido.', 'ESTADO_INVALIDO', 422);
        }
        $campos = ['unidade_negocio_id' => null, 'centro_custo_id' => null, 'cliente_id' => null, 'encomenda_venda_id' => null];
        if ($tipo === 'INTERNO') {
            $campos['unidade_negocio_id'] = $d['unidade_negocio_id'] ?? $p?->unidade_negocio_id;
            $campos['centro_custo_id'] = $d['centro_custo_id'] ?? $p?->centro_custo_id;
            if (! $campos['unidade_negocio_id'] || ! $campos['centro_custo_id']) {
                throw new ErroNegocio('Projectos internos exigem unidade de negócio e centro de custo.', 'DIMENSOES_OBRIGATORIAS', 422);
            }
            UnidadeNegocio::query()->findOr($campos['unidade_negocio_id'], fn () => throw new ErroNegocio('Unidade de negócio inexistente.', 'UNIDADE_NEGOCIO_INEXISTENTE', 422));
            CentroCusto::query()->findOr($campos['centro_custo_id'], fn () => throw new ErroNegocio('Centro de custo inexistente.', 'CENTRO_CUSTO_INEXISTENTE', 422));
        } else {
            $campos['cliente_id'] = $d['cliente_id'] ?? $p?->cliente_id;
            $campos['encomenda_venda_id'] = $d['encomenda_venda_id'] ?? $p?->encomenda_venda_id;
            if (! $campos['cliente_id'] || ! $campos['encomenda_venda_id']) {
                throw new ErroNegocio('Projectos externos exigem cliente e encomenda associada.', 'CLIENTE_ENCOMENDA_OBRIGATORIOS', 422);
            }
            $cliente = Terceiro::query()->find($campos['cliente_id']);
            if (! $cliente?->eCliente()) {
                throw new ErroNegocio('O terceiro indicado não é cliente.', 'CLIENTE_INVALIDO', 422);
            }
            $enc = Venda::query()->find($campos['encomenda_venda_id']);
            if (! $enc || $enc->tipo_documento !== 'NE' || $enc->estado === 'ANULADO') {
                throw new ErroNegocio('A encomenda tem de ser uma nota de encomenda (NE) não anulada.', 'ENCOMENDA_INVALIDA', 422);
            }
            if ((int) $enc->cliente_id !== (int) $cliente->id) {
                throw new ErroNegocio("A encomenda {$enc->numero_documento} é de outro cliente.", 'ENCOMENDA_OUTRO_CLIENTE', 422);
            }
        }

        return DB::transaction(function () use ($d, $p, $empresa, $tipo, $nome, $estado, $campos) {
            $codigo = trim((string) ($d['codigo'] ?? $p?->codigo ?? ''));
            if ($codigo === '') {
                $ano = now()->year;
                $n = $this->numeracao->proximo($empresa, "projetos:{$ano}", fn () => (int) DB::table('projetos')->where('empresa_id', $empresa)
                    ->whereRaw('codigo ~ ?', ["^PRJ-{$ano}-[0-9]+$"])->selectRaw('MAX(CAST(SUBSTRING(codigo FROM 10) AS INTEGER)) AS m')->value('m'));
                $codigo = sprintf('PRJ-%d-%04d', $ano, $n);
            }
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["projeto_codigo:{$empresa}:".mb_strtoupper($codigo)]);
            if (Projeto::query()->whereRaw('upper(codigo) = upper(?)', [$codigo])->when($p, fn ($q) => $q->whereKeyNot($p->id))->exists()) {
                throw new ErroNegocio("Já existe um projecto com o código {$codigo}.", 'CODIGO_DUPLICADO', 422);
            }
            $dados = ['codigo' => $codigo, 'nome' => $nome, 'tipo' => $tipo, 'estado' => $estado] + $campos;
            if ($p) {
                $antes = $p->estado;
                $p->update($dados);
                if ($antes !== $estado) {
                    $this->registar($p->id, 'Mudar estado', "{$antes} → {$estado}");
                }

                return $p->refresh();
            }
            $novo = Projeto::create($dados);
            $this->registar($novo->id, 'Criar projecto', "{$codigo} {$nome}");

            return $novo;
        });
    }

    /** Mudança de estado (changeProjectState, ui_projects.js:1780). */
    public function mudarEstado(Projeto $p, string $estado): Projeto
    {
        if ($estado === 'EM_CURSO') {
            $estado = 'ACTIVO';
        }
        if (! in_array($estado, Projeto::ESTADOS, true)) {
            throw new ErroNegocio('Estado inválido.', 'ESTADO_INVALIDO', 422);
        }
        if ($p->estado === $estado) {
            throw new ErroNegocio("O projecto já está {$estado}.", 'ESTADO_IGUAL', 422);
        }

        return DB::transaction(function () use ($p, $estado) {
            $antes = $p->estado;
            $p->update(['estado' => $estado]);
            $this->registar($p->id, 'Mudar estado', "{$antes} → {$estado}");

            return $p->refresh();
        });
    }

    /** A mudança pedida envolve um estado fechado (a controlar com `proj_estado`)? */
    public static function mudancaSensivel(?Projeto $p, ?string $estado): bool
    {
        if ($estado === null) {
            return false;
        }
        $antes = $p?->estado;

        return $antes !== $estado && (in_array($estado, Projeto::FECHADOS, true) || in_array($antes, Projeto::FECHADOS, true));
    }

    /** Recusa imputações num projecto encerrado ou cancelado. */
    public function exigirAberto(Projeto $p): void
    {
        if (in_array($p->estado, Projeto::FECHADOS, true)) {
            throw new ErroNegocio("O projecto {$p->codigo} está encerrado ou cancelado e não aceita imputações.", 'PROJETO_FECHADO', 422);
        }
    }

    /** Projectos activos para os selectores dos outros módulos (ProjectAPI.getActiveProjects). */
    public function ativos()
    {
        return Projeto::query()->whereIn('estado', ['ACTIVO', 'EM_CURSO'])->orderBy('codigo')->get(['id', 'codigo', 'nome', 'tipo', 'estado']);
    }

    /**
     * Gantt global da carteira (renderGlobalGantt, ui_projects.js:3511): projectos activos por tipo (EXTERNO, INTERNO),
     * com a janela das suas tarefas e as tarefas principais.
     */
    public function ganttGlobal(): array
    {
        $projetos = Projeto::query()->whereIn('estado', ['ACTIVO', 'EM_CURSO'])->orderBy('codigo')->get();
        $tarefas = TarefaProjeto::query()->whereIn('projeto_id', $projetos->pluck('id'))->orderBy('id')->get()->groupBy('projeto_id');
        $seg = [];
        foreach (['EXTERNO', 'INTERNO'] as $tipo) {
            $lista = [];
            foreach ($projetos->filter(fn ($p) => ($p->tipo ?: 'EXTERNO') === $tipo) as $p) {
                $ts = $tarefas[$p->id] ?? collect();
                if ($ts->isEmpty()) {
                    continue;
                }
                $lista[] = ['id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome,
                    'inicio' => $ts->pluck('data_inicio')->filter()->min()?->toDateString(), 'fim' => $ts->pluck('data_fim')->filter()->max()?->toDateString(),
                    'tarefas' => $ts->whereNull('tarefa_pai_id')->values()->map(fn ($t) => ['id' => $t->id, 'codigo' => $t->codigo, 'nome' => $t->nome,
                        'inicio' => $t->data_inicio?->toDateString(), 'fim' => $t->data_fim?->toDateString()])->all()];
            }
            if ($lista) {
                $seg[] = ['tipo' => $tipo, 'projetos' => $lista];
            }
        }
        $todas = $tarefas->flatten(1);

        return ['inicio' => $todas->pluck('data_inicio')->filter()->min()?->toDateString(), 'fim' => $todas->pluck('data_fim')->filter()->max()?->toDateString(), 'segmentos' => $seg];
    }

    /** Registo de actividade do projecto (logMovement 'Projectos', agora na tabela do projecto). */
    public function registar(?int $projetoId, string $acao, ?string $detalhes = null): void
    {
        LogAtividadeProjeto::create(['projeto_id' => $projetoId, 'ocorrido_em' => now(), 'nome_utilizador' => Auth::user()?->nome_utilizador,
            'acao' => mb_substr($acao, 0, 255), 'detalhes' => $detalhes]);
    }

    // ───────────── Configuração (configuracoes_projetos) ─────────────

    /** Chaves da configuração da empresa (projeto_id NULL). */
    public const CONFIGURACAO = ['produto_subempreitada_id', 'produto_faturacao_id'];

    public function configuracao(?int $projetoId = null): array
    {
        $linhas = ConfiguracaoProjeto::query()->when($projetoId, fn ($q) => $q->where('projeto_id', $projetoId), fn ($q) => $q->whereNull('projeto_id'))->get();
        $saida = $projetoId ? [] : array_fill_keys(self::CONFIGURACAO, null);
        foreach ($linhas as $l) {
            $v = json_decode((string) $l->valor, true);
            $saida[$l->chave] = json_last_error() === JSON_ERROR_NONE ? $v : $l->valor;
        }

        return $saida;
    }

    public function definirConfiguracao(?int $projetoId, string $chave, mixed $valor): void
    {
        DB::transaction(function () use ($projetoId, $chave, $valor) {
            $l = ConfiguracaoProjeto::query()->where('chave', $chave)->when($projetoId, fn ($q) => $q->where('projeto_id', $projetoId), fn ($q) => $q->whereNull('projeto_id'))
                ->lockForUpdate()->first();
            $json = $valor === null ? null : json_encode($valor, JSON_UNESCAPED_UNICODE);
            $l ? $l->update(['valor' => $json]) : ConfiguracaoProjeto::create(['projeto_id' => $projetoId, 'chave' => $chave, 'valor' => $json]);
        });
    }

    /**
     * O código aceite pela restrição CHECK da coluna? Enquanto o esquema não tiver os códigos em falta (BLOQUEADA nas
     * tarefas, PROCESSAMENTO_SALARIAL no razão), grava-se o código a NULL e o texto na coluna *_original.
     */
    public static function codigoAceite(string $tabela, string $coluna, string $valor): bool
    {
        static $cache = [];
        $cache["{$tabela}.{$coluna}"] ??= (string) (DB::selectOne('SELECT pg_get_constraintdef(c.oid) AS d FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
            WHERE t.relname = ? AND c.conname = ?', [$tabela, "ck_{$tabela}_{$coluna}"])?->d ?? '');
        $def = $cache["{$tabela}.{$coluna}"];

        return $def === '' || str_contains($def, "'{$valor}'");
    }
}
