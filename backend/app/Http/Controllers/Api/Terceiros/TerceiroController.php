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
 * tabelas auxiliares (tabelas_aux). As permissões dependem do papel da ficha gravada; «Cliente e Fornecedor»,
 * «Colaborador» e a substituição do tipo só nas Tabelas auxiliares (aux_gerir).
 */
final class TerceiroController extends Controller
{
    /** Consulta: também o POS, a lavandaria e a hotelaria, que escolhem o cliente/hóspede (Fase 5). */
    private const VER = ['vendas_clientes_view', 'compras_fornecedores_view', 'tabelas_aux_view', 'lancamentos_view', 'vendas_faturacao_view',
        'pos_venda', 'lav_ordens', 'lav_receber', 'hotel_estadias', 'hotel_checkout', 'pos_terminais_gerir'];

    public function __construct(private readonly ServicoTerceiros $terceiros) {}

    public function index(Request $request): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $request->validate([
            'papel' => ['nullable', 'in:CLIENTE,FORNECEDOR,COLABORADOR'], 'pesquisa' => ['nullable', 'string', 'max:200'], 'estrito' => ['nullable', 'boolean'],
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
        $papel = $this->autorizar($request);

        return RespostaApi::criado(TerceiroResource::make($this->terceiros->guardar($papel, $request->dados(), null, $request->substituirTipo())),
            match ($papel) {
                'CLIENTE' => 'Cliente criado com sucesso.',
                'FORNECEDOR' => 'Fornecedor criado com sucesso.',
                default => 'Terceiro criado com sucesso.',
            });
    }

    /** PUT — actualiza a ficha; se o papel for novo para a entidade, acrescenta-o (ex.: fornecedor que passa a cliente). */
    public function update(GuardarTerceiroRequest $request, int $terceiro): JsonResponse
    {
        $papel = $this->autorizar($request);

        return RespostaApi::sucesso(TerceiroResource::make($this->terceiros->guardar($papel, $request->dados(), Terceiro::query()->findOrFail($terceiro), $request->substituirTipo())),
            'Entidade actualizada com sucesso.');
    }

    public function destroy(int $terceiro): JsonResponse
    {
        $this->exigir('vendas_dados_del', 'aux_eliminar');
        $this->terceiros->eliminar(Terceiro::query()->findOrFail($terceiro));

        return RespostaApi::sucesso(null, 'Entidade eliminada com sucesso.');
    }

    /** Permissão pelo papel da ficha; substituir o tipo é só das Tabelas auxiliares. Devolve o papel. */
    private function autorizar(GuardarTerceiroRequest $request): string
    {
        $papel = $request->string('papel')->toString();
        $this->exigir(...($request->substituirTipo() ? ['aux_gerir'] : GuardarTerceiroRequest::GERIR[$papel]));

        return $papel;
    }
}
