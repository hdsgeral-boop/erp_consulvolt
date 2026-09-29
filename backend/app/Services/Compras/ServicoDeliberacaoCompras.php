<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigDeliberacaoCompra;
use App\Models\ItemCompra;
use App\Models\PedidoCompra;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deliberação dos pedidos de compra por escalões de valor (js/compras_deliberacao.js do legado).
 *   - escalões cumulativos: um pedido de valor V exige os níveis 1..k, sendo k o primeiro com V ≤ limite;
 *   - nível 1: tarefa compras_ped_aprovar; níveis 2..4: compras_ped_aprovar_n{i}
 *     (a aprovação pelo responsável da unidade orgânica do requisitante chega com o módulo RH/estrutura);
 *   - ninguém aprova os próprios pedidos; a recusa exige nota (≥ 3 caracteres) e rejeita o pedido;
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
            $etapas[] = $this->etapa($i, $k === 0 ? 'PENDENTE' : 'AGUARDA');
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
            if ($pedido->criado_por && $pedido->criado_por === $utilizador) {
                throw new ErroNegocio('Ninguém aprova os próprios pedidos.', 'AUTO_APROVACAO', 403);
            }
            if (($etapa['tipo'] ?? 'TAREFA') !== 'LEGADO' && ! Gate::allows($etapa['tarefa'] ?? 'compras_ped_aprovar')) {
                throw new ErroNegocio("Não tem permissão para aprovar a etapa \"{$etapa['nome']}\".", 'SEM_PERMISSAO_ETAPA', 403);
            }
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
                $d['etapas'][] = $this->etapa($i, $k === 0 ? 'PENDENTE' : 'AGUARDA') + ['revisao' => true];
            }
            $d['revisoes'][] = ['valor_proposta' => (float) $valorProposta, 'cotacao_compra_id' => $cotacaoId, 'em' => now()->toIso8601String(),
                'por' => Auth::user()?->nome_utilizador];
            $pedido->update(['deliberacao' => $d, 'estado' => 'PENDENTE']);

            return true;
        });
    }

    private function etapa(int $i, string $estado): array
    {
        $n = $this->escaloes()[$i];

        return ['ordem' => $i + 1, 'nome' => $n['nome'], 'tipo' => 'TAREFA', 'tarefa' => $i === 0 ? 'compras_ped_aprovar' : 'compras_ped_aprovar_n'.($i + 1),
            'estado' => $estado];
    }
}
