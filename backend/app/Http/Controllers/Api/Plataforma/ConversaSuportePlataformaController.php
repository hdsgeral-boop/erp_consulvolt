<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Mail\TicketRespostaMail;
use App\Models\MensagemSuporte;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** Conversa de um ticket, do lado da plataforma (middleware `plataforma`). Responder passa um ticket aberto a em curso. */
final class ConversaSuportePlataformaController extends Controller
{
    public function __construct(private readonly ContextoTenant $tenant) {}

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
        $this->notificarCliente($ticket, $d['mensagem'], $r->user()->nome_utilizador);

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

    /**
     * Quem criou o ticket, ou — sem email ou sem o encontrar — o primeiro administrador do cliente com email.
     * `utilizadores` tem RLS por tenant_id (IsolamentoRls::TENANT_DIRETAS): fora do contexto do próprio cliente a
     * consulta ficaria vazia, daí o executarComo (o pedido corre no tenant da plataforma, não no do cliente do ticket).
     */
    private function notificarCliente(TicketSuporte $ticket, string $mensagem, string $autorNome): void
    {
        $destinatario = $this->tenant->executarComo($ticket->tenant_id, function () use ($ticket) {
            $autor = Utilizador::query()->where('tenant_id', $ticket->tenant_id)->where('nome_utilizador', $ticket->criado_por)->whereNotNull('email')->first();

            return $autor ?? Utilizador::query()->where('tenant_id', $ticket->tenant_id)
                ->whereIn('papel', [Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_SUPER_ADMINISTRADOR])
                ->whereNotNull('email')->orderBy('id')->first();
        });

        if ($destinatario?->email) {
            Mail::to($destinatario->email)->send(new TicketRespostaMail(
                $ticket->id, $ticket->assunto, $destinatario->nome_completo ?: $destinatario->nome_utilizador, $autorNome, $mensagem, config('app.url'),
            ));
        }
    }
}