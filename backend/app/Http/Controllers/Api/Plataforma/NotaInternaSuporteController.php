<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\NotaInternaSuporte;
use App\Models\TicketSuporte;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notas internas de um ticket: só a equipa de suporte as vê (nunca o cliente, nunca /api/minha-conta/suporte/*). */
final class NotaInternaSuporteController extends Controller
{
    public function index(TicketSuporte $ticket): JsonResponse
    {
        $this->exigir(TicketSuporte::CHAVE_POR_CATEGORIA[$ticket->categoria] ?? TicketSuporte::CHAVE_POR_CATEGORIA['GERAL']);

        return RespostaApi::sucesso(
            NotaInternaSuporte::query()->where('ticket_id', $ticket->id)->orderBy('id')
                ->get()->map(fn (NotaInternaSuporte $n) => $this->apresentar($n))->all(),
            'Notas internas obtidas com sucesso.',
        );
    }

    public function store(Request $r, TicketSuporte $ticket): JsonResponse
    {
        $this->exigir(TicketSuporte::CHAVE_POR_CATEGORIA[$ticket->categoria] ?? TicketSuporte::CHAVE_POR_CATEGORIA['GERAL']);
        $d = $r->validate(['nota' => ['required', 'string', 'max:2000']]);
        $n = NotaInternaSuporte::create(['ticket_id' => $ticket->id, 'autor_nome' => $r->user()->nome_utilizador, 'nota' => $d['nota']]);

        return RespostaApi::criado($this->apresentar($n), 'Nota interna adicionada.');
    }

    /** @return array<string, mixed> */
    private function apresentar(NotaInternaSuporte $n): array
    {
        return ['id' => $n->id, 'autor_nome' => $n->autor_nome, 'nota' => $n->nota, 'criado_em' => $n->criado_em];
    }
}
