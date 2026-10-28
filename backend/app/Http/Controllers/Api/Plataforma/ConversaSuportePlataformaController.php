<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Mail\TicketRespostaMail;
use App\Models\AnexoSuporte;
use App\Models\MensagemSuporte;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoAnexosSuporte;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Conversa de um ticket, reaproveitada por /api/plataforma/suporte/* (super-administrador) e
 * /api/suporte/tickets/* (agente — ADR-077: só a categoria que o seu perfil de especialização concede).
 * Responder passa um ticket aberto a em curso.
 */
final class ConversaSuportePlataformaController extends Controller
{
    public function __construct(private readonly ContextoTenant $tenant, private readonly ServicoAnexosSuporte $anexos) {}

    public function index(TicketSuporte $ticket): JsonResponse
    {
        $this->exigir(TicketSuporte::CHAVE_POR_CATEGORIA[$ticket->categoria] ?? TicketSuporte::CHAVE_POR_CATEGORIA['GERAL']);

        return RespostaApi::sucesso(self::mensagens($ticket), 'Mensagens obtidas com sucesso.');
    }

    public function responder(Request $r, TicketSuporte $ticket): JsonResponse
    {
        $this->exigir(TicketSuporte::CHAVE_POR_CATEGORIA[$ticket->categoria] ?? TicketSuporte::CHAVE_POR_CATEGORIA['GERAL']);
        $d = $r->validate([
            'mensagem' => ['required', 'string', 'max:5000'],
            'ficheiros' => ['sometimes', 'array', 'max:'.ServicoAnexosSuporte::MAX_FICHEIROS],
            'ficheiros.*' => ['file', 'max:'.ServicoAnexosSuporte::TAMANHO_MAXIMO_KB],
        ]);
        $m = MensagemSuporte::query()->create([
            'ticket_id' => $ticket->id, 'autor_tipo' => MensagemSuporte::PLATAFORMA,
            'autor_nome' => $r->user()->nome_utilizador, 'mensagem' => $d['mensagem'],
        ]);
        foreach ($r->file('ficheiros', []) as $ficheiro) {
            $this->anexos->guardar($m, $ficheiro);
        }
        $primeiraResposta = $ticket->primeira_resposta_em === null;
        $ticket->update(['estado' => $ticket->estado === TicketSuporte::ABERTO ? TicketSuporte::EM_CURSO : $ticket->estado]
            + ($primeiraResposta ? ['primeira_resposta_em' => now()] : []));
        $this->notificarCliente($ticket, $d['mensagem'], $r->user()->nome_utilizador);

        return RespostaApi::criado(self::apresentar($m->refresh()), 'Resposta enviada.');
    }

    /** Bytes do anexo (autenticado: a mesma permissão de ver a conversa do ticket). */
    public function baixarAnexo(AnexoSuporte $anexo): Response
    {
        $ticket = $anexo->mensagem->ticket;
        $this->exigir(TicketSuporte::CHAVE_POR_CATEGORIA[$ticket->categoria] ?? TicketSuporte::CHAVE_POR_CATEGORIA['GERAL']);
        $f = $this->anexos->conteudo($anexo) ?? throw new NotFoundHttpException('Anexo não encontrado.');

        return response($f['conteudo'], 200, [
            'Content-Type' => $f['mime'], 'Content-Disposition' => 'inline; filename="anexo-'.$anexo->id.'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function mensagens(TicketSuporte $ticket): array
    {
        return MensagemSuporte::query()->where('ticket_id', $ticket->id)->with('anexos')->orderBy('id')->get()->map(fn (MensagemSuporte $m) => self::apresentar($m))->all();
    }

    /** @return array<string, mixed> */
    public static function apresentar(MensagemSuporte $m): array
    {
        return [
            'id' => $m->id, 'autor_tipo' => $m->autor_tipo, 'autor_nome' => $m->autor_nome, 'mensagem' => $m->mensagem, 'criado_em' => $m->criado_em,
            'anexos' => $m->anexos->map(fn (AnexoSuporte $a) => ['id' => $a->id, 'nome' => $a->nome_original, 'tamanho_bytes' => $a->tamanho_bytes, 'tipo_mime' => $a->tipo_mime])->all(),
        ];
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