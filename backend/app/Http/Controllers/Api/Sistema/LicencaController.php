<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Plataforma\ConversaSuportePlataformaController;
use App\Mail\TicketCriadoMail;
use App\Mail\TicketRespostaMail;
use App\Models\AnexoComprovativoPagamento;
use App\Models\AnexoSuporte;
use App\Models\MensagemSuporte;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Services\Pagamentos\ServicoComprovativosPagamento;
use App\Services\Plataforma\ServicoAnexosSuporte;
use App\Services\Sistema\ServicoLicenca;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\DefinicoesPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /api/licenca — licença da empresa activa e cobrança por plano e tempo (ADR-074).
 *
 * Estado e planos activos: qualquer utilizador da empresa (alimentam o aviso do cabeçalho). Pedir renovação, ver e cancelar
 * pedidos: `config_licenca_gerir`. Confirmar pagamento e gerir o catálogo de planos: só super-administradores.
 * Estas rotas funcionam também com a licença expirada (é assim que se renova); ver ResolverEmpresaAtiva.
 *
 * Suporte (abrir/responder/avaliar pedidos): qualquer utilizador autenticado da empresa — não só o administrador,
 * ao contrário de `/minha-conta/suporte` (reservado ao administrador, por estar ao lado de dados de plano e
 * pagamento). Mesmos tickets (tickets_suporte, por tenant_id), só com uma porta de entrada mais aberta.
 */
final class LicencaController extends Controller
{
    public function __construct(
        private readonly ServicoLicenca $licenca,
        private readonly ServicoAnexosSuporte $anexos,
        private readonly ServicoComprovativosPagamento $comprovativos,
    ) {}

    public function estado(): JsonResponse
    {
        return RespostaApi::sucesso($this->licenca->estado(), 'Licença da empresa.');
    }

    public function planos(Request $request): JsonResponse
    {
        $todos = $request->user()->eSuperAdministrador() && $request->boolean('todos');

        return RespostaApi::sucesso($this->licenca->planos($todos), 'Planos de licença.');
    }

    /** GET /api/sistema/planos-publicos (sem autenticação) */
    public function planosPublicos(): JsonResponse
    {
        return RespostaApi::sucesso($this->licenca->planos(false), 'Planos de subscrição.');
    }

    /**
     * GET /api/sistema/definicoes-publicas (sem autenticação): dados não sensíveis para a landing page e o registo
     * — hoje só a duração do período de demonstração, para a promessa comercial nunca divergir do que o registo concede.
     */
    public function definicoesPublicas(): JsonResponse
    {
        return RespostaApi::sucesso([
            'dias_teste_padrao' => DefinicoesPlataforma::diasTestePadrao(),
            // Rótulos em português dos módulos que planosPublicos() devolve em 'modulos' (códigos) — a landing page
            // traduz sem duplicar a lista à mão (erp.planos_catalogo.modulos, o mesmo catálogo que ModulosPlano usa).
            'modulos_catalogo' => (array) config('erp.planos_catalogo.modulos'),
        ], 'Definições públicas da plataforma.');
    }

    public function criarPlano(Request $request): JsonResponse
    {
        $this->exigirSuperAdministrador($request);

        return RespostaApi::criado($this->licenca->gravarPlano(null, $this->validarPlano($request)), 'Plano criado.');
    }

    public function actualizarPlano(Request $request, int $plano): JsonResponse
    {
        $this->exigirSuperAdministrador($request);
        $modelo = PlanoLicenca::query()->findOrFail($plano);

        return RespostaApi::sucesso($this->licenca->gravarPlano($modelo, $this->validarPlano($request, $modelo->id)), 'Plano actualizado.');
    }

    public function subscricoes(): JsonResponse
    {
        $this->exigir('config_licenca_gerir');

        return RespostaApi::sucesso($this->licenca->subscricoes(), 'Pedidos de licença da empresa.');
    }

    public function renovar(Request $request): JsonResponse
    {
        $this->exigir('config_licenca_gerir');
        $d = $request->validate([
            'plano_id' => ['required', 'integer'], 'meses' => ['required', 'integer', 'between:1,36'],
            'cupom_codigo' => ['nullable', 'string', 'max:30'],
        ]);

        return RespostaApi::criado(
            $this->licenca->pedirRenovacao((int) $d['plano_id'], (int) $d['meses'], $request->user(), $d['cupom_codigo'] ?? null),
            'Pedido de renovação criado. Aguarda confirmação do pagamento.',
        );
    }

    /** Pré-visualização de cupão para o checkout — nunca bloqueia o fluxo (devolve "valido: false" em vez de erro). */
    public function validarCupom(Request $request): JsonResponse
    {
        $this->exigir('config_licenca_gerir');
        $d = $request->validate([
            'codigo' => ['required', 'string', 'max:30'], 'plano_id' => ['required', 'integer'],
            'ciclo_codigo' => ['required', 'string', 'max:20'],
        ]);

        return RespostaApi::sucesso($this->licenca->validarCupom($d['codigo'], (int) $d['plano_id'], $d['ciclo_codigo']), 'Cupão validado.');
    }

    public function cancelar(int $subscricao): JsonResponse
    {
        $this->exigir('config_licenca_gerir');

        return RespostaApi::sucesso($this->licenca->cancelar($subscricao), 'Pedido cancelado.');
    }

    /** Anexa o comprovativo de transferência bancária a um pedido pendente da própria empresa. */
    public function anexarComprovativo(Request $request, int $subscricao): JsonResponse
    {
        $this->exigir('config_licenca_gerir');
        $s = SubscricaoEmpresa::query()->findOrFail($subscricao);
        $request->validate(['comprovativo' => ['required', 'file', 'max:'.ServicoComprovativosPagamento::TAMANHO_MAXIMO_KB]]);

        $a = $this->comprovativos->anexar($s, $request->file('comprovativo'), $request->user(), $request->ip());

        return RespostaApi::criado(['id' => $a->id, 'nome_original' => $a->nome_original, 'criado_em' => $a->criado_em], 'Comprovativo anexado. Aguarda validação.');
    }

    /** Comprovativo da própria empresa (nunca de outra — PertenceEmpresa). */
    public function baixarComprovativo(int $anexo): Response
    {
        $this->exigir('config_licenca_gerir');
        $a = AnexoComprovativoPagamento::query()->findOrFail($anexo);
        $f = $this->comprovativos->conteudo($a) ?? throw new NotFoundHttpException('Comprovativo não encontrado.');

        return response($f['conteudo'], 200, [
            'Content-Type' => $f['mime'], 'Content-Disposition' => 'inline; filename="comprovativo-'.$a->id.'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public function confirmarPagamento(Request $request, int $subscricao): JsonResponse
    {
        $this->exigirSuperAdministrador($request);
        $d = $request->validate(['metodo' => ['nullable', 'string', 'max:30'], 'notas' => ['nullable', 'string', 'max:1000']]);

        return RespostaApi::sucesso(
            $this->licenca->confirmarPagamento($subscricao, $d['metodo'] ?? null, $d['notas'] ?? null, $request->user()),
            'Pagamento confirmado. Licença prolongada.',
        );
    }

    public function suporte(Request $request): JsonResponse
    {
        $lista = TicketSuporte::query()->where('tenant_id', (int) $request->user()->tenant_id)->orderByDesc('criado_em')->limit(200)->get()
            ->map(fn (TicketSuporte $t) => [
                'id' => $t->id, 'assunto' => $t->assunto, 'descricao' => $t->descricao, 'estado' => $t->estado,
                'prioridade' => $t->prioridade, 'categoria' => $t->categoria, 'criado_em' => $t->criado_em,
                'csat_nota' => $t->csat_nota, 'csat_comentario' => $t->csat_comentario,
                'pode_avaliar' => in_array($t->estado, [TicketSuporte::RESOLVIDO, TicketSuporte::FECHADO], true) && $t->csat_nota === null,
            ])->all();

        return RespostaApi::sucesso($lista, 'Pedidos de suporte obtidos com sucesso.');
    }

    public function abrirSuporte(Request $request): JsonResponse
    {
        $d = $request->validate([
            'assunto' => ['required', 'string', 'max:200'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'categoria' => ['nullable', Rule::in(TicketSuporte::CATEGORIAS)],
        ]);
        $tenantId = (int) $request->user()->tenant_id;
        $t = TicketSuporte::query()->create($d + [
            'tenant_id' => $tenantId, 'categoria' => $d['categoria'] ?? 'GERAL', 'prioridade' => 'NORMAL',
            'estado' => TicketSuporte::ABERTO, 'criado_por' => $request->user()->nome_utilizador,
        ]);
        if ($emailSuporte = DefinicoesPlataforma::emailSuporte()) {
            $nomeCliente = Tenant::query()->find($tenantId)?->nome ?? '';
            Mail::to($emailSuporte)->send(new TicketCriadoMail($t->id, $nomeCliente, $t->assunto, 'NORMAL', $t->categoria, $t->descricao));
        }

        return RespostaApi::criado(['id' => $t->id, 'assunto' => $t->assunto, 'estado' => 'ABERTO'], 'Pedido de suporte aberto.');
    }

    public function mensagens(Request $request, int $ticket): JsonResponse
    {
        return RespostaApi::sucesso(ConversaSuportePlataformaController::mensagens($this->ticketDoTenant($request, $ticket)), 'Mensagens obtidas com sucesso.');
    }

    public function responder(Request $request, int $ticket): JsonResponse
    {
        $t = $this->ticketDoTenant($request, $ticket);
        $d = $request->validate([
            'mensagem' => ['required', 'string', 'max:5000'],
            'ficheiros' => ['sometimes', 'array', 'max:'.ServicoAnexosSuporte::MAX_FICHEIROS],
            'ficheiros.*' => ['file', 'max:'.ServicoAnexosSuporte::TAMANHO_MAXIMO_KB],
        ]);
        $m = MensagemSuporte::query()->create([
            'ticket_id' => $t->id, 'autor_tipo' => MensagemSuporte::CLIENTE, 'autor_nome' => $request->user()->nome_utilizador, 'mensagem' => $d['mensagem'],
        ]);
        foreach ($request->file('ficheiros', []) as $ficheiro) {
            $this->anexos->guardar($m, $ficheiro);
        }
        if (in_array($t->estado, [TicketSuporte::RESOLVIDO, TicketSuporte::FECHADO], true)) {
            $t->update(['estado' => TicketSuporte::ABERTO]);
        }
        if ($emailSuporte = DefinicoesPlataforma::emailSuporte()) {
            Mail::to($emailSuporte)->send(new TicketRespostaMail($t->id, $t->assunto, 'Equipa de suporte', $request->user()->nome_utilizador, $d['mensagem'], rtrim((string) config('app.url'), '/').'/admin/suporte'));
        }

        return RespostaApi::criado(ConversaSuportePlataformaController::apresentar($m->refresh()), 'Mensagem enviada.');
    }

    /** Avaliação de satisfação (CSAT): só depois de resolvido/fechado, e só uma vez. */
    public function avaliacao(Request $request, int $ticket): JsonResponse
    {
        $t = $this->ticketDoTenant($request, $ticket);
        if (! in_array($t->estado, [TicketSuporte::RESOLVIDO, TicketSuporte::FECHADO], true)) {
            throw new ErroNegocio('Só pode avaliar um pedido já resolvido ou fechado.', 'TICKET_NAO_RESOLVIDO', 422);
        }
        if ($t->csat_nota !== null) {
            throw new ErroNegocio('Este pedido já foi avaliado.', 'TICKET_JA_AVALIADO', 422);
        }
        $d = $request->validate(['nota' => ['required', 'integer', 'between:1,5'], 'comentario' => ['nullable', 'string', 'max:1000']]);
        $t->update(['csat_nota' => $d['nota'], 'csat_comentario' => $d['comentario'] ?? null]);

        return RespostaApi::sucesso(['csat_nota' => $t->csat_nota, 'csat_comentario' => $t->csat_comentario], 'Obrigado pela sua avaliação.');
    }

    /** Anexo de uma mensagem da conversa do próprio tenant (nunca de outro). */
    public function baixarAnexo(Request $request, int $anexo): Response
    {
        $a = AnexoSuporte::query()->with('mensagem')->findOrFail($anexo);
        $this->ticketDoTenant($request, (int) $a->mensagem->ticket_id);
        $f = $this->anexos->conteudo($a) ?? throw new NotFoundHttpException('Anexo não encontrado.');

        return response($f['conteudo'], 200, [
            'Content-Type' => $f['mime'], 'Content-Disposition' => 'inline; filename="anexo-'.$a->id.'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, max-age=600',
        ]);
    }

    private function ticketDoTenant(Request $request, int $id): TicketSuporte
    {
        return TicketSuporte::query()->where('tenant_id', (int) $request->user()->tenant_id)->findOrFail($id);
    }

    private function exigirSuperAdministrador(Request $request): void
    {
        if (! $request->user()?->eSuperAdministrador()) {
            throw new AccessDeniedHttpException;
        }
    }

    /** @return array<string, mixed> */
    private function validarPlano(Request $request, ?int $ignorar = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/', Rule::unique('planos_licenca', 'codigo')->ignore($ignorar)],
            'nome' => ['required', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'preco_mensal' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'moeda' => ['required', 'string', 'size:3'],
            'max_utilizadores' => ['nullable', 'integer', 'min:1'],
            'max_empresas' => ['nullable', 'integer', 'min:1'],
            'ativo' => ['required', 'boolean'],
            'ordem' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
