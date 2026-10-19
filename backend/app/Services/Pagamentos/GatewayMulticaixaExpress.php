<?php

namespace App\Services\Pagamentos;

use App\Exceptions\ErroNegocio;
use App\Models\SubscricaoEmpresa;
use App\Models\TransacaoGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Driver para Multicaixa Express (Angola).
 * Envia notificação de autorização PIN diretamente para o telemóvel do cliente associado ao Express.
 */
class GatewayMulticaixaExpress implements ContratoGatewayPagamento
{
    public function iniciar(SubscricaoEmpresa $subscricao, array $parametros = []): ResultadoIniciacao
    {
        $telemovel = preg_replace('/\D/', '', (string) ($parametros['telemovel'] ?? ''));
        // Formato angolano: 9 dígitos (ex: 923 000 000) ou com indicativo 2449...
        if (str_starts_with($telemovel, '244') && strlen($telemovel) === 12) {
            $telemovel = substr($telemovel, 3);
        }
        if (strlen($telemovel) !== 9 || ! str_starts_with($telemovel, '9')) {
            throw new ErroNegocio('O número de telemóvel deve ser um número válido de Angola com 9 dígitos (iniciado por 9).', 'TELEMOVEL_INVALIDO', 422);
        }

        $configs = DB::table('configuracoes_gateway')->pluck('valor', 'chave');
        $merchantId = $configs['mcx_merchant_id'] ?? 'CONSULVOLT';
        $terminalId = $configs['mcx_terminal_id'] ?? '';
        $urlApi = $configs['mcx_url_api'] ?? '';
        $chaveApi = $configs['mcx_chave_api'] ?? '';

        $referenciaExterna = 'MCX-EXP-'.$subscricao->id.'-'.Str::upper(Str::random(6));
        $dadosResposta = null;
        $estado = 'AGUARDANDO_PIN';

        // Se houver URL da API Real configurada, contacta o endpoint da EMIS / GPO / parceiro
        if (! empty($urlApi) && ! empty($chaveApi)) {
            try {
                $resposta = Http::withHeaders([
                    'Authorization' => 'Bearer '.$chaveApi,
                    'Accept' => 'application/json',
                    'X-Merchant-Id' => $merchantId,
                ])->timeout(12)->post(rtrim($urlApi, '/').'/express/cobranca', [
                    'merchant_id' => $merchantId,
                    'terminal_id' => $terminalId,
                    'telemovel' => '244'.$telemovel,
                    'valor' => (float) $subscricao->valor,
                    'moeda' => $subscricao->moeda ?: 'AOA',
                    'referencia' => $subscricao->referencia,
                    'id_transacao' => $referenciaExterna,
                ]);

                if ($resposta->successful()) {
                    $dadosResposta = $resposta->json();
                    if (! empty($dadosResposta['transaction_id'])) {
                        $referenciaExterna = (string) $dadosResposta['transaction_id'];
                    }
                } else {
                    $dadosResposta = $resposta->json() ?: ['codigo_http' => $resposta->status()];
                }
            } catch (\Throwable $e) {
                $dadosResposta = ['erro_comunicacao' => $e->getMessage()];
            }
        }

        // Regista a transação na base de dados
        TransacaoGateway::query()->create([
            'subscricao_id' => $subscricao->id,
            'gateway' => TransacaoGateway::GATEWAY_EXPRESS,
            'referencia_externa' => $referenciaExterna,
            'telemovel' => $telemovel,
            'valor' => $subscricao->valor,
            'moeda' => $subscricao->moeda ?: 'AOA',
            'estado' => TransacaoGateway::ESTADO_PENDENTE,
            'dados_pedido' => [
                'subscricao_ref' => $subscricao->referencia,
                'telemovel' => $telemovel,
            ],
            'dados_resposta' => $dadosResposta,
        ]);

        return new ResultadoIniciacao(
            sucesso: true,
            gateway: TransacaoGateway::GATEWAY_EXPRESS,
            referenciaExterna: $referenciaExterna,
            telemovel: $telemovel,
            instrucoes: "Pedido de pagamento enviado para o número (+244) {$telemovel}. Por favor, abra a aplicação Multicaixa Express no seu telemóvel e introduza o seu PIN para confirmar o pagamento.",
            estado: $estado,
            metadados: [
                'valor' => (float) $subscricao->valor,
                'moeda' => $subscricao->moeda ?: 'AOA',
                'tempo_limite_segundos' => 120,
            ]
        );
    }

    public function processarWebhook(array $payload, array $headers = []): ResultadoConfirmacao
    {
        $referenciaExterna = $payload['id_transacao'] ?? $payload['transaction_id'] ?? $payload['id'] ?? null;
        $referenciaSubscricao = $payload['referencia'] ?? $payload['reference'] ?? null;
        $valor = isset($payload['amount']) ? (float) $payload['amount'] : (isset($payload['valor']) ? (float) $payload['valor'] : null);
        $status = strtolower((string) ($payload['status'] ?? $payload['estado'] ?? ''));

        $transacao = TransacaoGateway::query()
            ->when($referenciaExterna, fn ($q) => $q->where('referencia_externa', $referenciaExterna))
            ->when(! $referenciaExterna && $referenciaSubscricao, function ($q) use ($referenciaSubscricao) {
                $subId = SubscricaoEmpresa::query()->where('referencia', $referenciaSubscricao)->value('id');
                $q->where('subscricao_id', $subId);
            })
            ->first();

        if (! $transacao) {
            return new ResultadoConfirmacao(sucesso: false, mensagem: 'Transação Multicaixa Express correspondente não encontrada.');
        }

        $sucesso = in_array($status, ['success', 'sucesso', 'pago', 'paid', 'approved', 'concluido'], true);

        return new ResultadoConfirmacao(
            sucesso: $sucesso,
            subscricaoId: $transacao->subscricao_id,
            referenciaExterna: $referenciaExterna ?: $transacao->referencia_externa,
            valorPago: $valor ?: (float) $transacao->valor,
            mensagem: $sucesso ? 'Pagamento Multicaixa Express aprovado com sucesso.' : 'Notificação Express recebida com estado '.$status,
            dadosOriginais: $payload,
        );
    }
}
