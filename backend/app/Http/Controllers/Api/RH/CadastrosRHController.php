<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\Banco;
use App\Models\InfotipoSalarial;
use App\Models\TipoOrganizacaoRH;
use App\Services\RH\ServicoCadastrosRH;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/rh/infotipos, /tipos-organizacao, /bancos e /mapeamentos-contabeis — tabelas de suporte dos salários. */
final class CadastrosRHController extends Controller
{
    public function __construct(private readonly ServicoCadastrosRH $cadastros) {}

    // ───────────── Rubricas ─────────────

    public function infotipos(): JsonResponse
    {
        $this->exigir('infotipos_view', 'contratos_view', 'calcular_view', 'contabilidade_view');

        return RespostaApi::sucesso(InfotipoSalarial::query()->orderBy('tipo', 'desc')->orderBy('nome')->get(), 'Rubricas salariais.');
    }

    public function guardarInfotipo(Request $r, ?int $infotipo = null): JsonResponse
    {
        $this->exigir('rh_infotipos_gerir');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'tipo' => ['sometimes', 'in:VENCIMENTO,DESCONTO,OUTROS'],
            'sujeito_inss' => ['sometimes', 'boolean'], 'irt' => ['sometimes', 'in:true,false,conditional_30k'], 'base_horaria' => ['sometimes', 'boolean'],
            'calculo_horas' => ['nullable', 'in:,EXTRA,FALTA,NAO']]);
        $i = $infotipo ? InfotipoSalarial::query()->findOrFail($infotipo) : null;
        $res = $this->cadastros->guardarInfotipo($d, $i);

        return $i ? RespostaApi::sucesso($res, 'Rubrica actualizada.') : RespostaApi::criado($res, 'Rubrica criada.');
    }

    public function eliminarInfotipo(int $infotipo): JsonResponse
    {
        $this->exigir('rh_infotipo_del');
        $this->cadastros->eliminarInfotipo(InfotipoSalarial::query()->findOrFail($infotipo));

        return RespostaApi::sucesso(null, 'Rubrica eliminada.');
    }

    // ───────────── Tipos de organização ─────────────

    public function tiposOrganizacao(): JsonResponse
    {
        $this->exigir('colaboradores_view', 'funcoes_view', 'contabilidade_view');

        return RespostaApi::sucesso(TipoOrganizacaoRH::query()->orderBy('nome')->get(), 'Tipos de organização.');
    }

    public function guardarTipoOrganizacao(Request $r, ?int $tipo = null): JsonResponse
    {
        $this->exigir('rh_funcoes_gerir');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255']]);
        $t = $tipo ? TipoOrganizacaoRH::query()->findOrFail($tipo) : null;
        $res = $this->cadastros->guardarTipoOrganizacao($d['nome'], $t);

        return $t ? RespostaApi::sucesso($res, 'Tipo de organização actualizado.') : RespostaApi::criado($res, 'Tipo de organização criado.');
    }

    public function eliminarTipoOrganizacao(int $tipo): JsonResponse
    {
        $this->exigir('rh_funcao_del');
        $this->cadastros->eliminarTipoOrganizacao(TipoOrganizacaoRH::query()->findOrFail($tipo));

        return RespostaApi::sucesso(null, 'Tipo de organização eliminado.');
    }

    // ───────────── Bancos ─────────────

    public function bancos(): JsonResponse
    {
        $this->exigir('bancario_view', 'rh_bancario_gerir', 'colaboradores_view', 'vendas_faturacao_view');

        return RespostaApi::sucesso(Banco::query()->orderBy('nome')->get(), 'Bancos.');
    }

    public function guardarBanco(Request $r, ?int $banco = null): JsonResponse
    {
        $this->exigir('rh_bancario_gerir');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'codigo' => ['nullable', 'string', 'max:50'], 'nif' => ['nullable', 'string', 'max:30'],
            'endereco' => ['nullable', 'string', 'max:1000'], 'codigo_conta' => ['nullable', 'string', 'max:20']]);
        $b = $banco ? Banco::query()->findOrFail($banco) : null;
        $res = $this->cadastros->guardarBanco($d, $b);

        return $b ? RespostaApi::sucesso($res, 'Banco actualizado.') : RespostaApi::criado($res, 'Banco criado.');
    }

    public function eliminarBanco(int $banco): JsonResponse
    {
        $this->exigir('rh_banco_del');
        $this->cadastros->eliminarBanco(Banco::query()->findOrFail($banco));

        return RespostaApi::sucesso(null, 'Banco eliminado.');
    }

    // ───────────── Mapeamento contabilístico ─────────────

    public function mapeamentos(): JsonResponse
    {
        $this->exigir('contabilidade_view', 'contab_mapeamento', 'processamento_view');

        return RespostaApi::sucesso($this->cadastros->mapeamentos(), 'Mapeamento contabilístico dos salários.');
    }

    public function gravarMapeamentos(Request $r): JsonResponse
    {
        $this->exigir('contab_mapeamento');
        $d = $r->validate([
            'rubricas' => ['sometimes', 'array'], 'rubricas.*.infotipo_salarial_id' => ['required', 'integer'],
            'rubricas.*.tipo_organizacao_id' => ['nullable', 'integer'], 'rubricas.*.avencado' => ['sometimes', 'boolean'], 'rubricas.*.numero_conta' => ['nullable', 'string', 'max:20'],
            'sistema' => ['sometimes', 'array'], 'sistema.*.codigo' => ['required', Rule::in(ServicoCadastrosRH::CODIGOS_SISTEMA)],
            'sistema.*.tipo_organizacao_id' => ['nullable', 'integer'], 'sistema.*.avencado' => ['sometimes', 'boolean'], 'sistema.*.numero_conta' => ['nullable', 'string', 'max:20'],
        ]);
        foreach ($d['rubricas'] ?? [] as $m) {
            InfotipoSalarial::query()->findOrFail($m['infotipo_salarial_id']);
        }
        foreach ([...($d['rubricas'] ?? []), ...($d['sistema'] ?? [])] as $m) {
            ! empty($m['tipo_organizacao_id']) && TipoOrganizacaoRH::query()->findOrFail($m['tipo_organizacao_id']);
        }

        return RespostaApi::sucesso($this->cadastros->gravarMapeamentos($d['rubricas'] ?? [], $d['sistema'] ?? []), 'Mapeamentos gravados.');
    }
}
