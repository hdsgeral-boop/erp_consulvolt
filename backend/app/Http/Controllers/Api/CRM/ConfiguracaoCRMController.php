<?php

namespace App\Http\Controllers\Api\CRM;

use App\Http\Controllers\Controller;
use App\Models\FunilVendasCRM;
use App\Models\ModeloEmailCRM;
use App\Models\SequenciaCampanhaCRM;
use App\Services\CRM\RegrasCRM;
use App\Services\CRM\ServicoConfiguracaoCRM;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/crm — definições, funis de vendas (etapas), modelos de email e sequências. */
final class ConfiguracaoCRMController extends Controller
{
    public const VER = ['crm_pipeline_view', 'crm_agenda_view', 'crm_contas_view', 'crm_previsao_view', 'crm_campanhas_view', 'crm_config_view',
        'crm_editar', 'crm_converter', 'crm_configurar', 'crm_campanhas_enviar'];

    public function __construct(private readonly ServicoConfiguracaoCRM $config) {}

    public function obterConfiguracao(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->config->obter() + ['tipos_atividade' => RegrasCRM::TIPOS_ATIVIDADE, 'marcadores' => RegrasCRM::MARCADORES], 'Definições do CRM.');
    }

    public function guardarConfiguracao(Request $r): JsonResponse
    {
        $this->exigir('crm_configurar');
        $d = $r->validate(['motivos_perda' => ['required'], 'origens' => ['nullable'], 'dias_sem_atividade' => ['nullable', 'integer', 'min:1', 'max:365'],
            'prazo_pagamento_dias' => ['nullable', 'integer', 'min:0', 'max:3650']]);

        return RespostaApi::sucesso($this->config->guardar($d), 'Definições do CRM gravadas.');
    }

    // ───────────── Funis ─────────────

    public function funis(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->config->funis(), 'Funis de vendas.');
    }

    public function funil(int $funil): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->config->funil($funil), 'Funil de vendas.');
    }

    public function guardarFunil(Request $r, ?int $funil = null): JsonResponse
    {
        $this->exigir('crm_configurar');
        $r->validate(['nome' => ['required', 'string', 'max:255'], 'ordem' => ['nullable', 'integer'], 'ativo' => ['nullable', 'boolean'],
            'etapas' => ['required', 'array', 'min:3', 'max:30'], 'etapas.*.id' => ['nullable', 'string', 'max:20'], 'etapas.*.nome' => ['nullable', 'string', 'max:100'],
            'etapas.*.probabilidade' => ['nullable', 'numeric', 'min:0', 'max:100'], 'etapas.*.dias_estagnacao' => ['nullable', 'integer', 'min:0'],
            'etapas.*.tipo' => ['required', Rule::in(RegrasCRM::TIPOS_ETAPA)], 'etapas.*.cor' => ['nullable', 'string', 'max:20'], 'etapas.*.tarefas' => ['nullable', 'array', 'max:20'],
            'etapas.*.tarefas.*.tipo' => ['nullable', Rule::in(array_keys(RegrasCRM::TIPOS_ATIVIDADE))], 'etapas.*.tarefas.*.titulo' => ['nullable', 'string', 'max:255'],
            'etapas.*.tarefas.*.dias' => ['nullable', 'integer', 'min:0'], 'etapas.*.tarefas.*.modelo_email_crm_id' => ['nullable', 'integer']]);
        $existente = $funil ? $this->config->funil($funil) : null;
        $res = $this->config->guardarFunil($r->only(['nome', 'ordem', 'ativo', 'etapas']), $existente);

        return $existente ? RespostaApi::sucesso($res, 'Funil gravado.') : RespostaApi::criado($res, 'Funil criado.');
    }

    public function eliminarFunil(int $funil): JsonResponse
    {
        $this->exigir('crm_configurar');
        $this->config->eliminarFunil(FunilVendasCRM::query()->findOrFail($funil));

        return RespostaApi::sucesso(null, 'Funil eliminado.');
    }

    // ───────────── Modelos de email ─────────────

    public function modelos(): JsonResponse
    {
        $this->exigir(...self::VER);
        $this->config->funis();   // a primeira utilização cria os modelos de partida

        return RespostaApi::sucesso(ModeloEmailCRM::query()->orderBy('nome')->get(), 'Modelos de email.');
    }

    public function guardarModelo(Request $r, ?int $modelo = null): JsonResponse
    {
        $this->exigir('crm_configurar');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'assunto' => ['required', 'string', 'max:100'], 'corpo' => ['nullable', 'string', 'max:20000']]);
        $m = $modelo ? ModeloEmailCRM::query()->findOrFail($modelo) : null;
        $res = $this->config->guardarModelo($d, $m);

        return $m ? RespostaApi::sucesso($res, 'Modelo gravado.') : RespostaApi::criado($res, 'Modelo criado.');
    }

    public function eliminarModelo(int $modelo): JsonResponse
    {
        $this->exigir('crm_configurar');
        $this->config->eliminarModelo(ModeloEmailCRM::query()->findOrFail($modelo));

        return RespostaApi::sucesso(null, 'Modelo eliminado.');
    }

    // ───────────── Sequências ─────────────

    public function sequencias(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(SequenciaCampanhaCRM::query()->orderBy('nome')->get(), 'Sequências de email.');
    }

    public function guardarSequencia(Request $r, ?int $sequencia = null): JsonResponse
    {
        $this->exigir('crm_configurar');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'funil_vendas_crm_id' => ['required', 'integer'], 'etapa_codigo' => ['required', 'string', 'max:20'],
            'ativo' => ['nullable', 'boolean'], 'passos' => ['required', 'array', 'min:1', 'max:5'], 'passos.*.dias' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'passos.*.modelo_email_crm_id' => ['nullable', 'integer']]);
        $s = $sequencia ? SequenciaCampanhaCRM::query()->findOrFail($sequencia) : null;
        $res = $this->config->guardarSequencia($d, $s);

        return $s ? RespostaApi::sucesso($res, 'Sequência gravada.') : RespostaApi::criado($res, 'Sequência criada.');
    }

    public function eliminarSequencia(int $sequencia): JsonResponse
    {
        $this->exigir('crm_configurar');
        $this->config->eliminarSequencia(SequenciaCampanhaCRM::query()->findOrFail($sequencia));

        return RespostaApi::sucesso(null, 'Sequência eliminada (as actividades já agendadas mantêm-se).');
    }
}
