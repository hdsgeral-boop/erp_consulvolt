<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Models\UnidadeNegocio;
use App\Services\Sistema\ServicoUnidadesNegocio;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/sistema/unidades-negocio — Terceiros e tabelas › Unidades de negócio (permissões do legado: consulta
 * "tabelas_aux", gravação "aux_gerir", eliminação "aux_eliminar").
 */
final class UnidadeNegocioController extends Controller
{
    private const VER = ['tabelas_aux_view', 'aux_gerir'];

    public function __construct(private readonly ServicoUnidadesNegocio $unidades) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['estado' => ['nullable', Rule::in(['ATIVO', 'INATIVO'])]]);

        return RespostaApi::sucesso($this->unidades->listar($d['estado'] ?? null), 'Unidades de negócio obtidas com sucesso.');
    }

    public function show(int $unidade): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(UnidadeNegocio::query()->findOrFail($unidade), 'Unidade de negócio obtida com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('aux_gerir');

        return RespostaApi::criado($this->unidades->guardar($this->validar($r, true)), 'Unidade de negócio criada.');
    }

    public function update(Request $r, int $unidade): JsonResponse
    {
        $this->exigir('aux_gerir');

        return RespostaApi::sucesso($this->unidades->guardar($this->validar($r, false), UnidadeNegocio::query()->findOrFail($unidade)), 'Unidade de negócio actualizada.');
    }

    public function destroy(int $unidade): JsonResponse
    {
        $this->exigir('aux_eliminar');
        $this->unidades->eliminar(UnidadeNegocio::query()->findOrFail($unidade));

        return RespostaApi::sucesso(null, 'Unidade de negócio eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r, bool $criar): array
    {
        $texto = fn (int $max) => ['sometimes', 'nullable', 'string', "max:{$max}"];

        return $r->validate([
            'codigo' => [$criar ? 'required' : 'sometimes', 'string', 'max:50'], 'nome' => [$criar ? 'required' : 'sometimes', 'string', 'max:255'],
            'nome_abreviado' => $texto(100), 'descricao' => $texto(2000), 'unidade_negocio_pai_id' => ['sometimes', 'nullable', 'integer'],
            'ordem_sequencia' => ['sometimes', 'nullable', 'integer', 'min:0'], 'estado' => ['sometimes', Rule::in(['ATIVO', 'INATIVO'])],
            'valido_de' => ['sometimes', 'nullable', 'date'], 'valido_ate' => ['sometimes', 'nullable', 'date'],
            'endereco' => $texto(500), 'cidade' => $texto(100), 'estado_fluxo' => $texto(100), 'codigo_postal' => $texto(30), 'pais' => $texto(100),
            'telefone' => $texto(50), 'email' => ['sometimes', 'nullable', 'email', 'max:150'], 'fax' => $texto(50), 'website' => $texto(255),
            'codigo_moeda' => $texto(10), 'bolsa_valores' => ['sometimes', 'nullable', 'numeric'], 'simbolo_bolsa' => $texto(50),
            'colaborador_gestor_id' => ['sometimes', 'nullable', 'integer'], 'tem_vendas' => ['sometimes', 'boolean'], 'tem_servico' => ['sometimes', 'boolean'],
        ], [], ['codigo' => 'código', 'valido_ate' => 'data de fim', 'colaborador_gestor_id' => 'gerente']);
    }
}
