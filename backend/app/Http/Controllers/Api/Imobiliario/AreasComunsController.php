<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\EspacoComum;
use App\Services\Imobiliario\CatalogoParqueamento;
use App\Services\Imobiliario\ServicoAreasComuns;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Áreas comuns: espaços, calendário e disponibilidade do dia (Onda 3, ecrã imo_areas). Ver: imo_areas_view. Criar, editar, activar e apagar
 * espaços: imo_area_editar (também recusadas no servidor, antes da validação). As reservas estão em ReservasController.
 *  GET  /imobiliario/areas/espacos|resumo|avisos|calendario         listas e indicadores
 *  POST /imobiliario/areas/espacos                                  novo espaço;  PUT|DELETE /imobiliario/areas/espacos/{id}
 *  POST /imobiliario/areas/espacos/{id}/activo                      activar ou pôr inactivo
 *  GET  /imobiliario/areas/espacos/{id}/dia?data=AAAA-MM-DD         reservas e intervalos livres do dia
 */
final class AreasComunsController extends Controller
{
    public function __construct(private readonly ServicoAreasComuns $areas) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $f = $r->validate(['imovel_id' => ['nullable', 'integer', 'min:1'], 'activo' => ['nullable', 'boolean'], 'reservaveis' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->areas->listar($f)), 'Espaços comuns.');
    }

    public function resumo(Request $r): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $f = $r->validate(['imovel_id' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->areas->resumo($f['imovel_id'] ?? null)), 'Indicadores das áreas comuns.');
    }

    public function avisos(Request $r): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $f = $r->validate(['imovel_id' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::sucesso($this->areas->avisos($f['imovel_id'] ?? null), 'Avisos das áreas comuns.');
    }

    public function calendario(Request $r): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $f = $r->validate(['imovel_id' => ['nullable', 'integer', 'min:1'], 'espaco_id' => ['nullable', 'integer', 'min:1'], 'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->areas->calendario($f)), 'Calendário de reservas.');
    }

    public function dia(Request $r, int $espaco): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $f = $r->validate(['data' => ['required', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->areas->dia(EspacoComum::query()->findOrFail($espaco), $f['data'])), 'Disponibilidade do dia.');
    }

    /** @return array<string, mixed> */
    private function regras(): array
    {
        return [
            'nome' => ['nullable', 'string', 'max:120'], 'imovel_id' => ['nullable', 'integer', 'min:1'], 'tipo' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::TIPOS_AREA))],
            'regime' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::REGIMES))], 'localizacao' => ['nullable', 'string', 'max:150'], 'capacidade' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'hora_abre' => ['nullable', 'string', 'max:5'], 'hora_fecha' => ['nullable', 'string', 'max:5'], 'dias_semana' => ['nullable', 'array', 'max:7'], 'dias_semana.*' => ['integer', 'min:0', 'max:6'],
            'duracao_min' => ['nullable', 'numeric', 'min:0', 'max:9999'], 'duracao_max' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'antecedencia_min_dias' => ['nullable', 'integer', 'min:0', 'max:3650'], 'antecedencia_max_dias' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'limite_mes_fraccao' => ['nullable', 'integer', 'min:0', 'max:1000'], 'exige_aprovacao' => ['nullable', 'boolean'], 'preco' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'preco_unidade' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::UNIDADES_PRECO))], 'caucao' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'prazo_pagamento_horas' => ['nullable', 'integer', 'min:1', 'max:8760'], 'cancel_dias' => ['nullable', 'integer', 'min:0', 'max:3650'], 'cancel_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'regulamento' => ['nullable', 'string', 'max:10000'], 'regulamento_url' => ['nullable', 'string', 'max:1000'], 'activo' => ['nullable', 'boolean'], 'notas' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('imo_area_editar');
        $e = $this->areas->gravar($r->validate($this->regras()));

        return RespostaApi::criado(new ImobiliarioResource($e), 'Espaço criado.');
    }

    public function update(Request $r, int $espaco): JsonResponse
    {
        $this->exigir('imo_area_editar');
        $e = $this->areas->gravar($r->validate($this->regras()), EspacoComum::query()->findOrFail($espaco));

        return RespostaApi::sucesso(new ImobiliarioResource($e), 'Espaço actualizado.');
    }

    public function activo(Request $r, int $espaco): JsonResponse
    {
        $this->exigir('imo_area_editar');
        $d = $r->validate(['activo' => ['required', 'boolean']]);
        $e = $this->areas->activar(EspacoComum::query()->findOrFail($espaco), (bool) $d['activo']);

        return RespostaApi::sucesso(new ImobiliarioResource($e), $e->activo ? 'Espaço activo.' : 'Espaço inactivo.');
    }

    public function destroy(int $espaco): JsonResponse
    {
        $this->exigir('imo_area_editar');
        $this->areas->eliminar(EspacoComum::query()->findOrFail($espaco));

        return RespostaApi::sucesso(null, 'Espaço apagado.');
    }
}
