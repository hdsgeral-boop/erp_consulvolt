<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sistema\EmpresaResource;
use App\Models\Empresa;
use App\Services\Sistema\ServicoEmpresas;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Empresas: selector de empresa do Top Header (index/show, sem empresa activa) e gestão de empresas
 * (config_empresas: consulta "config_empresas_view", gestão "config_empresas_gerir").
 */
final class EmpresaController extends Controller
{
    private const VER = ['config_empresas_view', 'config_empresas_gerir'];

    public function __construct(
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoGestaoEmpresas $gestao,
    ) {}

    /** GET /api/sistema/empresas */
    public function index(Request $request): JsonResponse
    {
        return RespostaApi::sucesso(
            EmpresaResource::collection($this->empresas->acessiveis($request->user())),
            'Empresas acessíveis obtidas com sucesso.',
        );
    }

    /** GET /api/sistema/empresas/{empresa} — 404 também quando não há acesso (não revela a existência). */
    public function show(Request $request, int $empresa): JsonResponse
    {
        abort_unless($this->empresas->podeAceder($request->user(), $empresa), 404);

        return RespostaApi::sucesso(EmpresaResource::make(Empresa::query()->findOrFail($empresa)), 'Empresa obtida com sucesso.');
    }

    /** GET /api/sistema/gestao-empresas — todas as empresas geríveis, em qualquer estado. */
    public function gestao(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['estado' => ['nullable', Rule::in([Empresa::ESTADO_ATIVO, Empresa::ESTADO_INATIVO])]]);

        return RespostaApi::sucesso($this->gestao->listar($r->user(), $d['estado'] ?? null), 'Empresas obtidas com sucesso.');
    }

    /** GET /api/sistema/gestao-empresas/{empresa} — ficha completa, com o logótipo. */
    public function ficha(Request $r, int $empresa): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->gestao->apresentar($this->gestao->obter($empresa, $r->user()), true), 'Empresa obtida com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('config_empresas_gerir');
        $e = $this->gestao->criar($this->validar($r, true), $r->user());

        return RespostaApi::criado($this->gestao->apresentar($e), 'Empresa criada com sucesso.');
    }

    public function update(Request $r, int $empresa): JsonResponse
    {
        $this->exigir('config_empresas_gerir');
        $e = $this->gestao->atualizar($this->gestao->obter($empresa, $r->user()), $this->validar($r, false), $r->user());

        return RespostaApi::sucesso($this->gestao->apresentar($e), 'Empresa actualizada com sucesso.');
    }

    public function estado(Request $r, int $empresa): JsonResponse
    {
        $this->exigir('config_empresas_gerir');
        $d = $r->validate(['estado' => ['required', Rule::in([Empresa::ESTADO_ATIVO, Empresa::ESTADO_INATIVO])]]);
        $e = $this->gestao->definirEstado($this->gestao->obter($empresa, $r->user()), $d['estado'], $r->user());

        return RespostaApi::sucesso($this->gestao->apresentar($e), $e->estado === Empresa::ESTADO_ATIVO ? 'Empresa activada.' : 'Empresa desactivada.');
    }

    /** PUT /api/sistema/empresa-ativa/horas-extra — regras das horas extra (RH › Rubricas: rh_infotipos_gerir). */
    public function horasExtra(Request $r): JsonResponse
    {
        $this->exigir('rh_infotipos_gerir', 'config_empresas_gerir');
        $d = $r->validate(['he_percentagem_1' => ['required', 'numeric', 'min:0', 'max:1000'], 'he_limite_horas' => ['required', 'numeric', 'min:0', 'max:744'],
            'he_percentagem_2' => ['required', 'numeric', 'min:0', 'max:1000']], [], ['he_percentagem_1' => '% até ao limite', 'he_limite_horas' => 'limite de horas',
                'he_percentagem_2' => '% acima do limite']);

        return RespostaApi::sucesso($this->gestao->apresentar($this->gestao->gravarHorasExtra($d)), 'Regras das horas extra guardadas.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r, bool $criar): array
    {
        $obrigatorio = $criar ? 'required' : 'sometimes';

        return $r->validate([
            'nome' => [$obrigatorio, 'string', 'max:255'], 'nif' => [$obrigatorio, 'string', 'max:30'],
            'endereco' => ['sometimes', 'nullable', 'string', 'max:2000'], 'provincia' => ['sometimes', 'nullable', 'string', 'max:100'],
            'municipio' => ['sometimes', 'nullable', 'string', 'max:100'], 'comuna' => ['sometimes', 'nullable', 'string', 'max:100'],
            'telefone' => ['sometimes', 'nullable', 'string', 'max:50'], 'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'], 'numero_registo_comercial' => ['sometimes', 'nullable', 'string', 'max:50'],
            'rodape_documento' => ['sometimes', 'nullable', 'string', 'max:2000'], 'regras_ia' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'logotipo' => ['sometimes', 'nullable', 'string', 'max:1500000'],
            'taxa_inss_patronal' => ['sometimes', 'numeric', 'between:0,100'], 'taxa_inss_trabalhador' => ['sometimes', 'numeric', 'between:0,100'],
            'he_percentagem_1' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'], 'he_limite_horas' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:744'],
            'he_percentagem_2' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'moeda_funcional' => ['sometimes', 'string', 'size:3'], 'e_consolidacao' => ['sometimes', 'boolean'], 'moeda_consolidacao' => ['sometimes', 'nullable', 'string', 'size:3'],
        ], [], ['nif' => 'NIF', 'taxa_inss_patronal' => 'INSS entidade patronal', 'taxa_inss_trabalhador' => 'INSS trabalhador']);
    }
}
