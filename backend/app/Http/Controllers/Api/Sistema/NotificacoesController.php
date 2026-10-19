<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoNotificacoes;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestão de notificações do sistema da empresa ativa (alertas de operações de utilizadores, eventos e avisos).
 */
final class NotificacoesController extends Controller
{
    public function __construct(
        private ServicoNotificacoes $servico,
        private ContextoEmpresa $contexto,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->contexto->obrigatorio();
        $user = $request->user();
        $limite = min(100, max(5, $request->integer('limite', 50)));

        $filtros = [
            'modulo' => $request->query('modulo'),
            'tipo' => $request->query('tipo'),
            'origem' => $request->query('origem'),
            'apenas_nao_lidas' => $request->query('apenas_nao_lidas'),
        ];

        return RespostaApi::sucesso(
            $this->servico->resumo($empresaId, $user, $limite, $filtros),
            'Notificações e alertas da empresa ativa obtidos com sucesso.'
        );
    }

    public function marcarLida(int $id): JsonResponse
    {
        $empresaId = $this->contexto->obrigatorio();
        $this->servico->marcarLida($id, $empresaId);

        return RespostaApi::sucesso(null, 'Notificação marcada como lida.');
    }

    public function marcarTodasLidas(): JsonResponse
    {
        $empresaId = $this->contexto->obrigatorio();
        $total = $this->servico->marcarTodasLidas($empresaId);

        return RespostaApi::sucesso(['atualizadas' => $total], 'Todas as notificações foram marcadas como lidas.');
    }

    public function criarTeste(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->eSuperAdministrador() && ! in_array($user?->papel, ['SUPER_ADMINISTRADOR', 'ADMINISTRADOR'], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Apenas administradores podem criar notificações de teste.');
        }

        $empresaId = $this->contexto->obrigatorio();

        $d = $request->validate([
            'tipo' => ['nullable', 'string', 'in:INFO,SUCESSO,AVISO,URGENTE'],
            'categoria' => ['nullable', 'string', 'max:30'],
            'titulo' => ['required', 'string', 'max:200'],
            'mensagem' => ['required', 'string'],
            'link' => ['nullable', 'string', 'max:255'],
        ]);

        $notificacao = $this->servico->criar(
            empresaId: $empresaId,
            tipo: $d['tipo'] ?? 'INFO',
            categoria: $d['categoria'] ?? 'SISTEMA',
            titulo: $d['titulo'],
            mensagem: $d['mensagem'],
            link: $d['link'] ?? null,
            utilizadorId: $user?->getKey(),
            autorNome: $user?->nome_completo ?? $user?->nome_utilizador,
        );

        return RespostaApi::criado($notificacao, 'Notificação de teste criada com sucesso.');
    }
}
