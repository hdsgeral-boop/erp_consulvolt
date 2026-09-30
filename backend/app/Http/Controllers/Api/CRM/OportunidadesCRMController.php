<?php

namespace App\Http\Controllers\Api\CRM;

use App\Http\Controllers\Controller;
use App\Models\AtividadeComercialCRM;
use App\Models\ContaCRM;
use App\Models\ContactoCRM;
use App\Models\FunilVendasCRM;
use App\Models\OportunidadeVendaCRM;
use App\Models\Venda;
use App\Services\CRM\RegrasCRM;
use App\Services\CRM\ServicoConfiguracaoCRM;
use App\Services\CRM\ServicoIndicadoresCRM;
use App\Services\CRM\ServicoOportunidadesCRM;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/crm — oportunidades, quadro Kanban, mudança de etapa, conversão em documento de venda, previsão e indicadores. */
final class OportunidadesCRMController extends Controller
{
    public function __construct(
        private readonly ServicoOportunidadesCRM $oportunidades,
        private readonly ServicoConfiguracaoCRM $config,
        private readonly ServicoIndicadoresCRM $indicadores,
    ) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...ConfiguracaoCRMController::VER);
        $f = $r->validate(['funil_vendas_crm_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'in:ABERTA,GANHA,PERDIDA'], 'conta_crm_id' => ['nullable', 'integer'],
            'responsavel' => ['nullable', 'string', 'max:100'], 'pesquisa' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $q = OportunidadeVendaCRM::query()->with(['contaCrm:id,nome,tipo,terceiro_id'])
            ->when($f['funil_vendas_crm_id'] ?? null, fn ($q, $v) => $q->where('funil_vendas_crm_id', $v))->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($f['conta_crm_id'] ?? null, fn ($q, $v) => $q->where('conta_crm_id', $v))->when($f['responsavel'] ?? null, fn ($q, $v) => $q->where('responsavel', $v))
            ->when($f['pesquisa'] ?? null, fn ($q, $p) => $q->where('titulo', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $p).'%'))
            ->orderByDesc('criado_em')->orderByDesc('id');

        return RespostaApi::paginado($q->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1)), null, 'Oportunidades.');
    }

    public function show(int $oportunidade): JsonResponse
    {
        $this->exigir(...ConfiguracaoCRMController::VER);
        $o = OportunidadeVendaCRM::query()->findOrFail($oportunidade);
        $funil = FunilVendasCRM::query()->findOrFail($o->funil_vendas_crm_id);
        $et = RegrasCRM::etapa($funil->etapas, $o->etapa_codigo);
        $prob = RegrasCRM::probabilidade($o->probabilidade === null ? null : (string) $o->probabilidade, $et);
        $atividades = AtividadeComercialCRM::query()->where('oportunidade_crm_id', $o->id)->orderByDesc('data_prevista')->orderByDesc('id')->get();

        return RespostaApi::sucesso(['oportunidade' => $o, 'etapa' => $et, 'funil' => $funil->only(['id', 'nome']), 'probabilidade_efectiva' => $prob,
            'valor_ponderado' => RegrasCRM::ponderado((string) $o->valor, $prob),
            'saude' => $this->oportunidades->saude($o, $et, $atividades->filter(fn ($a) => ! $a->concluida)->values(), $this->config->obter()),
            'conta' => ContaCRM::query()->withTrashed()->find($o->conta_crm_id), 'contactos' => ContactoCRM::query()->where('conta_crm_id', $o->conta_crm_id)->get(),
            'atividades' => $atividades,
            'documentos' => Venda::query()->where('oportunidade_crm_id', $o->id)->orderBy('id')->get(['id', 'tipo_documento', 'numero_documento', 'data_emissao', 'total_bruto', 'estado'])],
            'Oportunidade.');
    }

    public function guardar(Request $r, ?int $oportunidade = null): JsonResponse
    {
        $this->exigir('crm_editar');
        $d = $r->validate([
            'titulo' => ['required', 'string', 'max:255'], 'conta_crm_id' => ['required', 'integer'], 'contacto_crm_id' => ['nullable', 'integer'],
            'funil_vendas_crm_id' => ['required', 'integer'], 'etapa_codigo' => ['nullable', 'string', 'max:20'], 'valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'probabilidade' => ['nullable', 'numeric', 'min:0', 'max:100'], 'data_fecho_prevista' => ['nullable', 'date_format:Y-m-d'], 'responsavel' => ['nullable', 'string', 'max:100'],
            'origem' => ['nullable', 'string', 'max:100'], 'notas' => ['nullable', 'string', 'max:4000'], 'itens' => ['nullable', 'array', 'max:200'],
            'itens.*.produto_id' => ['nullable', 'integer'], 'itens.*.descricao' => ['nullable', 'string', 'max:1000'], 'itens.*.quantidade' => ['nullable', 'numeric', 'min:0'],
            'itens.*.preco' => ['nullable', 'numeric', 'min:0'], 'itens.*.taxa' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $existente = $oportunidade ? OportunidadeVendaCRM::query()->findOrFail($oportunidade) : null;
        $res = $this->oportunidades->guardar($d, $existente);

        return $existente ? RespostaApi::sucesso($res, 'Oportunidade gravada.') : RespostaApi::criado($res, 'Oportunidade criada.');
    }

    public function eliminar(int $oportunidade): JsonResponse
    {
        $this->exigir('crm_editar');
        $this->oportunidades->eliminar(OportunidadeVendaCRM::query()->findOrFail($oportunidade));

        return RespostaApi::sucesso(null, 'Oportunidade eliminada.');
    }

    public function moverEtapa(Request $r, int $oportunidade): JsonResponse
    {
        $this->exigir('crm_editar');
        $d = $r->validate(['etapa_codigo' => ['required', 'string', 'max:20'], 'motivo_perda' => ['nullable', 'string', 'max:255'], 'concorrente' => ['nullable', 'string', 'max:255'],
            'notas_perda' => ['nullable', 'string', 'max:2000']]);
        $res = $this->oportunidades->moverEtapa(OportunidadeVendaCRM::query()->findOrFail($oportunidade), $d['etapa_codigo'], $d);

        return RespostaApi::sucesso($res, $res['tarefas'] ? "Etapa alterada: {$res['tarefas']} tarefa(s) automática(s) criada(s)." : 'Etapa alterada.');
    }

    public function quadro(Request $r, int $funil): JsonResponse
    {
        $this->exigir('crm_pipeline_view', 'crm_editar', 'crm_converter');
        $f = $r->validate(['responsavel' => ['nullable', 'string', 'max:100'], 'texto' => ['nullable', 'string', 'max:100'], 'so_risco' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->oportunidades->quadro($this->config->funil($funil), $f), 'Quadro do funil.');
    }

    public function conversao(Request $r, int $oportunidade): JsonResponse
    {
        $this->exigir('crm_converter');
        $d = $r->validate(['tipo_documento' => ['required', Rule::in(RegrasCRM::DOCUMENTOS_CONVERSAO)]]);

        return RespostaApi::sucesso($this->oportunidades->conversao(OportunidadeVendaCRM::query()->findOrFail($oportunidade), $d['tipo_documento']),
            'Dados para emitir o documento em /api/vendas/documentos.');
    }

    public function ligarVenda(Request $r, int $oportunidade): JsonResponse
    {
        $this->exigir('crm_converter');
        $d = $r->validate(['venda_id' => ['required', 'integer']]);

        return RespostaApi::sucesso($this->oportunidades->ligarVenda(OportunidadeVendaCRM::query()->findOrFail($oportunidade), (int) $d['venda_id']),
            'Documento ligado à oportunidade.');
    }

    // ───────────── Previsão e indicadores ─────────────

    public function previsao(Request $r): JsonResponse
    {
        $this->exigir('crm_previsao_view');
        $f = $r->validate(['funil_vendas_crm_id' => ['nullable', 'integer'], 'meses' => ['nullable', 'integer', 'min:1', 'max:24'], 'responsavel' => ['nullable', 'string', 'max:100']]);

        return RespostaApi::sucesso($this->indicadores->previsao(isset($f['funil_vendas_crm_id']) ? (int) $f['funil_vendas_crm_id'] : null, (int) ($f['meses'] ?? 6),
            $f['responsavel'] ?? null), 'Previsão de vendas.');
    }

    public function indicadores(Request $r): JsonResponse
    {
        $this->exigir('crm_previsao_view');
        $f = $r->validate(['funil_vendas_crm_id' => ['nullable', 'integer'], 'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso($this->indicadores->kpis(isset($f['funil_vendas_crm_id']) ? (int) $f['funil_vendas_crm_id'] : null, $f['de'] ?? null, $f['ate'] ?? null),
            'Indicadores do funil.');
    }
}
