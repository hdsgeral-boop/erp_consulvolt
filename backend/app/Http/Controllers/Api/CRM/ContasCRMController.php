<?php

namespace App\Http\Controllers\Api\CRM;

use App\Http\Controllers\Controller;
use App\Models\ContaCRM;
use App\Models\ContactoCRM;
use App\Models\OportunidadeVendaCRM;
use App\Services\CRM\ServicoContasCRM;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/crm/contas — contas (prospects e clientes), contactos, ficha 360º e passagem a cliente. */
final class ContasCRMController extends Controller
{
    public function __construct(private readonly ServicoContasCRM $contas) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...ConfiguracaoCRMController::VER);
        $f = $r->validate(['tipo' => ['nullable', 'in:PROSPECT,CLIENTE'], 'pesquisa' => ['nullable', 'string', 'max:100'], 'responsavel' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $pagina = ContaCRM::query()->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))->when($f['responsavel'] ?? null, fn ($q, $x) => $q->where('responsavel', $x))
            ->when($f['pesquisa'] ?? null, function ($q, $p) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $p).'%';
                $q->where(fn ($s) => $s->where('nome', 'ilike', $termo)->orWhere('nif', 'ilike', $termo)->orWhere('email', 'ilike', $termo)->orWhere('setor', 'ilike', $termo));
            })->orderBy('nome')->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));
        $ids = $pagina->getCollection()->pluck('id');
        $opps = OportunidadeVendaCRM::query()->whereIn('conta_crm_id', $ids)->get(['id', 'conta_crm_id', 'estado', 'valor'])->groupBy('conta_crm_id');
        $pagina->setCollection($pagina->getCollection()->map(function ($c) use ($opps) {
            $os = $opps[$c->id] ?? collect();
            $abertas = $os->where('estado', 'ABERTA');

            return $c->toArray() + ['oportunidades_abertas' => $abertas->count(), 'valor_aberto' => $abertas->reduce(fn ($s, $o) => bcadd($s, (string) $o->valor, 2), '0.00'),
                'ganhas' => $os->where('estado', 'GANHA')->count(), 'financeiro' => $c->terceiro_id ? array_intersect_key($this->contas->financeiro($c->terceiro_id),
                    array_flip(['em_aberto', 'em_atraso', 'max_dias_atraso', 'n_atrasadas'])) : null];
        }));

        return RespostaApi::paginado($pagina, null, 'Contas do CRM.');
    }

    public function show(int $conta): JsonResponse
    {
        $this->exigir(...ConfiguracaoCRMController::VER);

        return RespostaApi::sucesso($this->contas->ficha360(ContaCRM::query()->findOrFail($conta)), 'Ficha 360º da conta.');
    }

    public function guardar(Request $r, ?int $conta = null): JsonResponse
    {
        $this->exigir('crm_editar');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'nif' => ['nullable', 'string', 'max:30'], 'setor' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:150'], 'telefone' => ['nullable', 'string', 'max:50'], 'morada' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'string', 'max:255'], 'origem' => ['nullable', 'string', 'max:100'], 'responsavel' => ['nullable', 'string', 'max:100'],
            'notas' => ['nullable', 'string', 'max:4000'],
            'terceiro_id' => ['nullable', 'integer', Rule::exists('terceiros', 'id')->where('empresa_id', $empresa)->whereNull('eliminado_em')]]);
        $existente = $conta ? ContaCRM::query()->findOrFail($conta) : null;
        $res = $this->contas->guardar($d, $existente);

        return $existente ? RespostaApi::sucesso($res, 'Conta gravada.') : RespostaApi::criado($res, 'Conta criada.');
    }

    /** Conta do CRM de um cliente existente (cria-a se ainda não houver). */
    public function doCliente(Request $r): JsonResponse
    {
        $this->exigir('crm_editar');
        $d = $r->validate(['terceiro_id' => ['required', 'integer']]);

        return RespostaApi::sucesso($this->contas->contaDoCliente((int) $d['terceiro_id']), 'Conta do cliente.');
    }

    public function converterEmCliente(Request $r, int $conta): JsonResponse
    {
        $this->exigir('crm_converter', 'crm_editar');
        $d = $r->validate(['codigo_conta' => ['required', 'string', 'max:20']]);

        return RespostaApi::sucesso($this->contas->converterEmCliente(ContaCRM::query()->findOrFail($conta), $d['codigo_conta']), 'O prospect passou a cliente.');
    }

    // ───────────── Contactos ─────────────

    public function contactos(int $conta): JsonResponse
    {
        $this->exigir(...ConfiguracaoCRMController::VER);
        $c = ContaCRM::query()->findOrFail($conta);

        return RespostaApi::sucesso(ContactoCRM::query()->where('conta_crm_id', $c->id)->orderByDesc('principal')->orderBy('nome')->get(), 'Contactos da conta.');
    }

    public function guardarContacto(Request $r, int $conta, ?int $contacto = null): JsonResponse
    {
        $this->exigir('crm_editar');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'cargo' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'string', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:50'], 'principal' => ['nullable', 'boolean']]);
        $c = ContaCRM::query()->findOrFail($conta);
        $existente = $contacto ? ContactoCRM::query()->where('conta_crm_id', $c->id)->findOrFail($contacto) : null;
        $res = $this->contas->guardarContacto($d, $c, $existente);

        return $existente ? RespostaApi::sucesso($res, 'Contacto gravado.') : RespostaApi::criado($res, 'Contacto criado.');
    }

    public function eliminarContacto(int $conta, int $contacto): JsonResponse
    {
        $this->exigir('crm_editar');
        ContactoCRM::query()->where('conta_crm_id', $conta)->findOrFail($contacto)->delete();   // eliminação lógica: as actividades mantêm a ligação

        return RespostaApi::sucesso(null, 'Contacto eliminado.');
    }
}
