<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Armazem\ExpedirRequest;
use App\Http\Requests\POS\Armazem\ListaRequest;
use App\Http\Requests\POS\Armazem\VendasRequest;
use App\Http\Requests\POS\Armazem\VenderRequest;
use App\Http\Resources\POS\VendaPOSResource;
use App\Models\GuiaSaida;
use App\Models\ItemGuiaSaida;
use App\Models\Venda;
use App\Services\Compras\RelacoesNomes;
use App\Services\POS\ServicoPOSArmazem;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * /api/pos/armazem — POS de armazém: stock do armazém, venda ao balcão (guia de saída) e picking de encomendas (NE → GR).
 * Artigos em cartões, acerto de stock e senhas: ParidadePOSArmazemController (routes/api/paridade_pos_stock/pos_armazem.php).
 */
final class POSArmazemController extends Controller
{
    private const VER = ['pos_armazem_view', 'pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir'];

    public function __construct(private readonly ServicoPOSArmazem $pos) {}

    // ───────────── Venda ao balcão ─────────────

    public function vendas(VendasRequest $r): JsonResponse
    {
        $f = $r->validated();
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

    public function vender(VenderRequest $r): JsonResponse
    {
        $d = $r->validated();
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

    public function lista(ListaRequest $r, int $encomenda): JsonResponse
    {
        $f = $r->validated();

        return RespostaApi::sucesso($this->pos->lista(Venda::query()->findOrFail($encomenda), (int) $f['armazem_id']), 'Lista de recolha.');
    }

    public function expedir(ExpedirRequest $r, int $encomenda): JsonResponse
    {
        // «recolhidos»: as linhas marcadas como «Verificado» na checklist; o servidor exige-as todas (não depende só do ecrã)
        $d = $r->validated();
        $gr = $this->pos->expedir(Venda::query()->findOrFail($encomenda), (int) $d['armazem_id'], array_map('intval', $d['recolhidos']), $d['observacoes'] ?? null);

        return RespostaApi::criado(new VendaPOSResource($gr), "Picking concluído: guia de remessa {$gr->numero_documento} emitida.");
    }
}
