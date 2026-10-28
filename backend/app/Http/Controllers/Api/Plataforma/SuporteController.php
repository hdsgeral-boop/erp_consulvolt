<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tickets de suporte dos clientes, reaproveitado por /api/plataforma/suporte/* (super-administrador, vê tudo) e
 * /api/suporte/tickets/* (agente, ADR-077: só as categorias que o seu perfil de especialização concede — ver
 * TicketSuporte::CHAVE_POR_CATEGORIA). Quem decide quem entra é sempre o middleware da rota (`plataforma`/`suporte`);
 * aqui só se restringe QUAIS categorias, nunca o acesso em si.
 */
final class SuporteController extends Controller
{
    public function __construct(private readonly ServicoPermissoes $permissoes) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'estado' => ['nullable', Rule::in(TicketSuporte::ESTADOS)],
            'agente_id' => ['nullable', 'integer'],
            'pesquisa' => ['nullable', 'string', 'max:100'],
        ]);
        $nomes = Tenant::query()->pluck('nome', 'id');
        $agentes = Utilizador::query()->whereIn('id', TicketSuporte::query()->whereNotNull('agente_id')->distinct()->pluck('agente_id'))->pluck('nome_utilizador', 'id');
        $categorias = $this->categoriasPermitidas($r);
        $termo = isset($d['pesquisa']) && trim($d['pesquisa']) !== '' ? '%'.addcslashes(trim($d['pesquisa']), '%_\\').'%' : null;

        $tickets = TicketSuporte::query()
            ->when($categorias !== null, fn ($q) => $q->whereIn('categoria', $categorias))
            ->when($d['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($d['agente_id'] ?? null, fn ($q, $a) => $q->where('agente_id', $a))
            ->when($termo, fn ($q) => $q->where(fn ($w) => $w->where('assunto', 'ilike', $termo)->orWhere('descricao', 'ilike', $termo)))
            ->orderByRaw("CASE estado WHEN 'ABERTO' THEN 0 WHEN 'EM_CURSO' THEN 1 WHEN 'RESOLVIDO' THEN 2 ELSE 3 END")
            ->orderByDesc('criado_em')
            ->limit(300)
            ->get()
            ->map(fn (TicketSuporte $t) => $this->apresentar($t, $nomes, $agentes));

        return RespostaApi::sucesso($tickets->all(), 'Tickets obtidos com sucesso.');
    }

    /** Agentes que já têm pelo menos um ticket atribuído, mais a lista completa para o selector de atribuição. */
    public function agentes(): JsonResponse
    {
        return RespostaApi::sucesso(
            Utilizador::query()->where('tenant_id', (int) config('erp.dominio.plataforma_tenant_id'))->where('papel', Utilizador::PAPEL_AGENTE_SUPORTE)
                ->orderBy('nome_utilizador')->get(['id', 'nome_utilizador'])->map(fn (Utilizador $u) => ['id' => $u->id, 'nome' => $u->nome_utilizador])->all(),
            'Agentes de suporte obtidos com sucesso.',
        );
    }

    /** null = sem filtro (super-administrador, vê tudo); senão, as categorias cujas chaves o perfil do agente concede. */
    private function categoriasPermitidas(Request $r): ?array
    {
        $u = $r->user();
        if ($u->eSuperAdministrador()) {
            return null;
        }

        return array_values(array_unique(array_keys(array_filter(
            TicketSuporte::CHAVE_POR_CATEGORIA,
            fn ($chave) => $this->permissoes->tem($u, $chave),
        ))));
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

        $prioridade = $d['prioridade'] ?? 'NORMAL';
        $ticket = TicketSuporte::create($d + [
            'prioridade' => $prioridade, 'categoria' => 'GERAL',
            'estado' => TicketSuporte::ABERTO, 'criado_por' => $r->user()->nome_utilizador,
            'prazo_sla' => now()->addHours(TicketSuporte::SLA_HORAS[$prioridade]),
        ]);

        return RespostaApi::criado($this->apresentar($ticket->refresh(), Tenant::query()->pluck('nome', 'id')), 'Ticket criado com sucesso.');
    }

    public function atualizar(Request $r, TicketSuporte $ticket): JsonResponse
    {
        $this->exigir(TicketSuporte::CHAVE_POR_CATEGORIA[$ticket->categoria] ?? TicketSuporte::CHAVE_POR_CATEGORIA['GERAL']);
        $d = $r->validate([
            'assunto' => ['sometimes', 'required', 'string', 'max:200'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'estado' => ['sometimes', Rule::in(TicketSuporte::ESTADOS)],
            'prioridade' => ['sometimes', Rule::in(TicketSuporte::PRIORIDADES)],
            'categoria' => ['sometimes', Rule::in(TicketSuporte::CATEGORIAS)],
            'agente_id' => ['sometimes', 'nullable', 'integer', Rule::exists('utilizadores', 'id')],
        ]);
        $ticket->update($d);
        $agentes = $ticket->agente_id ? Utilizador::query()->where('id', $ticket->agente_id)->pluck('nome_utilizador', 'id') : null;

        return RespostaApi::sucesso($this->apresentar($ticket->refresh(), Tenant::query()->pluck('nome', 'id'), $agentes), 'Ticket actualizado.');
    }

    public function eliminar(TicketSuporte $ticket): JsonResponse
    {
        $ticket->delete();

        return RespostaApi::sucesso(null, 'Ticket eliminado.');
    }

    /** @return array<string, mixed> */
    private function apresentar(TicketSuporte $t, $nomes, $agentes = null): array
    {
        return [
            'id' => $t->id, 'tenant_id' => $t->tenant_id, 'cliente' => $nomes[$t->tenant_id] ?? null,
            'assunto' => $t->assunto, 'descricao' => $t->descricao, 'estado' => $t->estado,
            'prioridade' => $t->prioridade, 'categoria' => $t->categoria, 'criado_por' => $t->criado_por, 'criado_em' => $t->criado_em,
            'agente_id' => $t->agente_id, 'agente_nome' => $t->agente_id ? ($agentes[$t->agente_id] ?? null) : null,
            'prazo_sla' => $t->prazo_sla, 'em_atraso' => $t->emAtraso(), 'primeira_resposta_em' => $t->primeira_resposta_em,
            'csat_nota' => $t->csat_nota, 'csat_comentario' => $t->csat_comentario,
        ];
    }
}