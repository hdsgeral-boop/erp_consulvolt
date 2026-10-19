<?php

namespace App\Services\Pagamentos;

use App\Models\SubscricaoEmpresa;
use App\Models\TransacaoGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Driver para Pagamento por Referência Multicaixa (Angola).
 * Gera Entidade, Referência de 9 dígitos e Data Limite.
 * Comunica com a API Real se `ref_url_api` estiver configurada,
 * ou gera a referência localmente pronta a receber os valores/callbacks da API Real.
 */
class GatewayReferenciaMulticaixa implements ContratoGatewayPagamento
{
    public function iniciar(SubscricaoEmpresa $subscricao, array $parametros = []): ResultadoIniciacao
    {
        $configs = DB::table('configuracoes_gateway')->pluck('valor', 'chave');

        $entidade = $configs['ref_entidade'] ?? '00123';
        $subentidade = $configs['ref_subentidade'] ?? '';
        $urlApi = $configs['ref_url_api'] ?? '';
        $chaveApi = $configs['ref_chave_api'] ?? '';

        // Gera uma referência de 9 dígitos única
        $numeroBase = str_pad((string) $subscricao->id, 4, '0', STR_PAD_LEFT);
        $random = str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT);
        $check = str_pad((string) (($subscricao->id * 7 + 13) % 89 + 10), 2, '0', STR_PAD_LEFT);
        $referenciaRaw = $subentidade ? substr($subentidade.$numeroBase.$random, 0, 9) : $numeroBase.$random.$check;
        $referenciaFormatada = substr($referenciaRaw, 0, 3).' '.substr($referenciaRaw, 3, 3).' '.substr($referenciaRaw, 6, 3);

        $referenciaExterna = 'REF-MCX-'.$subscricao->id.'-'.Str::upper(Str::random(6));
        $dadosResposta = null;

        // Se houver URL da API Real configurada, contacta a API do Gateway
        if (! empty($urlApi) && ! empty($chaveApi)) {
            try {
                $resposta = Http::withHeaders([
                    'Authorization' => 'Bearer '.$chaveApi,
                    'Accept' => 'application/json',
                ])->timeout(10)->post(rtrim($urlApi, '/').'/referencias', [
                    'referencia' => $referenciaRaw,
                    'valor' => (float) $subscricao->valor,
                    'moeda' => $subscricao->moeda ?: 'AOA',
                    'identificador_origem' => $subscricao->referencia,
                    'validade' => now()->addDays(3)->toIso8601String(),
                ]);

                if ($resposta->successful()) {
                    $dadosResposta = $resposta->json();
                    if (! empty($dadosResposta['referencia'])) {
                        $referenciaRaw = (string) $dadosResposta['referencia'];
                        $referenciaFormatada = substr($referenciaRaw, 0, 3).' '.substr($referenciaRaw, 3, 3).' '.substr($referenciaRaw, 6, 3);
                    }
                    if (! empty($dadosResposta['entidade'])) {
                        $entidade = (string) $dadosResposta['entidade'];
                    }
                    if (! empty($dadosResposta['id'])) {
                        $referenciaExterna = (string) $dadosResposta['id'];
                    }
                }
            } catch (\Throwable $e) {
                // Em caso de falha transitória ou de homologação, mantém os dados gerados
                $dadosResposta = ['erro_comunicacao' => $e->getMessage()];
            }
        }

        // Regista a transação na base de dados
        TransacaoGateway::query()->create([
            'subscricao_id' => $subscricao->id,
            'gateway' => TransacaoGateway::GATEWAY_REFERENCIA,
            'referencia_externa' => $referenciaExterna,
            'entidade' => $entidade,
            'referencia' => $referenciaRaw,
            'valor' => $subscricao->valor,
            'moeda' => $subscricao->moeda ?: 'AOA',
            'estado' => TransacaoGateway::ESTADO_PENDENTE,
            'dados_pedido' => [
                'subscricao_ref' => $subscricao->referencia,
                'meses' => $subscricao->meses,
            ],
            'dados_resposta' => $dadosResposta,
        ]);

        return new ResultadoIniciacao(
            sucesso: true,
            gateway: TransacaoGateway::GATEWAY_REFERENCIA,
            referenciaExterna: $referenciaExterna,
            entidade: $entidade,
            referencia: $referenciaFormatada,
            instrucoes: "Dirija-se a um Caixa Multicaixa ou utilize o Internet Banking: Escolha 'Pagamentos' > 'Pagamento por Referência', insira a Entidade {$entidade} e a Referência {$referenciaFormatada}.",
            estado: TransacaoGateway::ESTADO_PENDENTE,
            metadados: [
                'valor' => (float) $subscricao->valor,
                'moeda' => $subscricao->moeda ?: 'AOA',
                'validade' => now()->addDays(3)->format('d/m/Y H:i'),
            ]
        );
    }

    public function processarWebhook(array $payload, array $headers = []): ResultadoConfirmacao
    {
        // Aceita formatos padrão de webhook (ex: Proxypay / EMIS / standard JSON)
        $referencia = $payload['referencia'] ?? $payload['reference'] ?? $payload['custom_fields']['referencia'] ?? null;
        $referenciaExterna = $payload['id'] ?? $payload['transaction_id'] ?? null;
        $valor = isset($payload['amount']) ? (float) $payload['amount'] : (isset($payload['valor']) ? (float) $payload['valor'] : null);
        $status = strtolower((string) ($payload['status'] ?? $payload['estado'] ?? 'paid'));

        if (! $referencia && ! $referenciaExterna) {
            return new ResultadoConfirmacao(sucesso: false, mensagem: 'Referência não identificada no payload do webhook.');
        }

        $transacao = TransacaoGateway::query()
            ->when($referencia, fn ($q) => $q->where('referencia', preg_replace('/\s+/', '', (string) $referencia)))
            ->when($referenciaExterna && ! $referencia, fn ($q) => $q->where('referencia_externa', $referenciaExterna))
            ->first();

        if (! $transacao) {
            return new ResultadoConfirmacao(sucesso: false, mensagem: 'Transação correspondente não encontrada na base de dados.');
        }

        $sucesso = in_array($status, ['paid', 'pago', 'success', 'concluido', 'settled'], true);

        return new ResultadoConfirmacao(
            sucesso: $sucesso,
            subscricaoId: $transacao->subscricao_id,
            referenciaExterna: $referenciaExterna ?: $transacao->referencia_externa,
            valorPago: $valor ?: (float) $transacao->valor,
            mensagem: $sucesso ? 'Pagamento por referência confirmado com sucesso.' : 'Notificação recebida com estado '.$status,
            dadosOriginais: $payload,
        );
    }
}
