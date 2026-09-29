<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\ItemGuiaSaida;
use App\Models\RececaoCompra;
use App\Models\Terceiro;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Logistica\ServicoStock;
use App\Services\Sistema\ServicoCambios;
use App\Services\Vendas\CalculadoraDocumento;
use App\Services\Vendas\ServicoSeries;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Recepções de encomendas em dois passos, com segregação (compras_rec_registar ≠ armazem_validar):
 *   1. registar: quantidades por LINHA da encomenda (o legado casava por product_id e não limitava ao pendente);
 *   2. validar no armazém: valorização, entrada de stock com custo médio, e lançamento (diário GL):
 *        D compras (2.1) / C transitória do fornecedor (3.2.8)  ·  D mercadorias (2.6) / C compras (2.1)
 *      valorização por linha (moedas_compras.js:134-184): a parte já facturada e ainda não recebida entra ao valor
 *      da factura; o restante ao câmbio da DATA DA RECEPÇÃO (o legado usava a data do dia da validação).
 * Reverter a validação = estorno + saída de stock ao custo da entrada, só sem facturas da encomenda
 * (o legado deixava a 328 desequilibrada e o stock negativo).
 */
final class ServicoRececoesCompra
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoSeries $series,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoCambios $cambios,
        private readonly ServicoStock $stock,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoConfigCompras $config,
        private readonly ServicoProcessoCompras $processo,
    ) {}

    /** @param  array{numero_entrega: string, data: string, linhas: list<array{item_encomenda_id: int, quantidade: float|string}>}  $d */
    public function registar(EncomendaCompra $encomenda, array $d): RececaoCompra
    {
        $empresa = $this->contexto->obrigatorio();
        $data = substr($d['data'], 0, 10);
        $this->exercicios->exigirAberto($empresa, $data);

        return DB::transaction(function () use ($encomenda, $d, $data, $empresa) {
            $encomenda = EncomendaCompra::query()->lockForUpdate()->findOrFail($encomenda->id);
            if (in_array($encomenda->estado, ['ANULADA', 'RECEBIDO'], true)) {
                throw new ErroNegocio("A encomenda está {$encomenda->estado}: não admite recepções.", 'ENCOMENDA_NAO_RECEBIVEL', 422);
            }
            $itens = ItemCompra::query()->where('encomenda_compra_id', $encomenda->id)->lockForUpdate()->get()->keyBy('id');
            $linhas = collect($d['linhas'])->filter(fn ($l) => (float) $l['quantidade'] > 0)->values();
            if ($linhas->isEmpty()) {
                throw new ErroNegocio('Indique pelo menos uma quantidade recebida.', 'SEM_QUANTIDADES', 422);
            }
            foreach ($linhas as $n => $l) {
                $item = $itens[$l['item_encomenda_id']] ?? throw new ErroNegocio('Linha '.($n + 1).': não pertence à encomenda.', 'LINHA_INVALIDA', 422);
                $pendente = bcsub((string) $item->quantidade, (string) ($item->quantidade_recebida ?? 0), 3);
                if (bccomp((string) $l['quantidade'], $pendente, 3) > 0) {
                    throw new ErroNegocio("Linha {$item->descricao}: recebe {$l['quantidade']} mas só estão pendentes {$pendente}.", 'QUANTIDADE_SUPERIOR_PENDENTE', 422,
                        ['item_encomenda_id' => $item->id, 'pendente' => $pendente]);
                }
            }
            $reserva = $this->series->reservar($empresa, 'RCP', $data, false);
            $rececao = RececaoCompra::create([
                'numero_rececao' => $reserva['numero_documento'], 'encomenda_compra_id' => $encomenda->id, 'numero_entrega' => $d['numero_entrega'],
                'data' => $data, 'estado' => 'RECEBIDO', 'validado' => false, 'contabilizado' => false,
                'unidade_negocio_id' => $encomenda->unidade_negocio_id, 'centro_custo_id' => $encomenda->centro_custo_id, 'codigo_moeda' => $encomenda->codigo_moeda,
            ]);
            foreach ($linhas as $l) {
                $item = $itens[$l['item_encomenda_id']];
                ItemGuiaSaida::create(['rececao_compra_id' => $rececao->id, 'item_compra_id' => $item->id, 'produto_id' => $item->produto_id,
                    'quantidade' => (string) $l['quantidade'], 'projeto_id' => $item->projeto_id]);
                $item->update(['quantidade_recebida' => bcadd((string) ($item->quantidade_recebida ?? 0), (string) $l['quantidade'], 3)]);
            }
            $this->processo->recalcularEstadoEncomenda($encomenda);

            return $rececao;
        });
    }

    /** Anula uma recepção ainda não validada (repõe as quantidades pendentes da encomenda). */
    public function anular(RececaoCompra $rececao, string $motivo): RececaoCompra
    {
        return DB::transaction(function () use ($rececao, $motivo) {
            $rececao = RececaoCompra::query()->lockForUpdate()->findOrFail($rececao->id);
            if ($rececao->estado === 'ANULADO') {
                throw new ErroNegocio('A recepção já está anulada.', 'JA_ANULADO', 422);
            }
            if ($rececao->validado) {
                throw new ErroNegocio('A recepção está validada: reverta primeiro a validação.', 'RECECAO_VALIDADA', 422);
            }
            foreach ($rececao->itensGuiaSaida()->get() as $l) {
                if (! $l->item_compra_id) {
                    throw new ErroNegocio('Recepção do legado sem ligação às linhas da encomenda: anule-a manualmente.', 'RECECAO_LEGADO', 422);
                }
                $item = ItemCompra::query()->lockForUpdate()->findOrFail($l->item_compra_id);
                $item->update(['quantidade_recebida' => max(0, (float) bcsub((string) $item->quantidade_recebida, (string) $l->quantidade, 3))]);
            }
            $rececao->update(['estado' => 'ANULADO', 'anulado_em' => now(), 'motivo_anulacao' => $motivo]);
            $this->processo->recalcularEstadoEncomenda(EncomendaCompra::query()->findOrFail($rececao->encomenda_compra_id));

            return $rececao;
        });
    }

    public function validar(RececaoCompra $rececao, ?int $armazemId): RececaoCompra
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($rececao, $armazemId, $empresa) {
            $rececao = RececaoCompra::query()->lockForUpdate()->findOrFail($rececao->id);
            if ($rececao->estado !== 'RECEBIDO' || $rececao->validado) {
                throw new ErroNegocio('Só recepções registadas e ainda não validadas podem ser validadas.', 'RECECAO_ESTADO_INVALIDO', 422);
            }
            $data = $rececao->data->toDateString();
            $this->exercicios->exigirAberto($empresa, $data);
            $encomenda = EncomendaCompra::query()->findOrFail($rececao->encomenda_compra_id);
            $fornecedor = Terceiro::query()->withTrashed()->findOrFail($encomenda->fornecedor_id);
            $moeda = CalculadoraCompra::moeda($this->cambios, $empresa, $encomenda->codigo_moeda,
                $encomenda->taxa_cambio_manual ? (string) $encomenda->taxa_cambio : null, $data);

            $linhasContab = [];
            $total = '0.00';
            foreach ($rececao->itensGuiaSaida()->with('produto')->orderBy('id')->get() as $l) {
                $item = ItemCompra::query()->lockForUpdate()->findOrFail($l->item_compra_id);
                $produto = $l->produto;
                $q = (string) $l->quantidade;
                // parte já facturada e ainda não recebida: ao valor da factura
                $fatQ = (string) ($item->cambial_faturado_por_receber_qtd ?? 0);
                $fatV = (string) ($item->cambial_faturado_por_receber_kz ?? 0);
                $q1 = bccomp($q, $fatQ, 3) < 0 ? $q : $fatQ;
                $v1 = bccomp($q1, '0', 3) > 0 ? CalculadoraDocumento::arredondar(bcdiv(bcmul($fatV, $q1, 8), $fatQ, 8)) : '0.00';
                // restante: ao preço da encomenda (Kz) ou na moeda ao câmbio da data da recepção
                $q2 = bcsub($q, $q1, 3);
                $unitKz = $moeda['estrangeira'] && $item->preco_unitario_moeda !== null ? bcmul((string) $item->preco_unitario_moeda, $moeda['taxa'], 8) : (string) $item->preco_unitario;
                $v2 = CalculadoraDocumento::arredondar(bcmul($q2, $unitKz, 8));
                $valor = bcadd($v1, $v2, 2);
                $custo = bccomp($q, '0', 3) > 0 ? bcdiv($valor, $q, 6) : '0';
                $l->update(['cambial_q1' => $q1, 'cambial_v1' => $v1, 'cambial_q2' => $q2, 'cambial_v2' => $v2, 'valor_kz' => $valor, 'custo_unitario_kz' => $custo]);

                if (! $produto?->movimenta_stock) {
                    continue;   // serviços: sem stock nem lançamento (o custo entra pela factura)
                }
                $item->update([
                    'cambial_faturado_por_receber_qtd' => bcsub($fatQ, $q1, 3), 'cambial_faturado_por_receber_kz' => bcsub($fatV, $v1, 2),
                    'cambial_recebido_por_faturar_qtd' => bcadd((string) ($item->cambial_recebido_por_faturar_qtd ?? 0), $q2, 3),
                    'cambial_recebido_por_faturar_kz' => bcadd((string) ($item->cambial_recebido_por_faturar_kz ?? 0), $v2, 2),
                    'valor_recebido_kz' => bcadd((string) ($item->valor_recebido_kz ?? 0), $valor, 2),
                ]);
                if (! $armazemId) {
                    throw new ErroNegocio('Indique o armazém de entrada.', 'SEM_ARMAZEM', 422);
                }
                $this->stock->entrada($produto->id, $armazemId, $q, $custo, $data, "Recepção {$rececao->numero_rececao} (guia {$rececao->numero_entrega}, enc. {$encomenda->numero_encomenda})",
                    $fornecedor->id, $item->projeto_id);
                if (bccomp($valor, '0', 2) <= 0) {
                    continue;
                }
                $total = bcadd($total, $valor, 2);
                $contaCompra = $produto->conta_compra ?: $this->config->exigir('compras_mercadorias', "Produto {$produto->codigo} sem conta de compras.");
                $contaInv = $produto->conta_inventario ?: $this->config->exigir('inventario_mercadorias', "Produto {$produto->codigo} sem conta de inventário.");
                $transitoria = $fornecedor->conta_compra_transitoria ?: $this->config->exigir('transitoria_compras', 'O fornecedor não tem conta transitória de compras.');
                $comum = ['terceiro_id' => $fornecedor->id, 'unidade_negocio_id' => $rececao->unidade_negocio_id, 'centro_custo_id' => $rececao->centro_custo_id,
                    'projeto_id' => $item->projeto_id, 'descricao' => mb_substr("{$produto->codigo} {$produto->nome}", 0, 1000)];
                array_push($linhasContab,
                    ['codigo_conta' => $contaCompra, 'tipo_dc' => 'D', 'valor' => $valor] + $comum,
                    ['codigo_conta' => $transitoria, 'tipo_dc' => 'C', 'valor' => $valor] + $comum,
                    ['codigo_conta' => $contaInv, 'tipo_dc' => 'D', 'valor' => $valor] + $comum,
                    ['codigo_conta' => $contaCompra, 'tipo_dc' => 'C', 'valor' => $valor] + $comum);
            }

            $numeroLan = null;
            if ($linhasContab) {
                $numeroLan = $this->lancamentos->criar([
                    'diario_id' => $this->localizador->diario('GL', 'Logística')->id, 'data_documento' => $data, 'numero_documento' => $rececao->numero_rececao,
                    'referencia' => $rececao->numero_entrega, 'descricao' => mb_substr("Recepção {$rececao->numero_rececao} — {$fornecedor->nome}", 0, 1000),
                    'tipo_origem' => 'COMPRAS_RECECAO', 'linhas' => $linhasContab,
                ])->first()->numero_lan;
            }
            $rececao->update(['estado' => 'VALIDADO', 'validado' => true, 'armazem_id' => $armazemId, 'validado_em' => now(), 'validado_por' => Auth::user()?->nome_utilizador,
                'contabilizado' => (bool) $numeroLan, 'numero_lan_contabilizacao' => $numeroLan, 'valor_total_kz' => $total,
                'taxa_cambio' => $moeda['estrangeira'] ? $moeda['taxa'] : null, 'taxa_cambio_id' => $moeda['taxa_id'], 'taxa_cambio_manual' => $moeda['manual']]);

            return $rececao;
        });
    }

    public function reverterValidacao(RececaoCompra $rececao, string $motivo): RececaoCompra
    {
        return DB::transaction(function () use ($rececao, $motivo) {
            $rececao = RececaoCompra::query()->lockForUpdate()->findOrFail($rececao->id);
            if (! $rececao->validado) {
                throw new ErroNegocio('A recepção não está validada.', 'RECECAO_NAO_VALIDADA', 422);
            }
            if (FaturaCompra::query()->where('encomenda_compra_id', $rececao->encomenda_compra_id)
                ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))->exists()) {
                throw new ErroNegocio('A encomenda já tem facturas: anule-as primeiro (a conta transitória ficaria desequilibrada).', 'ENCOMENDA_COM_FATURAS', 422);
            }
            $linhas = $rececao->itensGuiaSaida()->with('produto')->get();
            if ($linhas->contains(fn ($l) => ! $l->item_compra_id)) {
                throw new ErroNegocio('Recepção do legado sem ligação às linhas da encomenda: reverta-a manualmente na Logística/Contabilidade.', 'RECECAO_LEGADO', 422);
            }
            if ($rececao->contabilizado) {
                $this->lancamentos->estornar($this->localizador->localizar($rececao->numero_lan_contabilizacao, (string) $rececao->numero_entrega, null, 'D', 'GL'), $motivo);
            }
            $data = now()->toDateString();
            foreach ($linhas as $l) {
                if (! $l->produto?->movimenta_stock) {
                    continue;
                }
                $item = ItemCompra::query()->lockForUpdate()->findOrFail($l->item_compra_id);
                // saída ao custo da ENTRADA (o legado usava cost_price); sem stock suficiente, recusa
                $this->stock->saida($l->produto_id, (int) $rececao->armazem_id, (string) $l->quantidade, (string) $l->custo_unitario_kz, $data,
                    "Reversão da recepção {$rececao->numero_rececao}: {$motivo}");
                $item->update([
                    'cambial_recebido_por_faturar_qtd' => bcsub((string) $item->cambial_recebido_por_faturar_qtd, (string) $l->cambial_q2, 3),
                    'cambial_recebido_por_faturar_kz' => bcsub((string) $item->cambial_recebido_por_faturar_kz, (string) $l->cambial_v2, 2),
                    'cambial_faturado_por_receber_qtd' => bcadd((string) ($item->cambial_faturado_por_receber_qtd ?? 0), (string) $l->cambial_q1, 3),
                    'cambial_faturado_por_receber_kz' => bcadd((string) ($item->cambial_faturado_por_receber_kz ?? 0), (string) $l->cambial_v1, 2),
                    'valor_recebido_kz' => bcsub((string) $item->valor_recebido_kz, (string) $l->valor_kz, 2),
                ]);
            }
            $rececao->update(['estado' => 'RECEBIDO', 'validado' => false, 'contabilizado' => false, 'numero_lan_contabilizacao' => null,
                'validado_em' => null, 'validado_por' => null, 'valor_total_kz' => null]);

            return $rececao;
        });
    }
}
