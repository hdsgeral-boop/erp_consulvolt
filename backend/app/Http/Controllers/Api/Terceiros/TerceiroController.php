<?php

namespace App\Http\Controllers\Api\Terceiros;

use App\Http\Controllers\Controller;
use App\Http\Requests\Terceiros\GuardarTerceiroRequest;
use App\Http\Resources\Terceiros\TerceiroResource;
use App\Models\Terceiro;
use App\Services\Terceiros\ServicoTerceiros;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/terceiros — clientes (ecrã vendas_clientes), fornecedores (compras_fornecedores) e terceiros das
 * tabelas auxiliares (tabelas_aux). As permissões dependem do papel da ficha gravada.
 */
final class TerceiroController extends Controller
{
    private const VER = ['vendas_clientes_view', 'compras_fornecedores_view', 'tabelas_aux_view', 'lancamentos_view', 'vendas_faturacao_view'];

    private const GERIR = ['CLIENTE' => ['vendas_clientes_gerir', 'aux_gerir'], 'FORNECEDOR' => ['compras_forn_gerir', 'aux_gerir']];

    public function __construct(private readonly ServicoTerceiros $terceiros) {}

    public function index(Request $request): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $request->validate([
            'papel' => ['nullable', 'in:CLIENTE,FORNECEDOR,COLABORADOR'], 'pesquisa' => ['nullable', 'string', 'max:200'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        return RespostaApi::paginado($this->terceiros->listar($f), TerceiroResource::class);
    }

    public function show(int $terceiro): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(TerceiroResource::make(Terceiro::query()->findOrFail($terceiro)), 'Entidade obtida com sucesso.');
    }

    public function store(GuardarTerceiroRequest $request): JsonResponse
    {
        $papel = $request->string('papel')->toString();
        $this->exigir(...self::GERIR[$papel]);

        return RespostaApi::criado(TerceiroResource::make($this->terceiros->guardar($papel, $request->dados())),
            $papel === 'CLIENTE' ? 'Cliente criado com sucesso.' : 'Fornecedor criado com sucesso.');
    }

    /** PUT — actualiza a ficha; se o papel for novo para a entidade, acrescenta-o (ex.: fornecedor que passa a cliente). */
    public function update(GuardarTerceiroRequest $request, int $terceiro): JsonResponse
    {
        $papel = $request->string('papel')->toString();
        $this->exigir(...self::GERIR[$papel]);

        return RespostaApi::sucesso(TerceiroResource::make($this->terceiros->guardar($papel, $request->dados(), Terceiro::query()->findOrFail($terceiro))),
            'Entidade actualizada com sucesso.');
    }

    public function destroy(int $terceiro): JsonResponse
    {
        $this->exigir('vendas_dados_del', 'aux_eliminar');
        $this->terceiros->eliminar(Terceiro::query()->findOrFail($terceiro));

        return RespostaApi::sucesso(null, 'Entidade eliminada com sucesso.');
    }
}
