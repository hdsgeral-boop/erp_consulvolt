<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Models\EstadiaHotel;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Services\Logistica\ServicoProdutos;
use App\Services\POS\Hotelaria\ServicoCheckoutHotel;
use App\Services\POS\Hotelaria\ServicoEstadiasHotel;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function quartos(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['terminal_pos_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->estadias->quartos(isset($f['terminal_pos_id']) ? TerminalPOS::query()->findOrFail($f['terminal_pos_id']) : null), 'Mapa de quartos.');
    }

    /** Tarifas do quarto (produto marcado como quarto: preço por hora e por diária, mínimo de horas). */
    public function guardarQuarto(Request $r, int $produto, ServicoProdutos $produtos): JsonResponse
    {
        $this->exigir('hotel_quartos');
        $d = $r->validate(['e_quarto' => ['sometimes', 'boolean'], 'preco_por_hora' => ['nullable', 'numeric', 'min:0'], 'preco_por_dia' => ['nullable', 'numeric', 'min:0'],
            'horas_minimas' => ['nullable', 'numeric', 'min:0']]);
        $p = $produtos->guardar($d + ['e_quarto' => true], Produto::query()->findOrFail($produto));

        return RespostaApi::sucesso($p->only(['id', 'codigo', 'nome', 'e_quarto', 'preco_unitario', 'taxa_imposto', 'preco_por_hora', 'preco_por_dia', 'horas_minimas']), 'Quarto actualizado.');
    }

    // ───────────── Estadias ─────────────

    public function lista(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['estado' => ['nullable', Rule::in([EstadiaHotel::ABERTA, EstadiaHotel::FECHADA, EstadiaHotel::ANULADA])], 'terminal_pos_id' => ['nullable', 'integer'],
            'produto_quarto_id' => ['nullable', 'integer'], 'cliente_hospede_id' => ['nullable', 'integer'], 'de' => ['nullable', 'date'], 'ate' => ['nullable', 'date'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
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

    public function checkin(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('hotel_estadias');
        $d = $r->validate([
            'produto_quarto_id' => ['required', 'integer'], 'cliente_hospede_id' => ['required', 'integer'], 'modo' => ['required', Rule::in(EstadiaHotel::MODOS)],
            'entrada_em' => ['nullable', 'date'], 'quantidade' => ['required', 'numeric', 'gt:0'], 'preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'numero_hospedes' => ['nullable', 'integer', 'min:1'], 'observacoes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($d['preco_unitario'])) {
            $this->exigirPrecoDoQuarto(Produto::query()->findOrFail($d['produto_quarto_id']), $d['modo'], $d['preco_unitario']);
        }
        $e = $this->estadias->checkin(SessaoPOS::query()->findOrFail($sessao), $d);

        return RespostaApi::criado($e, "Check-in do {$e->nome_quarto} registado.");
    }

    public function alterar(Request $r, int $estadia): JsonResponse
    {
        $this->exigir('hotel_estadias');
        $d = $r->validate([
            'cliente_hospede_id' => ['nullable', 'integer'], 'entrada_em' => ['nullable', 'date'], 'quantidade' => ['nullable', 'numeric', 'gt:0'],
            'preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'], 'numero_hospedes' => ['nullable', 'integer', 'min:1'], 'observacoes' => ['nullable', 'string', 'max:2000'],
        ]);
        $e = EstadiaHotel::query()->findOrFail($estadia);
        if (isset($d['preco_unitario']) && bccomp(number_format((float) $d['preco_unitario'], 2, '.', ''), (string) $e->preco_unitario, 2) !== 0) {
            $this->exigirPrecoDoQuarto(Produto::query()->withTrashed()->findOrFail($e->produto_quarto_id), $e->modo, $d['preco_unitario']);
        }

        return RespostaApi::sucesso($this->estadias->alterar($e, $d), 'Estadia alterada.');
    }

    public function consumos(Request $r, int $estadia): JsonResponse
    {
        $this->exigir('hotel_estadias', 'pos_venda');
        $d = $r->validate(['linhas' => ['present', 'array'], 'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'gt:0'],
            'linhas.*.preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0']]);
        // alteração de preço no carrinho do quarto (updatePOSCartPrice, permissoes.js:229)
        $precos = Produto::query()->whereIn('id', array_column($d['linhas'], 'produto_id'))->pluck('preco_unitario', 'id');
        if (collect($d['linhas'])->contains(fn ($l) => isset($l['preco_unitario'])
            && bccomp(number_format((float) $l['preco_unitario'], 2, '.', ''), number_format((float) ($precos[$l['produto_id']] ?? 0), 2, '.', ''), 2) !== 0)) {
            $this->exigir('pos_desconto');
        }

        return RespostaApi::sucesso($this->estadias->consumos(EstadiaHotel::query()->findOrFail($estadia), $d['linhas']), 'Consumos gravados.');
    }

    public function anular(Request $r, int $estadia): JsonResponse
    {
        $this->exigir('hotel_anular');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);

        return RespostaApi::sucesso($this->estadias->anular(EstadiaHotel::query()->findOrFail($estadia), $d['motivo']), 'Check-in anulado.');
    }

    // ───────────── Check-out ─────────────

    public function simular(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('hotel_checkout');

        return RespostaApi::sucesso($this->checkout->simular(SessaoPOS::query()->findOrFail($sessao), $this->validarCheckout($r, false)), 'Pré-visualização do check-out.');
    }

    public function fazerCheckout(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('hotel_checkout');
        $d = $this->validarCheckout($r, true);
        if ((float) ($d['percentagem_desconto'] ?? 0) > 0) {
            $this->exigir('pos_desconto');
        }
        $res = $this->checkout->checkout(SessaoPOS::query()->findOrFail($sessao), $d);
        $numeros = implode(', ', array_map(fn ($v) => $v->numero_documento, $res['vendas']));

        return RespostaApi::criado(['vendas' => array_map(fn ($v) => $v->load('itensVenda'), $res['vendas']), 'total' => $res['total'], 'troco' => $res['troco']],
            "Check-out concluído. Emitido(s): {$numeros}. Troco: ".number_format((float) $res['troco'], 2, ',', ' ').' Kz.');
    }

    private function validarCheckout(Request $r, bool $comPagamentos): array
    {
        return $r->validate([
            'estadias' => ['required', 'array', 'min:1'], 'estadias.*.id' => ['required', 'integer'], 'estadias.*.opcao_atraso' => ['nullable', 'in:RECALCULAR,MANTER'],
            'percentagem_desconto' => ['nullable', 'numeric', 'between:0,100'], 'modo_faturacao' => ['required', 'in:POR_QUARTO,UNICA'],
            'cliente_id' => ['nullable', 'integer', 'required_if:modo_faturacao,UNICA'], 'observacoes' => ['nullable', 'string', 'max:2000'],
            'pagamentos' => [$comPagamentos ? 'required' : 'nullable', 'array', 'min:1'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'],
            'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'], 'pagamentos.*.referencia' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /** Preço do alojamento diferente do do quarto (por modo) exige pos_desconto. */
    private function exigirPrecoDoQuarto(Produto $quarto, string $modo, mixed $preco): void
    {
        if (bccomp(number_format((float) $preco, 2, '.', ''), ServicoEstadiasHotel::precoQuarto($quarto, $modo), 2) !== 0) {
            $this->exigir('pos_desconto');
        }
    }
}
