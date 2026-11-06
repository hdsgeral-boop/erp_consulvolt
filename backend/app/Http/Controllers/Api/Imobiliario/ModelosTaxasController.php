<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imobiliario\GravarModeloRequest;
use App\Http\Requests\Imobiliario\GravarTaxasRequest;
use App\Http\Requests\Imobiliario\PreVisualizarModeloRequest;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\ModeloDocumentoImo;
use App\Services\Imobiliario\CatalogoImobiliario;
use App\Services\Imobiliario\ServicoModelosDocumento;
use App\Services\Imobiliario\ServicoTaxasImobiliario;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Modelos de documentos e taxas fiscais (Onda 1, ecrã imo_modelos). Ver: imo_modelos_view. Modelos: imo_modelo_editar. Taxas:
 * imo_taxas_editar (sensível). As taxas são editáveis e «por validar»: a conformidade legal não é afirmada pelo ERP.
 *  GET|POST /imobiliario/modelos, PUT|DELETE /imobiliario/modelos/{id}, GET /imobiliario/modelos/catalogo
 *  POST /imobiliario/modelos/importar-padrao, POST /imobiliario/modelos/pre-visualizar
 *  GET /imobiliario/taxas?ano=, PUT /imobiliario/taxas/{ano}, POST /imobiliario/taxas/{ano}/validar
 */
final class ModelosTaxasController extends Controller
{
    public function __construct(private readonly ServicoModelosDocumento $modelos, private readonly ServicoTaxasImobiliario $taxas) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('imo_modelos_view');
        $f = $r->validate(['tipo' => ['nullable', 'string', Rule::in(array_keys((array) CatalogoImobiliario::fabrica()['tipos']))]]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->modelos->listar($f['tipo'] ?? null)), 'Modelos de documentos.');
    }

    public function catalogo(): JsonResponse
    {
        $this->exigir('imo_modelos_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->modelos->catalogo()), 'Tipos e variáveis dos modelos.');
    }

    public function store(GravarModeloRequest $r): JsonResponse
    {
        return RespostaApi::criado(new ImobiliarioResource($this->modelos->criar($r->validated())), 'Modelo criado.');
    }

    public function update(GravarModeloRequest $r, int $modelo): JsonResponse
    {
        return RespostaApi::sucesso(new ImobiliarioResource($this->modelos->actualizar(ModeloDocumentoImo::query()->findOrFail($modelo), $r->validated())), 'Modelo gravado.');
    }

    public function destroy(int $modelo): JsonResponse
    {
        $this->exigir('imo_modelo_editar');
        $this->modelos->eliminar(ModeloDocumentoImo::query()->findOrFail($modelo));

        return RespostaApi::sucesso(null, 'Modelo eliminado.');
    }

    public function importarPadrao(): JsonResponse
    {
        $this->exigir('imo_modelo_editar');
        $n = $this->modelos->importarPadrao();

        return RespostaApi::sucesso(new ImobiliarioResource(['importados' => $n]), $n === 0 ? 'Os modelos por omissão já estavam importados.' : "{$n} modelo(s) importado(s).");
    }

    public function preVisualizar(PreVisualizarModeloRequest $r): JsonResponse
    {
        $d = $r->validated();
        $valores = (array) ($d['valores'] ?? []);
        $corpo = $this->modelos->renderizar((string) $d['corpo'], $valores);
        $assunto = $this->modelos->renderizar((string) ($d['assunto'] ?? ''), $valores);

        return RespostaApi::sucesso(new ImobiliarioResource(['corpo' => $corpo['texto'], 'assunto' => $assunto['texto'],
            'faltas' => array_values(array_unique(array_merge($corpo['faltas'], $assunto['faltas']))), 'invalidas' => $this->modelos->variaveisInvalidas($d['corpo'].' '.($d['assunto'] ?? ''))]),
            'Pré-visualização do modelo.');
    }

    public function taxas(Request $r): JsonResponse
    {
        $this->exigir('imo_modelos_view');
        $f = $r->validate(['ano' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $ano = (int) ($f['ano'] ?? now()->year);

        return RespostaApi::sucesso(new ImobiliarioResource($this->taxas->doAno($ano) + ['anos_gravados' => $this->taxas->anos()]), 'Taxas do ano.');
    }

    public function gravarTaxas(GravarTaxasRequest $r, int $ano): JsonResponse
    {
        return RespostaApi::sucesso(new ImobiliarioResource($this->taxas->gravar($ano, $r->validated()) + ['anos_gravados' => $this->taxas->anos()]),
            "Taxas de {$ano} gravadas. As rendas já facturadas não mudam.");
    }

    public function validarTaxas(GravarTaxasRequest $r, int $ano): JsonResponse
    {
        return RespostaApi::sucesso(new ImobiliarioResource($this->taxas->validar($ano, (string) ($r->validated('nota') ?? '')) + ['anos_gravados' => $this->taxas->anos()]),
            "Taxas de {$ano} marcadas como validadas.");
    }
}
