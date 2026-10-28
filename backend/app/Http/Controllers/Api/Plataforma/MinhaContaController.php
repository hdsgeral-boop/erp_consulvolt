<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Mail\TicketCriadoMail;
use App\Mail\TicketRespostaMail;
use App\Models\AnexoSuporte;
use App\Models\Empresa;
use App\Models\MensagemSuporte;
use App\Models\PlanoLicenca;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Services\Plataforma\ServicoAnexosSuporte;
use App\Services\Plataforma\ServicoLimitesPlano;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\DefinicoesPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Minha conta" do cliente SaaS (middleware `admin_cliente`): plano e uso, planos disponíveis, suporte com conversa e
 * nome do sistema. O cliente é sempre o do utilizador autenticado; um ticket de outro cliente responde 404.
 */
final class MinhaContaController extends Controller
{
    public function __construct(private readonly ServicoLimitesPlano $limites, private readonly ServicoAnexosSuporte $anexos) {}

    public function resumo(Request $r): JsonResponse
    {
        $c = $this->cliente($r);

        return RespostaApi::sucesso([
            'cliente' => ['id' => $c->id, 'nome' => $c->nome, 'slug' => $c->slug, 'estado' => $c->estado, 'nome_sistema' => $c->nome_sistema],
            'uso' => $this->limites->uso($c),
            'empresas' => Empresa::query()->where('tenant_id', $c->id)->orderBy('id')->get()->map(fn (Empresa $e) => [
                'id' => $e->id, 'nome' => $e->nome, 'licenca_origem' => $e->licenca_origem,
                'licenca_fim' => $e->licenca_fim?->toDateString(), 'expirada' => $e->licencaExpirada(),
            ])->all(),
        ], 'Conta obtida com sucesso.');
    }

    public function identidade(Request $r): JsonResponse
    {
        $d = $r->validate(['nome_sistema' => ['nullable', 'string', 'max:80']]);
        $c = $this->cliente($r);
        $c->update(['nome_sistema' => $d['nome_sistema'] ?: null]);

        return RespostaApi::sucesso(['nome_sistema' => $c->nome_sistema], 'Nome do sistema actualizado.');
    }

    /** Planos activos, para comparar com o actual. */
    public function planos(Request $r): JsonResponse
    {
        $actual = $this->cliente($r)->plano;
        $lista = PlanoLicenca::query()->where('ativo', true)->orderBy('ordem')->orderBy('id')->get()->map(fn (PlanoLicenca $p) => [
            'codigo' => $p->codigo, 'nome' => $p->nome, 'descricao' => $p->descricao, 'preco_mensal' => (float) $p->preco_mensal, 'moeda' => $p->moeda,
            'max_empresas' => $p->max_empresas, 'max_utilizadores' => $p->max_utilizadores, 'actual' => $p->codigo === $actual,
        ])->all();

        return RespostaApi::sucesso($lista, 'Planos obtidos com sucesso.');
    }

    public function suporte(Request $r): JsonResponse
    {
        $lista = TicketSuporte::query()->where('tenant_id', $this->cliente($r)->id)->orderByDesc('criado_em')->limit(200)->get()
            ->map(fn (TicketSuporte $t) => [
                'id' => $t->id, 'assunto' => $t->assunto, 'descricao' => $t->descricao, 'estado' => $t->estado,
                'prioridade' => $t->prioridade, 'categoria' => $t->categoria, 'criado_em' => $t->criado_em,
                'csat_nota' => $t->csat_nota, 'csat_comentario' => $t->csat_comentario,
                'pode_avaliar' => in_array($t->estado, [TicketSuporte::RESOLVIDO, TicketSuporte::FECHADO], true) && $t->csat_nota === null,
            ])->all();

        return RespostaApi::sucesso($lista, 'Pedidos de suporte obtidos com sucesso.');
    }

    public function abrirSuporte(Request $r): JsonResponse
    {
        $d = $r->validate([
            'assunto' => ['required', 'string', 'max:200'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'categoria' => ['nullable', Rule::in(TicketSuporte::CATEGORIAS)],
        ]);
        $cliente = $this->cliente($r);
        $t = TicketSuporte::query()->create($d + [
            'tenant_id' => $cliente->id, 'categoria' => 'GERAL', 'prioridade' => 'NORMAL',
            'estado' => TicketSuporte::ABERTO, 'criado_por' => $r->user()->nome_utilizador,
        ]);
        if ($emailSuporte = DefinicoesPlataforma::emailSuporte()) {
            Mail::to($emailSuporte)->send(new TicketCriadoMail($t->id, $cliente->nome, $t->assunto, 'NORMAL', $t->categoria, $t->descricao));
        }

        return RespostaApi::criado(['id' => $t->id, 'assunto' => $t->assunto, 'estado' => 'ABERTO'], 'Pedido de suporte aberto.');
    }

    public function mensagens(Request $r, int $ticket): JsonResponse
    {
        return RespostaApi::sucesso(ConversaSuportePlataformaController::mensagens($this->ticket($r, $ticket)), 'Mensagens obtidas com sucesso.');
    }

    public function responder(Request $r, int $ticket): JsonResponse
    {
        $t = $this->ticket($r, $ticket);
        $d = $r->validate([
            'mensagem' => ['required', 'string', 'max:5000'],
            'ficheiros' => ['sometimes', 'array', 'max:'.ServicoAnexosSuporte::MAX_FICHEIROS],
            'ficheiros.*' => ['file', 'max:'.ServicoAnexosSuporte::TAMANHO_MAXIMO_KB],
        ]);
        $m = MensagemSuporte::query()->create([
            'ticket_id' => $t->id, 'autor_tipo' => MensagemSuporte::CLIENTE, 'autor_nome' => $r->user()->nome_utilizador, 'mensagem' => $d['mensagem'],
        ]);
        foreach ($r->file('ficheiros', []) as $ficheiro) {
            $this->anexos->guardar($m, $ficheiro);
        }
        if (in_array($t->estado, [TicketSuporte::RESOLVIDO, TicketSuporte::FECHADO], true)) {
            $t->update(['estado' => TicketSuporte::ABERTO]);
        }
        if ($emailSuporte = DefinicoesPlataforma::emailSuporte()) {
            Mail::to($emailSuporte)->send(new TicketRespostaMail($t->id, $t->assunto, 'Equipa de suporte', $r->user()->nome_utilizador, $d['mensagem'], rtrim((string) config('app.url'), '/').'/admin/suporte'));
        }

        return RespostaApi::criado(ConversaSuportePlataformaController::apresentar($m->refresh()), 'Mensagem enviada.');
    }

    /** Anexo de uma mensagem da conversa do próprio cliente (nunca de outro tenant). */
    public function baixarAnexo(Request $r, int $anexo): Response
    {
        $a = AnexoSuporte::query()->with('mensagem')->findOrFail($anexo);
        $this->ticket($r, (int) $a->mensagem->ticket_id);   // 404 se o ticket não é deste cliente
        $f = $this->anexos->conteudo($a) ?? throw new NotFoundHttpException('Anexo não encontrado.');

        return response($f['conteudo'], 200, [
            'Content-Type' => $f['mime'], 'Content-Disposition' => 'inline; filename="anexo-'.$a->id.'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /** Avaliação de satisfação (CSAT): só depois de resolvido/fechado, e só uma vez. */
    public function avaliacao(Request $r, int $ticket): JsonResponse
    {
        $t = $this->ticket($r, $ticket);
        if (! in_array($t->estado, [TicketSuporte::RESOLVIDO, TicketSuporte::FECHADO], true)) {
            throw new ErroNegocio('Só pode avaliar um pedido já resolvido ou fechado.', 'TICKET_NAO_RESOLVIDO', 422);
        }
        if ($t->csat_nota !== null) {
            throw new ErroNegocio('Este pedido já foi avaliado.', 'TICKET_JA_AVALIADO', 422);
        }
        $d = $r->validate(['nota' => ['required', 'integer', 'between:1,5'], 'comentario' => ['nullable', 'string', 'max:1000']]);
        $t->update(['csat_nota' => $d['nota'], 'csat_comentario' => $d['comentario'] ?? null]);

        return RespostaApi::sucesso(['csat_nota' => $t->csat_nota, 'csat_comentario' => $t->csat_comentario], 'Obrigado pela sua avaliação.');
    }

    private function cliente(Request $r): Tenant
    {
        return Tenant::query()->findOrFail((int) $r->user()->tenant_id);
    }

    private function ticket(Request $r, int $id): TicketSuporte
    {
        return TicketSuporte::query()->where('tenant_id', (int) $r->user()->tenant_id)->findOrFail($id);
    }
}