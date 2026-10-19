<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Services\Vendas\Agt\ServicoWebhookAgt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/agt/webhook/{token} — notificações da AGT para a empresa dona do endereço (sem sessão: autenticado pela
 * assinatura HMAC, carimbo e nonce — ServicoWebhookAgt). Responde de imediato; o processamento corre na fila.
 */
final class WebhookAgtController extends Controller
{
    public function __invoke(Request $request, string $token, ServicoWebhookAgt $webhook): JsonResponse
    {
        $r = $webhook->receber($token, (string) $request->getContent(), $request->header('X-AGT-Carimbo'), $request->header('X-AGT-Nonce'),
            $request->header('X-AGT-Assinatura'), $request->ip());

        return response()->json($r['corpo'], $r['estado']);
    }
}
