<?php

namespace App\Services\POS\Hotelaria;

use App\Exceptions\ErroNegocio;
use App\Models\EstadiaHotel;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\POS\ServicoTerminaisPOS;
use App\Services\POS\ServicoVendasPOS;
use App\Services\Vendas\CalculadoraDocumento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Check-out e facturação da hotelaria (showPOSHotelCheckout/finalizePOSHotelPayment, js/hotelaria.js:497-703).
 * Cada factura é uma factura-recibo POS (ServicoVendasPOS::vender: série do terminal, AGT, stock dos consumos e CMV) com
 * o alojamento (produto do quarto, conta de proveitos e IVA do produto) e os consumos; uma por quarto (cliente de cada
 * estadia) ou única (cliente indicado). Os pagamentos são rateados pelas facturas na proporção do total, com o acerto do
 * cêntimo no maior e o troco na última (:651-665). A saída tardia propõe o recálculo (RECALCULAR) ou mantém o contratado
 * (MANTER); com atraso, a decisão é obrigatória. Correcções face ao legado:
 *   - tudo numa transacção, com as estadias bloqueadas (o legado emitia factura a factura: uma falha a meio deixava umas
 *     estadias facturadas e outras abertas);
 *   - os totais com desconto vêm do motor da venda POS (CalculadoraDocumento::calcularComIva), os mesmos da factura
 *     (o legado recalculava-os à parte, :636-644);
 *   - a ligação factura ↔ estadias fica na tabela vendas_estadias_hotel (o legado: lista de ids na venda);
 *   - o desconto exige pos_desconto (no controlador; no legado não era protegido).
 */
final class ServicoCheckoutHotel
{
    public function __construct(
        private readonly ServicoEstadiasHotel $estadias,
        private readonly ServicoVendasPOS $vendasPOS,
    ) {}

    /**
     * Pré-visualização (hotelCkAlterar, :565-592): facturas, totais e propostas de saída tardia, sem emitir.
     *
     * @param  array<string, mixed>  $d  estadias[{id, opcao_atraso?}], percentagem_desconto?, modo_faturacao, cliente_id?
     */
    public function simular(SessaoPOS $s, array $d): array
    {
        $t = TerminalPOS::query()->findOrFail($s->terminal_pos_id);
        $estadias = EstadiaHotel::query()->whereIn('id', array_column($d['estadias'], 'id'))->get()->keyBy('id');

        return $this->preparar($t, $d, $estadias, now(), false);
    }

    /**
     * @param  array<string, mixed>  $d  estadias[{id, opcao_atraso?}], percentagem_desconto?, modo_faturacao (POR_QUARTO|UNICA), cliente_id?,
     *                                   pagamentos[{meio_id, valor, referencia?}], observacoes?
     * @return array{vendas: list<Venda>, total: string, troco: string}
     */
    public function checkout(SessaoPOS $s, array $d): array
    {
        return DB::transaction(function () use ($s, $d) {
            [$s, $t] = $this->estadias->sessaoHotel($s);
            $estadias = collect();
            foreach (collect($d['estadias'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values() as $id) {   // ordem fixa dos locks
                $estadias[$id] = $this->estadias->aberta(EstadiaHotel::query()->findOrFail($id));
            }
            $agora = now();
            $p = $this->preparar($t, $d, $estadias, $agora, true);
            [$liquidos, $troco] = $this->vendasPOS->pagamentos($t, $d['pagamentos'], $p['total']);
            if (bccomp($troco, '0', 2) > 0 && ! array_filter($liquidos, fn ($l) => $l['tipo'] === 'NUMERARIO')) {
                // o numerário ficou todo em troco (ex.: TPA pelo total e numerário a mais): a última factura leva-o para devolver o troco
                $num = collect($d['pagamentos'])->last(fn ($x) => ServicoTerminaisPOS::meio($t, (string) $x['meio_id'])['tipo'] === 'NUMERARIO');
                $liquidos[] = ['meio_id' => $num['meio_id'], 'tipo' => 'NUMERARIO', 'valor' => '0.00', 'referencia' => null];
            }
            $rateio = $this->ratear($liquidos, $troco, array_column($p['facturas'], 'total'));
            $pct = $p['percentagem_desconto'];
            $u = Auth::user()?->nome_utilizador;
            $vendas = [];
            foreach ($p['facturas'] as $i => $f) {
                $v = $this->vendasPOS->vender($s, ['cliente_id' => $f['cliente_id'], 'linhas' => $f['linhas'], 'percentagem_desconto' => $pct,
                    'pagamentos' => $rateio[$i], 'observacoes' => $d['observacoes'] ?? null], ['nome_tabela' => implode(', ', array_column($f['estadias'], 'nome_quarto'))]);
                foreach ($f['estadias'] as $x) {
                    DB::table('vendas_estadias_hotel')->insert(['empresa_id' => $v->empresa_id, 'venda_id' => $v->id, 'estadia_hotel_id' => $x['id']]);
                    $e = $estadias[$x['id']];
                    $e->fill(['estado' => EstadiaHotel::FECHADA, 'saida_em' => $agora, 'quantidade_final' => $x['quantidade_final'], 'opcao_atraso' => $x['opcao_atraso'],
                        'percentagem_desconto' => $pct, 'venda_id' => $v->id, 'numero_venda' => $v->numero_documento, 'sessao_fecho_id' => $s->id,
                        'fechado_em' => $agora, 'fechado_por' => $u]);
                    $e->registar("Check-out: {$v->numero_documento} (".RegrasHotel::unidade($e->modo, $x['quantidade_final'])
                        .($x['proposta'] ? ', saída tardia: '.($x['opcao_atraso'] === 'RECALCULAR' ? 'recálculo cobrado' : 'mantido o contratado') : '')
                        .(bccomp($pct, '0', 4) > 0 ? ', desconto '.rtrim(rtrim($pct, '0'), '.').'%' : '').').', $u);
                    $e->save();
                }
                $vendas[] = $v;
            }

            return ['vendas' => $vendas, 'total' => $p['total'], 'troco' => $troco];
        });
    }

    /**
     * Facturas a emitir: linhas (alojamento + consumos), cliente e total com desconto de cada uma.
     *
     * @param  Collection<int, EstadiaHotel>  $estadias
     */
    private function preparar(TerminalPOS $t, array $d, $estadias, Carbon $agora, bool $exigirDecisoes): array
    {
        $rg = RegrasHotel::doTerminal($t);
        $pct = number_format((float) ($d['percentagem_desconto'] ?? 0), 4, '.', '');
        $ids = array_map(fn ($x) => (int) $x['id'], $d['estadias']);
        if (count($ids) !== count(array_unique($ids))) {
            throw new ErroNegocio('A mesma estadia foi indicada mais de uma vez.', 'ESTADIA_REPETIDA', 422);
        }
        $unica = $d['modo_faturacao'] === 'UNICA';
        if ($unica && empty($d['cliente_id'])) {
            throw new ErroNegocio('Seleccione o cliente da factura única.', 'CLIENTE_EM_FALTA', 422);
        }
        $semDecisao = $linhasEstadia = [];
        foreach ($d['estadias'] as $x) {
            $e = $estadias[(int) $x['id']] ?? throw new ErroNegocio("Estadia #{$x['id']} inexistente.", 'ESTADIA_INEXISTENTE', 404);
            if ($e->estado !== EstadiaHotel::ABERTA) {
                throw new ErroNegocio("A estadia do {$e->nome_quarto} já foi fechada ou anulada (possivelmente noutro computador).", 'ESTADIA_NAO_ABERTA', 422);
            }
            $proposta = RegrasHotel::propostaAtraso($e, $rg, $agora);
            $opcao = $proposta ? ($x['opcao_atraso'] ?? null) : null;
            if ($proposta && ! $opcao) {
                $semDecisao[] = $e->nome_quarto;
            }
            $qtd = $proposta && $opcao === 'RECALCULAR' ? $proposta['quantidade'] : number_format((float) $e->quantidade, 3, '.', '');
            $linhas = [['produto_id' => $e->produto_quarto_id, 'quantidade' => $qtd, 'preco_unitario' => (string) $e->preco_unitario,
                'descricao' => "Alojamento {$e->nome_quarto} · ".RegrasHotel::unidade($e->modo, $qtd).' · '.RegrasHotel::dataHora($e->entrada_em).' a '.RegrasHotel::dataHora($agora)]];
            foreach ($e->itens ?? [] as $i) {
                $linhas[] = ['produto_id' => (int) $i['produto_id'], 'quantidade' => (string) $i['quantidade'], 'preco_unitario' => number_format((float) $i['preco_unitario'], 2, '.', ''),
                    'descricao' => $i['descricao'] ?? null];
            }
            $linhasEstadia[] = ['estadia' => ['id' => $e->id, 'nome_quarto' => $e->nome_quarto, 'cliente_hospede_id' => $e->cliente_hospede_id, 'quantidade_final' => $qtd,
                'opcao_atraso' => $opcao, 'proposta' => $proposta, 'total_alojamento' => $e->totalAlojamento($qtd), 'total_consumos' => $e->totalConsumos()], 'linhas' => $linhas];
        }
        if ($exigirDecisoes && $semDecisao) {
            throw new ErroNegocio('Confirme a saída tardia (cobrar o recálculo ou manter o contratado) de: '.implode(', ', $semDecisao).'.', 'ATRASO_POR_DECIDIR', 422,
                ['quartos' => $semDecisao]);
        }
        $grupos = $unica ? [['cliente_id' => (int) $d['cliente_id'], 'itens' => $linhasEstadia]]
            : array_map(fn ($x) => ['cliente_id' => $x['estadia']['cliente_hospede_id'], 'itens' => [$x]], $linhasEstadia);
        $facturas = [];
        $total = '0.00';
        foreach ($grupos as $g) {
            $linhas = array_merge(...array_column($g['itens'], 'linhas'));
            $taxas = Produto::query()->whereIn('id', array_column($linhas, 'produto_id'))->pluck('taxa_imposto', 'id');
            $calc = CalculadoraDocumento::calcularComIva(array_map(fn ($l) => ['quantidade' => $l['quantidade'], 'preco_unitario' => $l['preco_unitario'],
                'taxa_imposto' => $taxas[$l['produto_id']] ?? 0], $linhas), $pct);
            $bruto = array_reduce($linhas, fn ($c, $l) => bcadd($c, CalculadoraDocumento::arredondar(bcmul($l['quantidade'], $l['preco_unitario'], 8)), 2), '0.00');
            $facturas[] = ['cliente_id' => $g['cliente_id'], 'estadias' => array_column($g['itens'], 'estadia'), 'linhas' => $linhas, 'bruto' => $bruto,
                'desconto' => bcsub($bruto, $calc['total_bruto'], 2), 'total_liquido' => $calc['total_liquido'], 'total_imposto' => $calc['total_imposto'], 'total' => $calc['total_bruto']];
            $total = bcadd($total, $calc['total_bruto'], 2);
        }

        return ['facturas' => $facturas, 'total' => $total, 'percentagem_desconto' => $pct, 'saidas_por_decidir' => $semDecisao, 'regras' => $rg];
    }

    /**
     * Rateio dos pagamentos (líquidos do troco) pelas facturas, na proporção do total; acerto do cêntimo no maior pagamento
     * da factura; a última recebe o resto e o troco no numerário.
     *
     * @param  list<array<string, mixed>>  $liquidos
     * @param  list<string>  $totais
     * @return list<list<array{meio_id: string, valor: string, referencia: ?string}>>
     */
    private function ratear(array $liquidos, string $troco, array $totais): array
    {
        $geral = array_reduce($totais, fn ($c, $x) => bcadd($c, $x, 2), '0.00');
        $alocado = array_fill(0, count($liquidos), '0.00');
        $ultima = count($totais) - 1;
        $saida = [];
        foreach ($totais as $gi => $tg) {
            $v = [];
            foreach ($liquidos as $i => $p) {
                $v[$i] = $gi === $ultima ? bcsub($p['valor'], $alocado[$i], 2)
                    : (bccomp($geral, '0', 2) > 0 ? CalculadoraDocumento::arredondar(bcdiv(bcmul($p['valor'], $tg, 10), $geral, 10)) : '0.00');
            }
            if ($gi !== $ultima && $v) {
                $dif = bcsub($tg, array_reduce($v, fn ($c, $x) => bcadd($c, $x, 2), '0.00'), 2);
                if (bccomp($dif, '0', 2) !== 0) {
                    $maior = array_keys($v, max($v))[0];
                    $v[$maior] = bcadd($v[$maior], $dif, 2);
                }
            }
            foreach ($v as $i => $x) {
                $alocado[$i] = bcadd($alocado[$i], $x, 2);
            }
            if ($gi === $ultima && bccomp($troco, '0', 2) > 0) {   // o troco devolve-se do numerário (a venda volta a calculá-lo)
                $num = array_key_last(array_filter($liquidos, fn ($p) => $p['tipo'] === 'NUMERARIO'));
                $v[$num] = bcadd($v[$num], $troco, 2);
            }
            $saida[] = array_values(array_filter(array_map(fn ($i) => ['meio_id' => $liquidos[$i]['meio_id'], 'valor' => $v[$i], 'referencia' => $liquidos[$i]['referencia']],
                array_keys($v)), fn ($p) => bccomp($p['valor'], '0', 2) > 0));
        }

        return $saida;
    }
}
