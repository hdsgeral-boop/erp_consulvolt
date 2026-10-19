<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tickets de suporte dos clientes, geridos pela plataforma (middleware `plataforma`). */
final class SuporteController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['estado' => ['nullable', Rule::in(TicketSuporte::ESTADOS)]]);
        $nomes = Tenant::query()->pluck('nome', 'id');

        $tickets = TicketSuporte::query()
            ->when($d['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->orderByRaw("CASE estado WHEN 'ABERTO' THEN 0 WHEN 'EM_CURSO' THEN 1 WHEN 'RESOLVIDO' THEN 2 ELSE 3 END")
            ->orderByDesc('criado_em')
            ->limit(300)
            ->get()
            ->map(fn (TicketSuporte $t) => $this->apresentar($t, $nomes));

        return RespostaApi::sucesso($tickets->all(), 'Tickets obtidos com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')],
            'assunto' => ['required', 'string', 'max:200'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'prioridade' => ['nullable', Rule::in(TicketSuporte::PRIORIDADES)],
            'categoria' => ['nullable', Rule::in(TicketSuporte::CATEGORIAS)],
        ]);

        $ticket = TicketSuporte::create($d + [
            'prioridade' => 'NORMAL', 'categoria' => 'GERAL',
            'estado' => TicketSuporte::ABERTO, 'criado_por' => $r->user()->nome_utilizador,
        ]);

        return RespostaApi::criado($this->apresentar($ticket->refresh(), Tenant::query()->pluck('nome', 'id')), 'Ticket criado com sucesso.');
    }

    public function atualizar(Request $r, TicketSuporte $ticket): JsonResponse
    {
        $d = $r->validate([
            'assunto' => ['sometimes', 'required', 'string', 'max:200'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'estado' => ['sometimes', Rule::in(TicketSuporte::ESTADOS)],
            'prioridade' => ['sometimes', Rule::in(TicketSuporte::PRIORIDADES)],
            'categoria' => ['sometimes', Rule::in(TicketSuporte::CATEGORIAS)],
        ]);
        $ticket->update($d);

        return RespostaApi::sucesso($this->apresentar($ticket->refresh(), Tenant::query()->pluck('nome', 'id')), 'Ticket actualizado.');
    }

    public function eliminar(TicketSuporte $ticket): JsonResponse
    {
        $ticket->delete();

        return RespostaApi::sucesso(null, 'Ticket eliminado.');
    }

    /** @return array<string, mixed> */
    private function apresentar(TicketSuporte $t, $nomes): array
    {
        return [
            'id' => $t->id, 'tenant_id' => $t->tenant_id, 'cliente' => $nomes[$t->tenant_id] ?? null,
            'assunto' => $t->assunto, 'descricao' => $t->descricao, 'estado' => $t->estado,
            'prioridade' => $t->prioridade, 'categoria' => $t->categoria, 'criado_por' => $t->criado_por, 'criado_em' => $t->criado_em,
        ];
    }
}