<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\ItemOrdemManutencao;
use App\Models\LinhaRequisicaoManutencao;
use App\Models\OrdemManutencaoAtivo;
use App\Models\Produto;
use App\Models\RequisicaoManutencao;
use App\Services\Logistica\ServicoStock;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Requisições ao armazém das ordens de manutenção (Fase F, Versão Actual — js/activos_manutencao.js:518-624; UI
 * activos_manutencao_ui.js:691-803). Regras do legado mantidas:
 *   - entrega total ou parcial por linha; não se entrega mais do que falta nem mais do que o armazém tem;
 *   - a saída de stock é valorizada ao custo médio; o custo soma à linha, à necessidade e ao custo de materiais da ordem;
 *   - a necessidade fica RECEBIDA quando o armazém entregou tudo o que lhe cabia e não há parte em compra;
 *   - requisição SATISFEITA quando todas as linhas estão entregues (senão PARCIAL); a ordem AGUARDA_MATERIAL passa a
 *     EM_CURSO quando a requisição fica completa (com data de início);
 *   - cancelamento com motivo, proibido se já houve entregas; as necessidades voltam a «por pedir».
 * Correcções: a saída passa pelo ServicoStock (a única via de alteração do stock — custo médio, stock por armazém,
 * movimento com documento de origem REQUISICAO_MANUTENCAO, exercício aberto e armazém sem inventário em curso); o
 * legado escrevia à mão em products/warehouse_stock/inventory_movements. Não se entrega material a uma ordem fechada.
 * Uma necessidade que também foi a compras, ao cancelar a requisição, fica «em compra» (o legado repunha-a «por pedir»
 * e um novo accionamento duplicava o pedido de compra).
 */
final class ServicoRequisicoesManutencao
{
    public function __construct(
        private readonly ServicoStock $stock,
        private readonly ServicoOrdensManutencao $ordens,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    public function listar(array $f): LengthAwarePaginator
    {
        $q = RequisicaoManutencao::query()->with(['ordemManutencao:id,numero,estado', 'ativoImobilizado:id,codigo,descricao', 'armazem:id,nome'])->withCount('linhas')
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($f['ordem_manutencao_id'] ?? null, fn ($q, $v) => $q->where('ordem_manutencao_id', $v))
            ->orderByDesc('data')->orderByDesc('id');

        return $q->paginate((int) ($f['por_pagina'] ?? 100), ['*'], 'pagina', (int) ($f['pagina'] ?? 1));
    }

    /** Requisição com as linhas e o stock disponível no armazém indicado (formularioEntrega, UI :747-773). */
    public function detalhe(RequisicaoManutencao $req, ?int $armazemId = null): array
    {
        $armazemId ??= $req->armazem_id;
        $linhas = LinhaRequisicaoManutencao::query()->with('produto:id,codigo,nome,quantidade_stock,movimenta_stock')->where('requisicao_manutencao_id', $req->id)->orderBy('id')->get();

        return $req->load(['ordemManutencao:id,numero,estado', 'ativoImobilizado:id,codigo,descricao', 'armazem:id,nome'])->toArray() + [
            'armazem_consulta_id' => $armazemId,
            'linhas' => $linhas->map(fn (LinhaRequisicaoManutencao $l) => $l->toArray() + [
                'falta' => bcsub((string) $l->quantidade, (string) ($l->quantidade_entregue ?? '0'), 3),
                'stock' => $l->produto ? $this->ordens->stockDisponivel($l->produto, $armazemId) : '0.000',
            ])->all(),
        ];
    }

    /**
     * Entrega (satisfazerRequisicao :529-607).
     *
     * @param  array<int|string, mixed>  $entregas  id da linha => quantidade (linha omitida = entrega o que falta)
     * @return array{total: string, completa: bool}
     */
    public function entregar(RequisicaoManutencao $req, array $entregas, ?int $armazemId): array
    {
        return DB::transaction(function () use ($req, $entregas, $armazemId) {
            $req = RequisicaoManutencao::query()->lockForUpdate()->findOrFail($req->id);
            if ($req->estado === RequisicaoManutencao::SATISFEITA) {
                throw new ErroNegocio('A requisição já foi satisfeita.', 'REQUISICAO_FECHADA', 422);
            }
            if ($req->estado === RequisicaoManutencao::CANCELADA) {
                throw new ErroNegocio('A requisição está cancelada.', 'REQUISICAO_FECHADA', 422);
            }
            $armazemId = $armazemId ?: $req->armazem_id;
            if (! $armazemId || ! Armazem::query()->whereKey($armazemId)->exists()) {
                throw new ErroNegocio('Escolha o armazém de onde sai o material.', 'ARMAZEM_EM_FALTA', 422);
            }
            $ordem = OrdemManutencaoAtivo::query()->lockForUpdate()->find($req->ordem_manutencao_id);
            if ($ordem && ! in_array($ordem->estado, OrdemManutencaoAtivo::ABERTAS, true)) {
                throw new ErroNegocio("A ordem {$ordem->numero} já está fechada: cancele a requisição em vez de entregar.", 'ORDEM_FECHADA', 422);
            }
            $linhas = LinhaRequisicaoManutencao::query()->where('requisicao_manutencao_id', $req->id)->lockForUpdate()->orderBy('id')->get();
            $hoje = now()->toDateString();
            $total = '0.00';
            $entregues = 0;
            foreach ($linhas as $l) {
                if (in_array($l->estado, ['ENTREGUE', 'CANCELADA'], true)) {
                    continue;
                }
                $falta = bcsub((string) $l->quantidade, (string) ($l->quantidade_entregue ?? '0'), 3);
                $pedir = array_key_exists((string) $l->id, $entregas) || array_key_exists($l->id, $entregas)
                    ? number_format((float) ($entregas[(string) $l->id] ?? $entregas[$l->id]), 3, '.', '') : $falta;
                if (bccomp($pedir, '0', 3) <= 0) {
                    continue;
                }
                if (bccomp($pedir, $falta, 3) > 0) {
                    throw new ErroNegocio("Na linha «{$l->descricao}» só faltam ".$this->q($falta).'. Não se pode entregar '.$this->q($pedir).'.', 'QUANTIDADE_EXCEDE', 422);
                }
                $produto = Produto::query()->find($l->produto_id) ?? throw new ErroNegocio("Linha «{$l->descricao}»: artigo inexistente.", 'DADOS_INVALIDOS', 422);
                $disp = $this->ordens->stockDisponivel($produto, $armazemId);
                if (bccomp($pedir, $disp, 3) > 0) {
                    throw new ErroNegocio("O armazém só tem {$this->q($disp)} de «{$l->descricao}». Reduza a quantidade ou peça a compra da diferença.", 'STOCK_INSUFICIENTE', 422);
                }
                $mov = $this->stock->saida($produto->id, $armazemId, $pedir, null, $hoje,
                    trim("Requisição de manutenção {$req->numero} (ordem ".($ordem?->numero ?? '').')'), null, null, false,
                    ['documento_tipo' => 'REQUISICAO_MANUTENCAO', 'documento_id' => $req->id]);
                $valor = (string) $mov['movimento']->valor;
                $novaEntregue = bcadd((string) ($l->quantidade_entregue ?? '0'), $pedir, 3);
                $l->update(['quantidade_entregue' => $novaEntregue, 'custo' => bcadd(CalculadoraAmortizacoes::d($l->custo), $valor, 2),
                    'estado' => bccomp($novaEntregue, (string) $l->quantidade, 3) >= 0 ? 'ENTREGUE' : 'PARCIAL']);
                $total = bcadd($total, $valor, 2);
                $entregues++;

                // a necessidade só fica recebida quando o armazém entregou tudo o que lhe cabia e não há parte em compra (:576-589)
                if ($l->item_ordem_manutencao_id && ($it = ItemOrdemManutencao::query()->lockForUpdate()->find($l->item_ordem_manutencao_id))) {
                    $recebida = bcadd((string) ($it->quantidade_recebida ?? '0'), $pedir, 3);
                    $esperada = (string) ($it->quantidade_armazem ?? $it->quantidade);
                    $temCompra = bccomp((string) ($it->quantidade_compra ?? '0'), '0', 3) > 0;
                    $it->update(['quantidade_recebida' => $recebida, 'custo' => bcadd(CalculadoraAmortizacoes::d($it->custo), $valor, 2),
                        'estado' => bccomp($recebida, $esperada, 3) >= 0 && ! $temCompra ? 'RECEBIDO' : $it->estado]);
                }
            }
            if (! $entregues) {
                throw new ErroNegocio('Indique as quantidades a entregar.', 'SEM_QUANTIDADES', 422);
            }
            $completa = LinhaRequisicaoManutencao::query()->where('requisicao_manutencao_id', $req->id)->where('estado', '<>', 'CANCELADA')
                ->whereRaw('COALESCE(quantidade_entregue, 0) < quantidade')->doesntExist();
            $req->update(['estado' => $completa ? RequisicaoManutencao::SATISFEITA : RequisicaoManutencao::PARCIAL, 'armazem_id' => $armazemId,
                'satisfeita_em' => $completa ? now() : null]);

            // custo do material na ordem (:597-604)
            if ($ordem) {
                $alt = ['custo_materiais' => bcadd(CalculadoraAmortizacoes::d($ordem->custo_materiais), $total, 2)];
                if ($ordem->estado === OrdemManutencaoAtivo::AGUARDA_MATERIAL && $completa) {
                    $alt['estado'] = OrdemManutencaoAtivo::EM_CURSO;
                    $alt['data_inicio'] = $ordem->data_inicio ?? $hoje;
                }
                $ordem->update($alt);
            }
            $this->auditoria->registar('Activos', 'Satisfazer requisição de manutenção', "{$req->numero}: {$total} Kz", 'requisicoes_manutencao', $req->id);

            return ['total' => $total, 'completa' => $completa];
        });
    }

    /** Cancelamento (cancelarRequisicao :609-624). */
    public function cancelar(RequisicaoManutencao $req, string $motivo): RequisicaoManutencao
    {
        if (trim($motivo) === '') {
            throw new ErroNegocio('Indique o motivo do cancelamento.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($req, $motivo) {
            $req = RequisicaoManutencao::query()->lockForUpdate()->findOrFail($req->id);
            if ($req->estado === RequisicaoManutencao::SATISFEITA) {
                throw new ErroNegocio('Uma requisição satisfeita não se cancela — o material já saiu do armazém.', 'REQUISICAO_FECHADA', 422);
            }
            if ($req->estado === RequisicaoManutencao::CANCELADA) {
                throw new ErroNegocio('A requisição já está cancelada.', 'REQUISICAO_FECHADA', 422);
            }
            $linhas = LinhaRequisicaoManutencao::query()->where('requisicao_manutencao_id', $req->id)->lockForUpdate()->get();
            if ($linhas->contains(fn ($l) => bccomp((string) ($l->quantidade_entregue ?? '0'), '0', 3) > 0)) {
                throw new ErroNegocio('A requisição já teve entregas parciais e não pode ser cancelada.', 'REQUISICAO_COM_ENTREGAS', 422);
            }
            $req->update(['estado' => RequisicaoManutencao::CANCELADA, 'motivo_cancelamento' => mb_substr(trim($motivo), 0, 1000), 'cancelado_em' => now(),
                'cancelado_por' => Auth::user()?->nome_utilizador]);
            foreach ($linhas as $l) {
                $l->update(['estado' => 'CANCELADA']);
                $it = $l->item_ordem_manutencao_id ? ItemOrdemManutencao::query()->lockForUpdate()->find($l->item_ordem_manutencao_id) : null;
                if (! $it) {
                    continue;
                }
                $temCompra = bccomp((string) ($it->quantidade_compra ?? '0'), '0', 3) > 0 && $it->pedido_compra_id;
                $it->update($temCompra
                    ? ['estado' => 'EM_COMPRA', 'destino' => 'COMPRA', 'requisicao_manutencao_id' => null, 'quantidade_armazem' => 0]
                    : ['estado' => 'POR_PEDIR', 'destino' => null, 'requisicao_manutencao_id' => null, 'quantidade_armazem' => 0, 'quantidade_compra' => 0]);
            }

            return $req->refresh();
        });
    }

    private function q(string $v): string
    {
        return rtrim(rtrim($v, '0'), '.') ?: '0';
    }
}
