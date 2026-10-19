<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Models\SubscricaoEmpresa;
use App\Models\Utilizador;
use App\Services\Pagamentos\ProcessadorPagamentos;
use App\Services\Sistema\ServicoLicenca;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gestão de métodos de pagamento, gateways angolanos (Referência e Multicaixa Express) e webhooks.
 */
final class GatewayPagamentoController extends Controller
{
    public function __construct(
        private ProcessadorPagamentos $processador,
        private ServicoLicenca $licenca,
    ) {}

    /** GET /api/plataforma/pagamentos/config */
    public function obterConfig(Request $request): JsonResponse
    {
        $this->exigirSuperAdmin($request);

        return RespostaApi::sucesso($this->processador->obterConfiguracoes(), 'Configurações de pagamento obtidas.');
    }

    /** PUT /api/plataforma/pagamentos/config */
    public function guardarConfig(Request $request): JsonResponse
    {
        $this->exigirSuperAdmin($request);

        $dados = $request->validate([
            'referencia' => ['nullable', 'array'],
            'referencia.ativo' => ['nullable', 'boolean'],
            'referencia.entidade' => ['nullable', 'string', 'max:20'],
            'referencia.subentidade' => ['nullable', 'string', 'max:20'],
            'referencia.chave_api' => ['nullable', 'string', 'max:255'],
            'referencia.url_api' => ['nullable', 'string', 'max:255'],

            'express' => ['nullable', 'array'],
            'express.ativo' => ['nullable', 'boolean'],
            'express.merchant_id' => ['nullable', 'string', 'max:80'],
            'express.terminal_id' => ['nullable', 'string', 'max:80'],
            'express.chave_api' => ['nullable', 'string', 'max:255'],
            'express.url_api' => ['nullable', 'string', 'max:255'],

            'dados_bancarios' => ['nullable', 'array'],
            'dados_bancarios.banco' => ['nullable', 'string', 'max:100'],
            'dados_bancarios.titular' => ['nullable', 'string', 'max:150'],
            'dados_bancarios.iban' => ['nullable', 'string', 'max:50'],
            'dados_bancarios.swift' => ['nullable', 'string', 'max:30'],
        ]);

        $this->processador->guardarConfiguracoes($dados);

        return RespostaApi::sucesso($this->processador->obterConfiguracoes(), 'Configurações guardadas com sucesso.');
    }

    /** POST /api/licenca/subscricoes/{id}/iniciar-pagamento */
    public function iniciarPagamento(Request $request, int $id): JsonResponse
    {
        if (! $request->user()?->eSuperAdministrador() && ! in_array($request->user()?->papel, ['SUPER_ADMINISTRADOR', 'ADMINISTRADOR'], true)) {
            throw new AccessDeniedHttpException('Apenas administradores podem iniciar pagamentos de subscrição.');
        }

        $d = $request->validate([
            'metodo' => ['required', 'string', 'in:REFERENCIA,EXPRESS'],
            'telemovel' => ['required_if:metodo,EXPRESS', 'nullable', 'string'],
        ]);

        $resultado = $this->processador->iniciar($id, $d['metodo'], $d);

        return RespostaApi::sucesso($resultado->toArray(), 'Pagamento iniciado com sucesso.');
    }

    /** POST /api/licenca/subscricoes/{id}/simular-confirmacao (apenas super-administrador) */
    public function simularConfirmacao(Request $request, int $id): JsonResponse
    {
        $this->exigirSuperAdmin($request);

        $subscricao = SubscricaoEmpresa::query()->findOrFail($id);
        $resultado = $this->licenca->confirmarPagamento(
            id: $subscricao->id,
            metodo: 'SIMULACAO_GATEWAY',
            notas: 'Confirmado manualmente via simulação de gateway',
            utilizador: $request->user()
        );

        return RespostaApi::sucesso($resultado, 'Pagamento simulado e licença prolongada com sucesso.');
    }

    /** POST /api/pagamentos/webhook/{gateway} — público para receção de callbacks da API Real */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        $payload = $request->all();
        $headers = $request->headers->all();

        $res = $this->processador->processarWebhook($gateway, $payload, $headers);

        return response()->json($res, $res['sucesso'] ? 200 : 400);
    }

    private function exigirSuperAdmin(Request $request): void
    {
        if (! $request->user()?->eSuperAdministrador()) {
            throw new AccessDeniedHttpException('Apenas super-administradores podem gerir métodos de pagamento.');
        }
    }
}
