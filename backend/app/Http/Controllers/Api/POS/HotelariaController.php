<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Hotelaria\AlterarRequest;
use App\Http\Requests\POS\Hotelaria\AnularRequest;
use App\Http\Requests\POS\Hotelaria\CheckinRequest;
use App\Http\Requests\POS\Hotelaria\ConsumosRequest;
use App\Http\Requests\POS\Hotelaria\FazerCheckoutRequest;
use App\Http\Requests\POS\Hotelaria\GuardarQuartoRequest;
use App\Http\Requests\POS\Hotelaria\ListaRequest;
use App\Http\Requests\POS\Hotelaria\QuartosRequest;
use App\Http\Requests\POS\Hotelaria\SimularCheckoutRequest;
use App\Models\EstadiaHotel;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Services\Logistica\ServicoProdutos;
use App\Services\POS\Hotelaria\ServicoCheckoutHotel;
use App\Services\POS\Hotelaria\ServicoEstadiasHotel;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * /api/pos/hotelaria — mapa de quartos, estadias (check-in, alteração, consumos, anulação) e check-out com facturação POS.
 * Desconto e preços diferentes do quarto/produto exigem pos_desconto (no legado não eram protegidos na hotelaria).
 */
final class HotelariaController extends Controller
{
    private const VER = ['pos_hotelaria_view', 'hotel_estadias', 'hotel_checkout', 'hotel_anular', 'hotel_quartos'];

    public function __construct(
        private readonly ServicoEstadiasHotel $estadias,
        private readonly ServicoCheckoutHotel $checkout,
    ) {}

    // ───────────── Quartos ─────────────

    public function quartos(QuartosRequest $r): JsonResponse
    {
        $f = $r->validated();

        return RespostaApi::sucesso($this->estadias->quartos(isset($f['terminal_pos_id']) ? TerminalPOS::query()->findOrFail($f['terminal_pos_id']) : null), 'Mapa de quartos.');
    }

    /** Tarifas do quarto (produto marcado como quarto: preço por hora e por diária, mínimo de horas). */
    public function guardarQuarto(GuardarQuartoRequest $r, int $produto, ServicoProdutos $produtos): JsonResponse
    {
        $d = $r->validated();
        $p = $produtos->guardar($d + ['e_quarto' => true], Produto::query()->findOrFail($produto));

        return RespostaApi::sucesso($p->only(['id', 'codigo', 'nome', 'e_quarto', 'preco_unitario', 'taxa_imposto', 'preco_por_hora', 'preco_por_dia', 'horas_minimas']), 'Quarto actualizado.');
    }

    // ───────────── Estadias ─────────────

    public function lista(ListaRequest $r): JsonResponse
    {
        $f = $r->validated();
        $q = EstadiaHotel::query();
        foreach (['estado', 'terminal_pos_id', 'produto_quarto_id', 'cliente_hospede_id'] as $c) {
            $q->when($f[$c] ?? null, fn ($q, $v) => $q->where($c, $v));
        }
        $q->when($f['de'] ?? null, fn ($q, $v) => $q->where('entrada_em', '>=', $v))->when($f['ate'] ?? null, fn ($q, $v) => $q->where('entrada_em', '<', now()->parse($v)->addDay()));

        return RespostaApi::paginado($q->orderByDesc('entrada_em')->orderByDesc('id')->paginate($f['por_pagina'] ?? 50, ['*'], 'pagina', $f['pagina'] ?? 1), null, 'Estadias.');
    }

    public function estadia(int $estadia): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->estadias->detalhe(EstadiaHotel::query()->findOrFail($estadia)), 'Estadia.');
    }

    public function checkin(CheckinRequest $r, int $sessao): JsonResponse
    {
        $d = $r->validated();
        if (isset($d['preco_unitario'])) {
            $this->exigirPrecoDoQuarto(Produto::query()->findOrFail($d['produto_quarto_id']), $d['modo'], $d['preco_unitario']);
        }
        $e = $this->estadias->checkin(SessaoPOS::query()->findOrFail($sessao), $d);

        return RespostaApi::criado($e, "Check-in do {$e->nome_quarto} registado.");
    }

    public function alterar(AlterarRequest $r, int $estadia): JsonResponse
    {
        $d = $r->validated();
        $e = EstadiaHotel::query()->findOrFail($estadia);
        if (isset($d['preco_unitario']) && bccomp(number_format((float) $d['preco_unitario'], 2, '.', ''), (string) $e->preco_unitario, 2) !== 0) {
            $this->exigirPrecoDoQuarto(Produto::query()->withTrashed()->findOrFail($e->produto_quarto_id), $e->modo, $d['preco_unitario']);
        }

        return RespostaApi::sucesso($this->estadias->alterar($e, $d), 'Estadia alterada.');
    }

    public function consumos(ConsumosRequest $r, int $estadia): JsonResponse
    {
        $d = $r->validated();
        // alteração de preço no carrinho do quarto (updatePOSCartPrice, permissoes.js:229)
        $precos = Produto::query()->whereIn('id', array_column($d['linhas'], 'produto_id'))->pluck('preco_unitario', 'id');
        if (collect($d['linhas'])->contains(fn ($l) => isset($l['preco_unitario'])
            && bccomp(number_format((float) $l['preco_unitario'], 2, '.', ''), number_format((float) ($precos[$l['produto_id']] ?? 0), 2, '.', ''), 2) !== 0)) {
            $this->exigir('pos_desconto');
        }

        return RespostaApi::sucesso($this->estadias->consumos(EstadiaHotel::query()->findOrFail($estadia), $d['linhas']), 'Consumos gravados.');
    }

    public function anular(AnularRequest $r, int $estadia): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->estadias->anular(EstadiaHotel::query()->findOrFail($estadia), $d['motivo']), 'Check-in anulado.');
    }

    // ───────────── Check-out ─────────────

    public function simular(SimularCheckoutRequest $r, int $sessao): JsonResponse
    {
        return RespostaApi::sucesso($this->checkout->simular(SessaoPOS::query()->findOrFail($sessao), $r->validated()), 'Pré-visualização do check-out.');
    }

    public function fazerCheckout(FazerCheckoutRequest $r, int $sessao): JsonResponse
    {
        $d = $r->validated();
        if ((float) ($d['percentagem_desconto'] ?? 0) > 0) {
            $this->exigir('pos_desconto');
        }
        $res = $this->checkout->checkout(SessaoPOS::query()->findOrFail($sessao), $d);
        $numeros = implode(', ', array_map(fn ($v) => $v->numero_documento, $res['vendas']));

        return RespostaApi::criado(['vendas' => array_map(fn ($v) => $v->load('itensVenda'), $res['vendas']), 'total' => $res['total'], 'troco' => $res['troco']],
            "Check-out concluído. Emitido(s): {$numeros}. Troco: ".number_format((float) $res['troco'], 2, ',', ' ').' Kz.');
    }

    /** Preço do alojamento diferente do do quarto (por modo) exige pos_desconto. */
    private function exigirPrecoDoQuarto(Produto $quarto, string $modo, mixed $preco): void
    {
        if (bccomp(number_format((float) $preco, 2, '.', ''), ServicoEstadiasHotel::precoQuarto($quarto, $modo), 2) !== 0) {
            $this->exigir('pos_desconto');
        }
    }
}
