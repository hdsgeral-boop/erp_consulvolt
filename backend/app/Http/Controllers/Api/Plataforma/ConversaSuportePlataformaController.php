<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\MensagemSuporte;
use App\Models\TicketSuporte;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Conversa de um ticket, do lado da plataforma (middleware `plataforma`). Responder passa um ticket aberto a em curso. */
final class ConversaSuportePlataformaController extends Controller
{
    public function index(TicketSuporte $ticket): JsonResponse
    {
        return RespostaApi::sucesso(self::mensagens($ticket), 'Mensagens obtidas com sucesso.');
    }

    public function responder(Request $r, TicketSuporte $ticket): JsonResponse
    {
        $d = $r->validate(['mensagem' => ['required', 'string', 'max:5000']]);
        $m = MensagemSuporte::query()->create([
            'ticket_id' => $ticket->id, 'autor_tipo' => MensagemSuporte::PLATAFORMA,
            'autor_nome' => $r->user()->nome_utilizador, 'mensagem' => $d['mensagem'],
        ]);
        if ($ticket->estado === TicketSuporte::ABERTO) {
            $ticket->update(['estado' => TicketSuporte::EM_CURSO]);
        }

        return RespostaApi::criado(self::apresentar($m->refresh()), 'Resposta enviada.');
    }

    /** @return list<array<string, mixed>> */
    public static function mensagens(TicketSuporte $ticket): array
    {
        return MensagemSuporte::query()->where('ticket_id', $ticket->id)->orderBy('id')->get()->map(fn (MensagemSuporte $m) => self::apresentar($m))->all();
    }

    /** @return array<string, mixed> */
    public static function apresentar(MensagemSuporte $m): array
    {
        return ['id' => $m->id, 'autor_tipo' => $m->autor_tipo, 'autor_nome' => $m->autor_nome, 'mensagem' => $m->mensagem, 'criado_em' => $m->criado_em];
    }
}