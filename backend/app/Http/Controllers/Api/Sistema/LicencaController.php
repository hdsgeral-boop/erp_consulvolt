<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoLicenca;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * /api/licenca — licença da empresa activa e cobrança por plano e tempo (ADR-074).
 *
 * Estado e planos activos: qualquer utilizador da empresa (alimentam o aviso do cabeçalho). Pedir renovação, ver e cancelar
 * pedidos: `config_licenca_gerir`. Confirmar pagamento: só o super-administrador do tenant da plataforma. O catálogo de planos
 * gere-se na app da plataforma (/api/plataforma/planos); aqui o cliente só lê os planos activos.
 * Estas rotas funcionam também com a licença expirada (é assim que se renova); ver ResolverEmpresaAtiva.
 */
final class LicencaController extends Controller
{
    public function __construct(private readonly ServicoLicenca $licenca) {}

    public function estado(): JsonResponse
    {
        return RespostaApi::sucesso($this->licenca->estado(), 'Licença da empresa.');
    }

    public function planos(Request $request): JsonResponse
    {
        $todos = $this->ePlataforma($request) && $request->boolean('todos');

        return RespostaApi::sucesso($this->licenca->planos($todos), 'Planos de licença.');
    }

    public function subscricoes(): JsonResponse
    {
        $this->exigir('config_licenca_gerir');

        return RespostaApi::sucesso($this->licenca->subscricoes(), 'Pedidos de licença da empresa.');
    }

    public function renovar(Request $request): JsonResponse
    {
        $this->exigir('config_licenca_gerir');
        $d = $request->validate(['plano_id' => ['required', 'integer'], 'meses' => ['required', 'integer', 'between:1,36']]);

        return RespostaApi::criado($this->licenca->pedirRenovacao((int) $d['plano_id'], (int) $d['meses'], $request->user()), 'Pedido de renovação criado. Aguarda confirmação do pagamento.');
    }

    public function cancelar(int $subscricao): JsonResponse
    {
        $this->exigir('config_licenca_gerir');

        return RespostaApi::sucesso($this->licenca->cancelar($subscricao), 'Pedido cancelado.');
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

    /**
     * Confirmação de pagamentos: só o super-administrador do tenant da plataforma.
     * Um super-administrador de cliente não pode aprovar a própria subscrição.
     */
    private function exigirSuperAdministrador(Request $request): void
    {
        if (! $this->ePlataforma($request)) {
            throw new AccessDeniedHttpException;
        }
    }

    private function ePlataforma(Request $request): bool
    {
        return $request->user()?->ePlataforma() ?? false;
    }
}
