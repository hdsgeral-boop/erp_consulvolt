<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ConfigDeliberacaoCompra;
use App\Models\ItemCompra;
use App\Models\PedidoCompra;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deliberação dos pedidos de compra por escalões de valor (js/compras_deliberacao.js do legado).
 *   - escalões cumulativos: um pedido de valor V exige os níveis 1..k, sendo k o primeiro com V ≤ limite;
 *   - nível 1 (aprovadorPrimeiroNivel, compras_deliberacao.js:99-127): o responsável da unidade orgânica do colaborador
 *     requisitante (sobe na estrutura enquanto o responsável for o próprio requisitante) — etapa RESPONSAVEL, só ele
 *     decide; sem colaborador/unidade, o utilizador responsável da unidade ligada ao CC/UN do pedido — etapa UTILIZADOR;
 *     sem nenhum dos dois (ou o responsável sem utilizador activo na empresa), a tarefa compras_ped_aprovar com a nota
 *     do porquê (nota_aprovador); níveis 2..4: tarefa compras_ped_aprovar_n{i};
 *   - ninguém aprova os próprios pedidos (criador ou colaborador requisitante); a recusa exige nota (≥ 3 caracteres) e
 *     rejeita o pedido;
 *   - revisão na adjudicação: se a proposta exigir mais níveis do que os aprovados, abrem-se as etapas em falta.
 * O formato de `deliberacao` (etapas, revisoes, valor…) é o do legado, para os pedidos migrados continuarem válidos.
 */
final class ServicoDeliberacaoCompras
{
    public const ESCALOES_PADRAO = [
        ['nome' => 'Responsável da unidade', 'limite' => 500000],
        ['nome' => 'Direcção', 'limite' => 5000000],
        ['nome' => 'Administração', 'limite' => null],
    ];

    private const NOME_TAREFA_N1 = 'Utilizadores com a tarefa «Aprovar e rejeitar pedidos»';

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @return list<array{nome: string, limite: ?float}> */
    public function escaloes(): array
    {
        return ConfigDeliberacaoCompra::query()->value('niveis') ?: self::ESCALOES_PADRAO;
    }

    public function definirEscaloes(array $niveis): array
    {
        $niveis = array_values($niveis);
        if (count($niveis) < 1 || count($niveis) > 4) {
            throw new ErroNegocio('Defina entre 1 e 4 níveis de aprovação.', 'ESCALOES_INVALIDOS', 422);
        }
        $anterior = 0.0;
        foreach ($niveis as $i => &$n) {
            $n['nome'] = trim((string) ($n['nome'] ?? ''));
            if ($n['nome'] === '') {
                throw new ErroNegocio('Todos os níveis têm de ter nome.', 'ESCALOES_INVALIDOS', 422);
            }
            if ($i === count($niveis) - 1) {
                $n['limite'] = null;   // o último nível não tem limite

                continue;
            }
            $limite = (float) ($n['limite'] ?? 0);
            if ($limite <= $anterior) {
                throw new ErroNegocio('Os limites têm de ser positivos e crescentes.', 'ESCALOES_INVALIDOS', 422);
            }
            $n['limite'] = $anterior = $limite;
        }
        unset($n);
        $c = ConfigDeliberacaoCompra::query()->first();
        $dados = ['niveis' => $niveis, 'atualizado_por' => Auth::user()?->nome_utilizador];
        $c ? $c->update($dados) : ConfigDeliberacaoCompra::create($dados);

        return $niveis;
    }

    /** @return list<int> índices (0-based) dos níveis exigidos para o valor */
    public function niveisExigidos(float $valor): array
    {
        $exigidos = [];
        foreach ($this->escaloes() as $i => $n) {
            $exigidos[] = $i;
            if ($n['limite'] === null || $valor <= (float) $n['limite']) {
                break;
            }
        }

        return $exigidos;
    }

    /** Valor estimado: Σ quantidade × preço da linha (ou custo médio do produto). O legado caía no preço de VENDA. */
    public function valorEstimado(PedidoCompra $pedido): array
    {
        $valor = '0.00';
        $semPreco = 0;
        foreach (ItemCompra::query()->where('pedido_compra_id', $pedido->id)->with('produto')->get() as $l) {
            $preco = (float) $l->preco_unitario > 0 ? (string) $l->preco_unitario : (string) ($l->produto?->custo_medio ?? '0');
            if ((float) $preco <= 0) {
                $semPreco++;
            }
            $valor = bcadd($valor, bcmul((string) $l->quantidade, $preco, 4), 2);
        }

        return ['valor' => $valor, 'sem_preco' => $semPreco];
    }

    public function iniciar(PedidoCompra $pedido): void
    {
        $estimado = $this->valorEstimado($pedido);
        $etapas = [];
        foreach ($this->niveisExigidos((float) $estimado['valor']) as $k => $i) {
            $etapas[] = $this->etapa($i, $k === 0 ? 'PENDENTE' : 'AGUARDA', $pedido);
        }
        $pedido->update(['deliberacao' => ['valor' => (float) $estimado['valor'], 'sem_preco' => $estimado['sem_preco'], 'etapas' => $etapas,
            'revisoes' => [], 'iniciada_em' => now()->toIso8601String()], 'estado' => 'PENDENTE']);
    }

    /** Pedidos migrados sem deliberação: APROVADO/ADJUDICADO ficam com uma etapa LEGADO aprovada; PENDENTE inicia-se. */
    public function garantir(PedidoCompra $pedido): void
    {
        if (! empty($pedido->deliberacao['etapas'])) {
            return;
        }
        if (in_array($pedido->estado, ['APROVADO', 'ADJUDICADO'], true)) {
            $pedido->update(['deliberacao' => ['valor' => 0, 'etapas' => [['ordem' => 1, 'nome' => $this->escaloes()[0]['nome'], 'tipo' => 'LEGADO', 'estado' => 'APROVADO',
                'por' => '(aprovado antes da deliberação por valor)']], 'revisoes' => []]]);
        } elseif ($pedido->estado === 'PENDENTE') {
            $this->iniciar($pedido);
        }
    }

    public function decidir(PedidoCompra $pedido, bool $aprovar, ?string $nota): PedidoCompra
    {
        return DB::transaction(function () use ($pedido, $aprovar, $nota) {
            $pedido = PedidoCompra::query()->lockForUpdate()->findOrFail($pedido->id);
            $this->garantir($pedido);
            if ($pedido->estado !== 'PENDENTE') {
                throw new ErroNegocio("O pedido está {$pedido->estado}: não há etapa de aprovação pendente.", 'SEM_ETAPA_PENDENTE', 422);
            }
            $d = $pedido->deliberacao;
            $i = collect($d['etapas'])->search(fn ($e) => $e['estado'] === 'PENDENTE');
            if ($i === false) {
                throw new ErroNegocio('Não há etapa de aprovação pendente.', 'SEM_ETAPA_PENDENTE', 422);
            }
            $etapa = $d['etapas'][$i];
            $utilizador = Auth::user()?->nome_utilizador;
            $this->exigirPodeDecidir($pedido);
            if (! $aprovar && mb_strlen(trim((string) $nota)) < 3) {
                throw new ErroNegocio('Indique o motivo da recusa.', 'RECUSA_SEM_NOTA', 422);
            }
            $d['etapas'][$i] = array_merge($etapa, ['estado' => $aprovar ? 'APROVADO' : 'RECUSADO', 'por' => $utilizador,
                'em' => now()->toIso8601String(), 'nota' => $nota ? mb_substr($nota, 0, 1000) : null]);

            if (! $aprovar) {
                $pedido->update(['deliberacao' => $d, 'estado' => 'REJEITADO']);

                return $pedido;
            }
            $seguinte = collect($d['etapas'])->search(fn ($e) => $e['estado'] === 'AGUARDA');
            if ($seguinte !== false) {
                $d['etapas'][$seguinte]['estado'] = 'PENDENTE';
                $pedido->update(['deliberacao' => $d]);
            } else {
                $d['valor_aprovado'] = max((float) ($d['valor'] ?? 0), ...array_map(fn ($r) => (float) $r['valor_proposta'], $d['revisoes'] ?? []), ...[0]);
                $d['aprovado_em'] = now()->toIso8601String();
                $pedido->update(['deliberacao' => $d, 'estado' => 'APROVADO']);
            }

            return $pedido;
        });
    }

    /**
     * Antes de adjudicar: se o valor da proposta exigir níveis ainda não aprovados, abre-os (revisão),
     * repõe o pedido em PENDENTE e devolve true (a adjudicação tem de esperar). Grava fora da adjudicação.
     */
    public function exigeRevisao(PedidoCompra $pedido, string $valorProposta, int $cotacaoId): bool
    {
        return DB::transaction(function () use ($pedido, $valorProposta, $cotacaoId) {
            $pedido = PedidoCompra::query()->lockForUpdate()->findOrFail($pedido->id);
            $this->garantir($pedido);
            $d = $pedido->deliberacao;
            $aprovados = collect($d['etapas'])->where('estado', 'APROVADO')->count();
            $exigidos = $this->niveisExigidos((float) $valorProposta);
            if (count($exigidos) <= $aprovados) {
                return false;
            }
            foreach (array_slice($exigidos, $aprovados) as $k => $i) {
                $d['etapas'][] = $this->etapa($i, $k === 0 ? 'PENDENTE' : 'AGUARDA', $pedido) + ['revisao' => true];
            }
            $d['revisoes'][] = ['valor_proposta' => (float) $valorProposta, 'cotacao_compra_id' => $cotacaoId, 'em' => now()->toIso8601String(),
                'por' => Auth::user()?->nome_utilizador];
            $pedido->update(['deliberacao' => $d, 'estado' => 'PENDENTE']);

            return true;
        });
    }

    /**
     * Pode o utilizador autenticado decidir a etapa pendente? (podeDecidir, compras_deliberacao.js:139-149)
     * $meuColaborador: o colaborador ligado ao utilizador na empresa activa (false = lê-o aqui; a lista passa-o já lido).
     *
     * @return array{pode: bool, motivo: ?string, codigo: ?string}
     */
    public function podeDecidir(PedidoCompra $pedido, int|false|null $meuColaborador = false): array
    {
        $nao = fn (string $motivo, string $codigo = 'SEM_PERMISSAO_ETAPA') => ['pode' => false, 'motivo' => $motivo, 'codigo' => $codigo];
        if ($pedido->estado !== 'PENDENTE') {
            return $nao('O pedido não está por aprovar.', 'SEM_ETAPA_PENDENTE');
        }
        $utilizador = Auth::user()?->nome_utilizador;
        $meuColaborador = $meuColaborador === false ? $this->meuColaborador() : $meuColaborador;
        if (($pedido->criado_por && $pedido->criado_por === $utilizador)
            || ($meuColaborador !== null && $pedido->colaborador_requerente_id !== null && (int) $pedido->colaborador_requerente_id === $meuColaborador)) {
            return $nao('Não pode aprovar os seus próprios pedidos.', 'AUTO_APROVACAO');
        }
        $etapa = collect($pedido->deliberacao['etapas'] ?? [])->firstWhere('estado', 'PENDENTE');
        if (! $etapa) {
            // pedido migrado sem deliberação: inicia-se ao decidir (nível 1); aqui basta uma das tarefas de aprovação
            return Gate::any(['compras_ped_aprovar', 'compras_ped_aprovar_n2', 'compras_ped_aprovar_n3', 'compras_ped_aprovar_n4'])
                ? ['pode' => true, 'motivo' => null, 'codigo' => null] : $nao('Não tem permissão para aprovar pedidos.');
        }
        $tipo = $etapa['tipo'] ?? 'TAREFA';
        $ok = match ($tipo) {
            'RESPONSAVEL' => $meuColaborador !== null && (int) ($etapa['employee_id'] ?? 0) === $meuColaborador,
            'UTILIZADOR' => $utilizador !== null && ($etapa['utilizador'] ?? null) === $utilizador,
            'LEGADO' => true,
            default => Gate::allows($etapa['tarefa'] ?? 'compras_ped_aprovar'),
        };
        if ($ok) {
            return ['pode' => true, 'motivo' => null, 'codigo' => null];
        }

        return $nao(match ($tipo) {
            'RESPONSAVEL' => 'Aguarda '.($etapa['nome_aprovador'] ?? 'o responsável da unidade').'.',
            'UTILIZADOR' => 'Aguarda '.($etapa['utilizador'] ?? 'o responsável').'.',
            default => "Aguarda o nível «{$etapa['nome']}».",
        });
    }

    public function exigirPodeDecidir(PedidoCompra $pedido): void
    {
        $r = $this->podeDecidir($pedido);
        if (! $r['pode']) {
            throw new ErroNegocio($r['codigo'] === 'AUTO_APROVACAO' ? 'Ninguém aprova os próprios pedidos.' : (string) $r['motivo'], (string) $r['codigo'],
                $r['codigo'] === 'SEM_ETAPA_PENDENTE' ? 422 : 403);
        }
    }

    /** Colaborador ligado ao utilizador autenticado na empresa activa (utilizador_empresa.colaborador_id; meuColaboradorId do legado). */
    public function meuColaborador(): ?int
    {
        $id = Auth::id() ? DB::table('utilizador_empresa')->where('utilizador_id', Auth::id())->where('empresa_id', $this->contexto->obrigatorio())->value('colaborador_id') : null;

        return $id === null ? null : (int) $id;
    }

    /**
     * Aprovador do 1.º nível (aprovadorPrimeiroNivel, compras_deliberacao.js:99-127). Campos da etapa no formato do legado.
     *
     * @return array<string, mixed>
     */
    public function aprovadorPrimeiroNivel(PedidoCompra $pedido): array
    {
        $empresa = $this->contexto->obrigatorio();
        $fallback = fn (string $nota) => ['tipo' => 'TAREFA', 'tarefa' => 'compras_ped_aprovar', 'nome_aprovador' => self::NOME_TAREFA_N1, 'nota_aprovador' => $nota];
        $requisitante = $pedido->colaborador_requerente_id ? Colaborador::query()->find($pedido->colaborador_requerente_id, ['id', 'unidade_organica_id']) : null;
        if ($requisitante && $requisitante->unidade_organica_id) {
            $visto = [];
            for ($u = UnidadeOrganica::query()->find($requisitante->unidade_organica_id); $u && ! isset($visto[$u->id]) && count($visto) < 200;
                $u = $u->unidade_organica_pai_id ? UnidadeOrganica::query()->find($u->unidade_organica_pai_id) : null) {
                $visto[$u->id] = true;
                if ($u->colaborador_responsavel_id && (int) $u->colaborador_responsavel_id !== (int) $requisitante->id) {
                    $resp = Colaborador::query()->find($u->colaborador_responsavel_id, ['id', 'nome_completo']);
                    if ($resp && $this->temUtilizador($empresa, (int) $resp->id)) {
                        return ['tipo' => 'RESPONSAVEL', 'employee_id' => (int) $resp->id, 'nome_aprovador' => "{$resp->nome_completo} ({$u->nome})", 'nota_aprovador' => ''];
                    }

                    return $fallback("O responsável de «{$u->nome}»".($resp ? " ({$resp->nome_completo})" : '').' não tem utilizador no sistema.');
                }
            }

            return $fallback('A unidade do requisitante não tem responsável definido.');
        }
        // sem colaborador (ou sem unidade): utilizador responsável da unidade ligada ao centro de custo / UN do pedido
        if ($pedido->centro_custo_id || $pedido->unidade_negocio_id) {
            $user = $this->responsavelPara($pedido->centro_custo_id, $pedido->unidade_negocio_id);
            if ($user && $user !== $pedido->criado_por && $this->utilizadorActivoNaEmpresa($empresa, $user)) {
                return ['tipo' => 'UTILIZADOR', 'utilizador' => $user, 'nome_aprovador' => "{$user} (responsável do CC/UN)", 'nota_aprovador' => ''];
            }
        }

        return $fallback('Requisitante sem unidade na Estrutura Orgânica.');
    }

    /** EstruturaOrg.responsavelPara (estrutura_dados.js:231-235): utilizador responsável da unidade activa ligada ao CC, senão à UN. */
    private function responsavelPara(?int $centroCusto, ?int $unidadeNegocio): ?string
    {
        $base = fn () => UnidadeOrganica::query()->where(fn ($q) => $q->where('ativo', true)->orWhereNull('ativo'))
            ->whereNotNull('utilizador_responsavel')->where('utilizador_responsavel', '<>', '')->orderBy('id');

        return ($centroCusto ? $base()->where('centro_custo_id', $centroCusto)->value('utilizador_responsavel') : null)
            ?: ($unidadeNegocio ? $base()->where('unidade_negocio_id', $unidadeNegocio)->value('utilizador_responsavel') : null);
    }

    /** O colaborador tem utilizador activo ligado nesta empresa (utilizadoresDe do legado). */
    private function temUtilizador(int $empresa, int $colaborador): bool
    {
        return DB::table('utilizador_empresa')->join('utilizadores', 'utilizadores.id', '=', 'utilizador_empresa.utilizador_id')
            ->where('utilizador_empresa.empresa_id', $empresa)->where('utilizador_empresa.colaborador_id', $colaborador)
            ->where('utilizadores.ativo', true)->whereNull('utilizadores.eliminado_em')->exists();
    }

    private function utilizadorActivoNaEmpresa(int $empresa, string $nome): bool
    {
        return DB::table('utilizadores')->where('nome_utilizador', $nome)->where('ativo', true)->whereNull('eliminado_em')
            ->where(fn ($q) => $q->where('acesso_todas_empresas', true)
                ->orWhereExists(fn ($e) => $e->from('utilizador_empresa')->whereColumn('utilizador_empresa.utilizador_id', 'utilizadores.id')->where('utilizador_empresa.empresa_id', $empresa)))
            ->exists();
    }

    private function etapa(int $i, string $estado, PedidoCompra $pedido): array
    {
        $n = $this->escaloes()[$i];
        $base = ['ordem' => $i + 1, 'nome' => $n['nome'], 'estado' => $estado];

        return $i === 0
            ? $base + $this->aprovadorPrimeiroNivel($pedido)
            : $base + ['tipo' => 'TAREFA', 'tarefa' => 'compras_ped_aprovar_n'.($i + 1), 'nome_aprovador' => 'Utilizadores com a tarefa «Aprovar pedidos — nível '.($i + 1).'»'];
    }
}
