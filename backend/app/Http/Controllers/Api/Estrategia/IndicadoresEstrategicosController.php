<?php

namespace App\Http\Controllers\Api\Estrategia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Estrategia\GravarIndicadorRequest;
use App\Http\Requests\Estrategia\GravarMedicaoRequest;
use App\Http\Requests\Estrategia\GravarMetasRequest;
use App\Http\Resources\Estrategia\EstrategiaResource;
use App\Http\Resources\Estrategia\IndicadorEstrategicoResource;
use App\Services\Estrategia\ServicoIndicadoresEstrategicos;
use App\Services\Estrategia\ServicoMetasMedicoes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Planeamento Estratégico — indicadores, metas e medição manual (ecrã est_indicadores). Ver: est_indicadores_view.
 * Indicadores: est_ind_editar. Metas: est_metas_editar. Entregar o valor de um indicador manual: est_entregar.
 * Valores em kwanzas só com est_ver_financeiros (senão `oculto: true`).
 *  GET    estrategia/catalogos
 *  GET    estrategia/planos/{plano}/indicadores
 *  POST   estrategia/planos/{plano}/indicadores
 *  PUT    estrategia/planos/{plano}/indicadores/{indicador}
 *  DELETE estrategia/planos/{plano}/indicadores/{indicador}
 *  POST   estrategia/indicadores/{indicador}/testar
 *  GET|PUT estrategia/indicadores/{indicador}/metas
 *  GET    estrategia/indicadores/{indicador}/serie?ano=
 *  POST   estrategia/indicadores/{indicador}/medicoes
 */
final class IndicadoresEstrategicosController extends Controller
{
    public function __construct(private readonly ServicoIndicadoresEstrategicos $indicadores, private readonly ServicoMetasMedicoes $metas) {}

    public function catalogos(): JsonResponse
    {
        $this->exigir('est_plano_view', 'est_indicadores_view');

        return RespostaApi::sucesso(new EstrategiaResource($this->indicadores->catalogos()), 'Catálogos do planeamento estratégico.');
    }

    public function index(int $plano): JsonResponse
    {
        $this->exigir('est_indicadores_view');
        $lista = $this->indicadores->listar($plano)->map(fn ($i) => (new IndicadorEstrategicoResource($i))->resolve())->all();

        return RespostaApi::sucesso(new EstrategiaResource(['indicadores' => $lista]), 'Indicadores do plano.');
    }

    public function store(GravarIndicadorRequest $r, int $plano): JsonResponse
    {
        [$d, $motivo] = $this->separar($r->validated());

        return RespostaApi::criado(new IndicadorEstrategicoResource($this->indicadores->obter($this->indicadores->criar($plano, $d, $motivo)->id)), 'Indicador criado.');
    }

    public function update(GravarIndicadorRequest $r, int $plano, int $indicador): JsonResponse
    {
        [$d, $motivo] = $this->separar($r->validated());

        return RespostaApi::sucesso(new IndicadorEstrategicoResource($this->indicadores->obter($this->indicadores->actualizar($plano, $indicador, $d, $motivo)->id)), 'Indicador gravado.');
    }

    public function destroy(Request $r, int $plano, int $indicador): JsonResponse
    {
        $this->exigir('est_ind_editar');
        $this->indicadores->apagar($plano, $indicador, $r->input('motivo_excepcao'));

        return RespostaApi::sucesso(null, 'Indicador apagado.');
    }

    public function testar(Request $r, int $indicador): JsonResponse
    {
        $this->exigir('est_ind_editar');
        $periodo = $r->validate(['periodo' => ['nullable', 'string', 'max:8']])['periodo'] ?? null;

        return RespostaApi::sucesso(new EstrategiaResource($this->indicadores->testar($indicador, $periodo)), 'Medição de teste (não é gravada).');
    }

    public function metas(int $indicador): JsonResponse
    {
        $this->exigir('est_indicadores_view');

        return RespostaApi::sucesso(new EstrategiaResource($this->metas->metas($indicador)), 'Metas do indicador.');
    }

    public function gravarMetas(GravarMetasRequest $r, int $indicador): JsonResponse
    {
        return RespostaApi::sucesso(new EstrategiaResource($this->metas->gravarMetas($indicador, $r->validated('metas'), $r->validated('motivo_excepcao'))), 'Metas gravadas.');
    }

    public function serie(Request $r, int $indicador): JsonResponse
    {
        $this->exigir('est_indicadores_view', 'est_painel_view');
        $ano = $r->validate(['ano' => ['nullable', 'integer', 'min:1901', 'max:2200']])['ano'] ?? null;

        return RespostaApi::sucesso(new EstrategiaResource($this->metas->serie($indicador, $ano === null ? null : (int) $ano)), 'Série do indicador.');
    }

    public function entregar(GravarMedicaoRequest $r, int $indicador): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::criado(new EstrategiaResource($this->metas->entregar($indicador, (string) $d['periodo'], $d['valor'], $d['nota'] ?? null, isset($d['unidade_organica_id']) ? (int) $d['unidade_organica_id'] : null)), 'Valor entregue.');
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function separar(array $d): array
    {
        $motivo = $d['motivo_excepcao'] ?? null;
        unset($d['motivo_excepcao']);

        return [$d, $motivo];
    }
}
