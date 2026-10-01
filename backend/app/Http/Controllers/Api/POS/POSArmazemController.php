<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Models\GuiaSaida;
use App\Models\ItemGuiaSaida;
use App\Models\Venda;
use App\Services\Compras\RelacoesNomes;
use App\Services\Logistica\ServicoArmazens;
use App\Services\POS\ServicoPOSArmazem;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/pos/armazem — POS de armazém: stock do armazém, venda ao balcão (guia de saída) e picking de encomendas (NE → GR).
 * O acerto de stock do legado não se porta (ADR-050): as regularizações fazem-se na Logística (ajustes e inventário).
 */
final class POSArmazemController extends Controller
{
    private const VER = ['pos_armazem_view', 'pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir'];

    public function __construct(private readonly ServicoPOSArmazem $pos) {}

    public function stock(Request $r, ServicoArmazens $armazens): JsonResponse
    {
        $this->exigir(...self::VER, ...['pos_venda']);   // a frente de caixa mostra o stock do armazém do terminal
        $f = $r->validate(['armazem_id' => ['required', 'integer'], 'so_com_stock' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($armazens->stock((int) $f['armazem_id'], (bool) ($f['so_com_stock'] ?? false)), 'Stock do armazém.');
    }

    // ───────────── Venda ao balcão ─────────────

    public function vendas(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['armazem_id' => ['nullable', 'integer'], 'de' => ['nullable', 'date'], 'ate' => ['nullable', 'date']]);
        $q = GuiaSaida::query()->where(fn ($q) => $q->where('tipo', GuiaSaida::VENDA_BALCAO)->orWhere(fn ($q) => $q->where('tipo', 'VENDA')->where('tipo_original', GuiaSaida::VENDA_BALCAO)))
            ->when($f['armazem_id'] ?? null, fn ($q, $v) => $q->where('armazem_id', $v))->when($f['de'] ?? null, fn ($q, $v) => $q->where('data', '>=', $v))
            ->when($f['ate'] ?? null, fn ($q, $v) => $q->where('data', '<=', $v));

        return RespostaApi::sucesso($q->with(RelacoesNomes::terceiro())->orderByDesc('data')->orderByDesc('id')->get(), 'Vendas ao balcão.');
    }

    public function venda(int $guia): JsonResponse
    {
        $this->exigir(...self::VER);
        $g = GuiaSaida::query()->with(RelacoesNomes::terceiro())->findOrFail($guia);

        return RespostaApi::sucesso($g->toArray() + ['linhas' => ItemGuiaSaida::query()->with(RelacoesNomes::produto())->where('guia_saida_id', $g->id)->orderBy('id')->get()],
            'Guia de saída.');
    }

    public function vender(Request $r): JsonResponse
    {
        $this->exigir('pos_armazem_vender');
        $d = $r->validate(['armazem_id' => ['required', 'integer'], 'terceiro_id' => ['nullable', 'integer'], 'observacoes' => ['nullable', 'string', 'max:2000'],
            'linhas' => ['required', 'array', 'min:1'], 'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'gt:0']]);
        $g = $this->pos->vender($d);

        return RespostaApi::criado($g->toArray() + ['linhas' => ItemGuiaSaida::query()->where('guia_saida_id', $g->id)->orderBy('id')->get(),
            'aviso_contabilizacao' => $g->avisoContabilizacao], "Saída concluída: guia {$g->numero_documento} emitida."
            .($g->avisoContabilizacao ? " CMV por contabilizar: {$g->avisoContabilizacao}" : ''));
    }

    // ───────────── Picking ─────────────

    public function fila(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->pos->fila(), 'Fila de picking.');
    }

    public function lista(Request $r, int $encomenda): JsonResponse
    {
        $this->exigir('pos_armazem_picking', 'pos_armazem_expedir');
        $f = $r->validate(['armazem_id' => ['required', 'integer']]);

        return RespostaApi::sucesso($this->pos->lista(Venda::query()->findOrFail($encomenda), (int) $f['armazem_id']), 'Lista de recolha.');
    }

    public function expedir(Request $r, int $encomenda): JsonResponse
    {
        $this->exigir('pos_armazem_expedir');
        $d = $r->validate(['armazem_id' => ['required', 'integer'], 'observacoes' => ['nullable', 'string', 'max:2000']]);
        $gr = $this->pos->expedir(Venda::query()->findOrFail($encomenda), (int) $d['armazem_id'], $d['observacoes'] ?? null);

        return RespostaApi::criado($gr->load('itensVenda'), "Picking concluído: guia de remessa {$gr->numero_documento} emitida.");
    }
}
