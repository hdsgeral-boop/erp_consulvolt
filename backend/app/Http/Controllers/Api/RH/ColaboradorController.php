<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\CoordenadaBancariaColaborador;
use App\Services\RH\ServicoColaboradores;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/rh/colaboradores — ecrã «Colaboradores» e ficha; /coordenada-bancaria — ecrã «Coordenadas bancárias». */
final class ColaboradorController extends Controller
{
    private const VER = ['colaboradores_view', 'contratos_view', 'calcular_view', 'processamento_view'];

    public function __construct(private readonly ServicoColaboradores $colaboradores) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['estado' => ['nullable', 'in:ACTIVO,INACTIVO,SUSPENSO'], 'tipo_organizacao_id' => ['nullable', 'integer'], 'pesquisa' => ['nullable', 'string', 'max:200'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::paginado($this->colaboradores->listar($f));
    }

    public function show(int $colaborador): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->colaboradores->ficha(Colaborador::query()->findOrFail($colaborador)), 'Colaborador obtido com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('colaboradores_detail');

        return RespostaApi::criado($this->colaboradores->ficha($this->colaboradores->guardar($this->dados($r))), 'Colaborador criado com sucesso.');
    }

    public function update(Request $r, int $colaborador): JsonResponse
    {
        $this->exigir('colaboradores_detail');
        $c = Colaborador::query()->findOrFail($colaborador);

        return RespostaApi::sucesso($this->colaboradores->ficha($this->colaboradores->guardar($this->dados($r), $c)), 'Colaborador actualizado com sucesso.');
    }

    public function destroy(int $colaborador): JsonResponse
    {
        $this->exigir('rh_colab_del');
        $this->colaboradores->eliminar(Colaborador::query()->findOrFail($colaborador));

        return RespostaApi::sucesso(null, 'Colaborador eliminado com sucesso.');
    }

    // ───────────── Coordenadas bancárias ─────────────

    public function coordenadas(): JsonResponse
    {
        $this->exigir('bancario_view', 'rh_bancario_gerir');

        return RespostaApi::sucesso(CoordenadaBancariaColaborador::query()->with('banco:id,nome')->orderBy('colaborador_id')->get(), 'Coordenadas bancárias dos colaboradores.');
    }

    public function gravarCoordenada(Request $r, int $colaborador): JsonResponse
    {
        $this->exigir('rh_bancario_gerir');
        $d = $r->validate(['banco_id' => ['required', 'integer'], 'iban' => ['required', 'string', 'max:50']]);

        return RespostaApi::sucesso($this->colaboradores->gravarCoordenada(Colaborador::query()->findOrFail($colaborador), (int) $d['banco_id'], $d['iban']),
            'Coordenadas bancárias gravadas.');
    }

    public function eliminarCoordenada(int $colaborador): JsonResponse
    {
        $this->exigir('rh_banco_del');
        $this->colaboradores->eliminarCoordenada(Colaborador::query()->findOrFail($colaborador));

        return RespostaApi::sucesso(null, 'Coordenadas bancárias eliminadas.');
    }

    /** @return array<string, mixed> */
    private function dados(Request $r): array
    {
        $e = app(ContextoEmpresa::class)->obrigatorio();
        $existe = fn (string $t) => Rule::exists($t, 'id')->where('empresa_id', $e)->whereNull('eliminado_em');

        return $r->validate([
            'nome_completo' => ['required', 'string', 'max:255'], 'nif' => ['required', 'string', 'max:30'], 'numero_inss' => ['nullable', 'string', 'max:50'],
            'estado' => ['sometimes', 'in:ACTIVO,INACTIVO,SUSPENSO'], 'tipo_organizacao_id' => ['required', 'integer', $existe('tipos_organizacao_rh')],
            'cargo_funcao_id' => ['nullable', 'integer', $existe('cargos_funcoes')], 'dias_uteis_mes' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'reformado' => ['sometimes', 'boolean'], 'avencado' => ['sometimes', 'boolean'],
            'unidade_negocio_id' => ['nullable', 'integer', $existe('unidades_negocio')], 'centro_custo_id' => ['nullable', 'integer', $existe('centros_custo')],
            'unidade_organica_id' => ['nullable', 'integer', $existe('unidades_organicas')], 'posto_trabalho_id' => ['nullable', 'integer', $existe('postos_trabalho')],
            'colaborador_gestor_id' => ['nullable', 'integer', $existe('colaboradores')],
            'sexo' => ['nullable', 'in:M,F'], 'data_nascimento' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            'estado_civil' => ['nullable', 'in:SOLTEIRO,CASADO,DIVORCIADO,VIUVO,UNIAO_FACTO'], 'nacionalidade' => ['nullable', 'string', 'max:20'],
            'naturalidade' => ['nullable', 'string', 'max:20'], 'provincia_naturalidade' => ['nullable', 'string', 'max:20'],
            'documento_identificacao' => ['nullable', 'string', 'max:30'], 'documento_validade' => ['nullable', 'date'], 'data_admissao' => ['nullable', 'date'],
            'endereco' => ['nullable', 'string', 'max:1000'], 'bairro' => ['nullable', 'string', 'max:150'], 'municipio' => ['nullable', 'string', 'max:150'],
            'provincia' => ['nullable', 'string', 'max:150'], 'telefone' => ['nullable', 'string', 'max:50'], 'telefone_alternativo' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email:rfc', 'max:255'], 'emergencia_nome' => ['nullable', 'string', 'max:255'], 'emergencia_telefone' => ['nullable', 'string', 'max:50'],
            'emergencia_parentesco' => ['nullable', 'string', 'max:100'], 'habilitacao_maxima' => ['nullable', Rule::in(ServicoColaboradores::NIVEIS)],
            'dependentes' => ['sometimes', 'array', 'max:30'], 'dependentes.*.nome' => ['required', 'string', 'max:255'],
            'dependentes.*.parentesco' => ['nullable', 'in:FILHO,CONJUGE,PAI,MAE,OUTRO'], 'dependentes.*.data_nascimento' => ['nullable', 'date', 'before_or_equal:today'],
            'dependentes.*.sexo' => ['nullable', 'in:M,F'], 'dependentes.*.dependente_fiscal' => ['nullable', 'boolean'],
            'habilitacoes' => ['sometimes', 'array', 'max:30'], 'habilitacoes.*.nivel' => ['required', Rule::in(ServicoColaboradores::NIVEIS)],
            'habilitacoes.*.curso' => ['nullable', 'string', 'max:255'], 'habilitacoes.*.instituicao' => ['nullable', 'string', 'max:255'],
            'habilitacoes.*.ano_conclusao' => ['nullable', 'digits:4'], 'habilitacoes.*.estado' => ['nullable', 'string', 'max:50'],
        ], [], ['nif' => 'NIF', 'tipo_organizacao_id' => 'tipo de organização', 'dias_uteis_mes' => 'dias úteis']);
    }
}
