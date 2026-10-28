<?php

namespace App\Http\Controllers\Api\Terceiros;

use App\Http\Controllers\Controller;
use App\Http\Requests\Terceiros\GuardarTerceiroRequest;
use App\Http\Resources\Terceiros\TerceiroResource;
use App\Models\Terceiro;
use App\Services\Terceiros\ServicoConsultaNifAgt;
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
        'pos_venda', 'lav_ordens', 'lav_receber', 'hotel_estadias', 'hotel_checkout', 'pos_terminais_gerir', 'credores_devedores_view'];

    public function __construct(private readonly ServicoTerceiros $terceiros) {}

    public function index(Request $request): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $request->validate([
            'papel' => ['nullable', 'in:CLIENTE,FORNECEDOR,COLABORADOR,CREDOR_DEVEDOR'], 'pesquisa' => ['nullable', 'string', 'max:200'], 'estrito' => ['nullable', 'boolean'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        return RespostaApi::paginado($this->terceiros->listar($f), TerceiroResource::class);
    }

    public function show(int $terceiro): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(TerceiroResource::make(Terceiro::query()->findOrFail($terceiro)), 'Entidade obtida com sucesso.');
    }

    /** Valida o NIF no Portal do Contribuinte da AGT ao criar/editar um terceiro (servidor-a-servidor). */
    public function consultaNifAgt(Request $r): JsonResponse
    {
        $this->exigir('vendas_clientes_gerir', 'compras_forn_gerir', 'aux_gerir', 'credores_devedores_gerir');
        $f = $r->validate(['nif' => ['required', 'string', 'max:30']]);

        return RespostaApi::sucesso(app(ServicoConsultaNifAgt::class)->consultar($f['nif']), 'Consulta à AGT concluída.');
    }

    public function store(GuardarTerceiroRequest $request): JsonResponse
    {
        $papel = $this->autorizar($request);

        return RespostaApi::criado(TerceiroResource::make($this->terceiros->guardar($papel, $request->dados(), null, $request->substituirTipo())),
            match ($papel) {
                'CLIENTE' => 'Cliente criado com sucesso.',
                'FORNECEDOR' => 'Fornecedor criado com sucesso.',
                'CREDOR_DEVEDOR' => 'Credor/devedor criado com sucesso.',
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
