<?php

namespace App\Http\Controllers\Api\CRM;

use App\Http\Controllers\Controller;
use App\Models\AtividadeComercialCRM;
use App\Services\CRM\RegrasCRM;
use App\Services\CRM\ServicoAtividadesCRM;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/crm — actividades comerciais, agenda, emails (preparar/enviar) e campanhas. */
final class AtividadesCRMController extends Controller
{
    public function __construct(private readonly ServicoAtividadesCRM $atividades) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...ConfiguracaoCRMController::VER);
        $f = $r->validate(['oportunidade_crm_id' => ['nullable', 'integer'], 'conta_crm_id' => ['nullable', 'integer'], 'responsavel' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', Rule::in(array_keys(RegrasCRM::TIPOS_ATIVIDADE))], 'estado' => ['nullable', 'in:PENDENTES,VENCIDAS,CONCLUIDAS'],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $pendente = fn ($q) => $q->where(fn ($x) => $x->whereNull('concluida')->orWhere('concluida', false));
        $q = AtividadeComercialCRM::query()->with(['contaCrm:id,nome', 'oportunidadeCrm:id,titulo'])
            ->when($f['oportunidade_crm_id'] ?? null, fn ($q, $v) => $q->where('oportunidade_crm_id', $v))->when($f['conta_crm_id'] ?? null, fn ($q, $v) => $q->where('conta_crm_id', $v))
            ->when($f['responsavel'] ?? null, fn ($q, $v) => $q->where('responsavel', $v))->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when(($f['estado'] ?? null) === 'PENDENTES', $pendente)
            ->when(($f['estado'] ?? null) === 'VENCIDAS', fn ($q) => $pendente($q)->where('data_prevista', '<', RegrasCRM::hoje()))
            ->when(($f['estado'] ?? null) === 'CONCLUIDAS', fn ($q) => $q->where('concluida', true))
            ->when($f['de'] ?? null, fn ($q, $v) => $q->where('data_prevista', '>=', $v))->when($f['ate'] ?? null, fn ($q, $v) => $q->where('data_prevista', '<=', $v))
            ->orderByDesc('data_prevista')->orderByDesc('id');
        $pagina = $q->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));
        $hoje = RegrasCRM::hoje();
        $pagina->setCollection($pagina->getCollection()->map(fn ($a) => $a->toArray() + ['vencida' => ! $a->concluida && $a->data_prevista && $a->data_prevista->toDateString() < $hoje]));

        return RespostaApi::paginado($pagina, null, 'Actividades comerciais.');
    }

    public function agenda(Request $r): JsonResponse
    {
        $this->exigir('crm_agenda_view', 'crm_pipeline_view', 'crm_editar');
        $f = $r->validate(['responsavel' => ['nullable', 'string', 'max:100'], 'dias' => ['nullable', 'integer', 'min:0', 'max:365']]);

        return RespostaApi::sucesso($this->atividades->agenda($f['responsavel'] ?? null, (int) ($f['dias'] ?? 7)), 'Agenda comercial.');
    }

    public function guardar(Request $r, ?int $atividade = null): JsonResponse
    {
        $this->exigir('crm_editar');
        $d = $r->validate(['tipo' => ['nullable', Rule::in(array_keys(RegrasCRM::TIPOS_ATIVIDADE))], 'titulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:4000'], 'data_prevista' => ['nullable', 'date_format:Y-m-d'], 'responsavel' => ['nullable', 'string', 'max:100'],
            'oportunidade_crm_id' => ['nullable', 'integer'], 'conta_crm_id' => ['nullable', 'integer'], 'contacto_crm_id' => ['nullable', 'integer'],
            'modelo_email_crm_id' => ['nullable', 'integer'], 'concluida' => ['nullable', 'boolean'], 'resultado' => ['nullable', 'string', 'max:50']]);
        $a = $atividade ? AtividadeComercialCRM::query()->findOrFail($atividade) : null;
        $res = $this->atividades->guardar($d, $a);

        return $a ? RespostaApi::sucesso($res, 'Actividade gravada.') : RespostaApi::criado($res, 'Actividade criada.');
    }

    public function concluir(Request $r, int $atividade): JsonResponse
    {
        $this->exigir('crm_editar');
        $d = $r->validate(['resultado' => ['nullable', 'string', 'max:50']]);

        return RespostaApi::sucesso($this->atividades->concluir(AtividadeComercialCRM::query()->findOrFail($atividade), $d['resultado'] ?? null), 'Actividade concluída.');
    }

    public function reabrir(int $atividade): JsonResponse
    {
        $this->exigir('crm_editar');

        return RespostaApi::sucesso($this->atividades->reabrir(AtividadeComercialCRM::query()->findOrFail($atividade)), 'Actividade reaberta.');
    }

    public function eliminar(int $atividade): JsonResponse
    {
        $this->exigir('crm_editar');
        AtividadeComercialCRM::query()->findOrFail($atividade)->delete();

        return RespostaApi::sucesso(null, 'Actividade eliminada.');
    }

    // ───────────── Emails e campanhas ─────────────

    public function prepararEmail(Request $r): JsonResponse
    {
        $this->exigir(...ConfiguracaoCRMController::VER);

        return RespostaApi::sucesso($this->atividades->preparar($this->dadosEmail($r)), 'Mensagem preparada.');
    }

    public function enviarEmail(Request $r): JsonResponse
    {
        $this->exigir('crm_editar');
        $d = $this->dadosEmail($r) + $r->validate(['atividade_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->atividades->enviarEmail($d), 'Email registado no histórico.');
    }

    public function destinatarios(Request $r): JsonResponse
    {
        $this->exigir('crm_campanhas_view', 'crm_campanhas_enviar');
        $f = $r->validate(['modelo_email_crm_id' => ['required', 'integer'], 'tipo' => ['nullable', 'in:PROSPECT,CLIENTE'], 'etapa_codigo' => ['nullable', 'string', 'max:20'],
            'origem' => ['nullable', 'string', 'max:100']]);

        return RespostaApi::sucesso($this->atividades->destinatarios((int) $f['modelo_email_crm_id'], $f), 'Destinatários da campanha.');
    }

    public function enviarCampanha(Request $r): JsonResponse
    {
        $this->exigir('crm_campanhas_enviar');
        $d = $r->validate(['modelo_email_crm_id' => ['required', 'integer'], 'modo' => ['required', 'in:INDIVIDUAL,BCC'], 'destinatarios' => ['required', 'array', 'min:1', 'max:1000'],
            'destinatarios.*.conta_crm_id' => ['required', 'integer'], 'destinatarios.*.contacto_crm_id' => ['nullable', 'integer'],
            'destinatarios.*.oportunidade_crm_id' => ['nullable', 'integer']]);
        $res = $this->atividades->enviarCampanha((int) $d['modelo_email_crm_id'], $d['destinatarios'], $d['modo']);

        return RespostaApi::sucesso($res, "{$res['registadas']} email(s) registado(s) no histórico.");
    }

    private function dadosEmail(Request $r): array
    {
        return $r->validate(['conta_crm_id' => ['nullable', 'integer'], 'contacto_crm_id' => ['nullable', 'integer'], 'oportunidade_crm_id' => ['nullable', 'integer'],
            'modelo_email_crm_id' => ['nullable', 'integer'], 'para' => ['nullable', 'string', 'max:150'], 'assunto' => ['nullable', 'string', 'max:255'],
            'corpo' => ['nullable', 'string', 'max:20000']]);
    }
}
