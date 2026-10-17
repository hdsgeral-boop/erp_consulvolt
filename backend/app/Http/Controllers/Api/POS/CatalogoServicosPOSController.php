<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Models\Produto;
use App\Models\TerminalPOS;
use App\Services\POS\ServicoCatalogoPOS;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo do POS por serviço (r3_vendas):
 *   GET  /pos/terminais/{terminal}/catalogo — produtos que o terminal pode vender (filtro no servidor);
 *   GET  /pos/produtos/servicos             — serviços disponíveis e marcação de cada produto (para o ecrã de Produtos);
 *   PUT  /pos/produtos/{produto}/servicos    — grava a marcação (servicos_pos: lista de tipos de terminal, ou null = todos).
 */
final class CatalogoServicosPOSController extends Controller
{
    public function __construct(private readonly ServicoCatalogoPOS $catalogo) {}

    public function catalogo(int $terminal): JsonResponse
    {
        $this->exigir('pos_view', 'pos_venda', 'hotel_estadias', 'hotel_checkout');

        return RespostaApi::sucesso($this->catalogo->catalogo(TerminalPOS::query()->findOrFail($terminal)), 'Catálogo do terminal.');
    }

    public function marcacoes(): JsonResponse
    {
        $this->exigir('vendas_produtos_view', 'vendas_produtos_gerir', 'pos_terminais_gerir');

        return RespostaApi::sucesso([
            'servicos' => array_map(fn ($s) => ['valor' => $s, 'rotulo' => ServicoCatalogoPOS::ROTULOS[$s] ?? $s], ServicoCatalogoPOS::SERVICOS),
            'produtos' => $this->catalogo->marcacoes(),
        ], 'Serviços POS dos produtos.');
    }

    public function definir(Request $request, int $produto): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');
        $d = $request->validate([
            'servicos_pos' => ['present', 'nullable', 'array', 'max:'.count(ServicoCatalogoPOS::SERVICOS)],
            'servicos_pos.*' => ['string', Rule::in(ServicoCatalogoPOS::SERVICOS)],
        ], [], ['servicos_pos' => 'serviços POS']);
        $servicos = $this->catalogo->definir(Produto::query()->findOrFail($produto), $d['servicos_pos']);

        return RespostaApi::sucesso(['id' => $produto, 'servicos_pos' => $servicos],
            $servicos === null ? 'O produto aparece em todos os POS.' : ($servicos ? 'Serviços POS do produto gravados.' : 'O produto deixou de aparecer nos POS.'));
    }
}
