<?php

namespace App\Services\Pagamentos;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\SubscricaoEmpresa;
use App\Models\TransacaoGateway;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoLicenca;
use App\Services\Sistema\ServicoNotificacoes;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Gestor e orquestrador de gateways de pagamento (Referência Multicaixa, Multicaixa Express e Coordenadas Bancárias).
 */
final class ProcessadorPagamentos
{
    public function __construct(
        private ServicoLicenca $licenca,
        private ServicoNotificacoes $notificacoes,
    ) {}

    /** Devolve a configuração dos métodos de pagamento e dados bancários da plataforma. */
    public function obterConfiguracoes(): array
    {
        $linhas = SegredosGateway::configuracoes();

        return [
            'referencia' => [
                'ativo' => ($linhas['ref_ativo'] ?? 'true') === 'true',
                'entidade' => $linhas['ref_entidade'] ?? '00123',
                'subentidade' => $linhas['ref_subentidade'] ?? '',
                // O segredo nunca sai da API: só o indicador de configuração.
                'chave_api' => '',
                'url_api' => $linhas['ref_url_api'] ?? '',
                'configurado' => ! empty($linhas['ref_chave_api']),
                'tem_chave' => ! empty($linhas['ref_chave_api']),
                'webhook_segredo_configurado' => ! empty($linhas['ref_webhook_segredo']),
            ],
            'express' => [
                'ativo' => ($linhas['mcx_ativo'] ?? 'true') === 'true',
                'merchant_id' => $linhas['mcx_merchant_id'] ?? '',
                'terminal_id' => $linhas['mcx_terminal_id'] ?? '',
                'chave_api' => '',
                'url_api' => $linhas['mcx_url_api'] ?? '',
                'configurado' => ! empty($linhas['mcx_chave_api']),
                'tem_chave' => ! empty($linhas['mcx_chave_api']),
                'webhook_segredo_configurado' => ! empty($linhas['mcx_webhook_segredo']),
            ],
            'dados_bancarios' => [
                'banco' => $linhas['banco_nome'] ?? '',
                'titular' => $linhas['titular_conta'] ?? '',
                'iban' => $linhas['iban'] ?? '',
                'swift' => $linhas['swift_bic'] ?? '',
            ],
        ];
    }

    /** Atualiza as configurações de gateways e dados da empresa (apenas super-administrador). */
    public function guardarConfiguracoes(array $dados): void
    {
        $mapa = [];

        if (isset($dados['referencia'])) {
            if (isset($dados['referencia']['ativo'])) {
                $mapa['ref_ativo'] = $dados['referencia']['ativo'] ? 'true' : 'false';
            }
            if (isset($dados['referencia']['entidade'])) {
                $mapa['ref_entidade'] = (string) $dados['referencia']['entidade'];
            }
            if (isset($dados['referencia']['subentidade'])) {
                $mapa['ref_subentidade'] = (string) $dados['referencia']['subentidade'];
            }
            if (! empty($dados['referencia']['chave_api']) && ! str_starts_with($dados['referencia']['chave_api'], '••••')) {
                $mapa['ref_chave_api'] = (string) $dados['referencia']['chave_api'];
            }
            if (isset($dados['referencia']['url_api'])) {
                $mapa['ref_url_api'] = (string) $dados['referencia']['url_api'];
            }
            if (! empty($dados['referencia']['webhook_segredo'])) {
                $mapa['ref_webhook_segredo'] = (string) $dados['referencia']['webhook_segredo'];
            }
        }

        if (isset($dados['express'])) {
            if (isset($dados['express']['ativo'])) {
                $mapa['mcx_ativo'] = $dados['express']['ativo'] ? 'true' : 'false';
            }
            if (isset($dados['express']['merchant_id'])) {
                $mapa['mcx_merchant_id'] = (string) $dados['express']['merchant_id'];
            }
            if (isset($dados['express']['terminal_id'])) {
                $mapa['mcx_terminal_id'] = (string) $dados['express']['terminal_id'];
            }
            if (! empty($dados['express']['chave_api']) && ! str_starts_with($dados['express']['chave_api'], '••••')) {
                $mapa['mcx_chave_api'] = (string) $dados['express']['chave_api'];
            }
            if (isset($dados['express']['url_api'])) {
                $mapa['mcx_url_api'] = (string) $dados['express']['url_api'];
            }
            if (! empty($dados['express']['webhook_segredo'])) {
                $mapa['mcx_webhook_segredo'] = (string) $dados['express']['webhook_segredo'];
            }
        }

        if (isset($dados['dados_bancarios'])) {
            if (isset($dados['dados_bancarios']['banco'])) {
                $mapa['banco_nome'] = (string) $dados['dados_bancarios']['banco'];
            }
            if (isset($dados['dados_bancarios']['titular'])) {
                $mapa['titular_conta'] = (string) $dados['dados_bancarios']['titular'];
            }
            if (isset($dados['dados_bancarios']['iban'])) {
                $mapa['iban'] = (string) $dados['dados_bancarios']['iban'];
            }
            if (isset($dados['dados_bancarios']['swift'])) {
                $mapa['swift_bic'] = (string) $dados['dados_bancarios']['swift'];
            }
        }

        foreach ($mapa as $k => $v) {
            SegredosGateway::gravar((string) $k, $v); // cifra os segredos
        }
    }

    /** Inicia o pagamento para uma subscrição pendente usando o método selecionado. */
    public function iniciar(int $subscricaoId, string $metodo, array $parametros = []): ResultadoIniciacao
    {
        $subscricao = SubscricaoEmpresa::query()->findOrFail($subscricaoId);
        if ($subscricao->estado !== SubscricaoEmpresa::PENDENTE) {
            throw new ErroNegocio('Apenas pedidos pendentes podem ser pagos.', 'SUBSCRICAO_NAO_PENDENTE', 422);
        }

        $gateway = match (strtoupper($metodo)) {
            'REFERENCIA', 'MCX_REF' => new GatewayReferenciaMulticaixa,
            'EXPRESS', 'MCX_EXPRESS' => new GatewayMulticaixaExpress,
            default => throw new ErroNegocio('Método de pagamento não suportado.', 'METODO_NAO_SUPORTADO', 422),
        };

        return $gateway->iniciar($subscricao, $parametros);
    }

    /** Tolerância (segundos) entre o timestamp assinado do webhook e a hora do servidor (anti-replay). */
    public const JANELA_WEBHOOK_SEGUNDOS = 300;

    /**
     * Processa a confirmação de pagamento recebida via webhook. Exige assinatura HMAC-SHA256 de "{timestamp}.{corpo}"
     * (cabeçalhos X-Webhook-Timestamp, X-Webhook-Signature e X-Webhook-Event-Id), janela temporal, evento único,
     * gateway/valor/moeda iguais aos da subscrição e subscrição ainda PENDENTE. Todas as tentativas ficam auditadas.
     *
     * @return array{sucesso: bool, mensagem: string, http: int, subscricao?: array}
     */
    public function processarWebhook(string $gatewayNome, array $payload, array $headers = [], string $corpoBruto = '', ?string $ip = null): array
    {
        $gateway = match (strtoupper($gatewayNome)) {
            'REFERENCIA', 'MCX_REF' => TransacaoGateway::GATEWAY_REFERENCIA,
            'EXPRESS', 'MCX_EXPRESS' => TransacaoGateway::GATEWAY_EXPRESS,
            default => null,
        };

        $cab = [];
        foreach ($headers as $nome => $valor) {
            $cab[strtolower((string) $nome)] = trim((string) (is_array($valor) ? ($valor[0] ?? '') : $valor));
        }
        $timestamp = $cab['x-webhook-timestamp'] ?? '';
        $eventoId = trim((string) ($cab['x-webhook-event-id'] ?? $payload['event_id'] ?? $payload['evento_id'] ?? '')) ?: null;
        $ctx = [
            'gateway' => $gateway ?? substr(strtoupper($gatewayNome), 0, 30),
            'evento_id' => $eventoId,
            'payload' => $payload,
            'ip' => $ip,
            'timestamp' => ctype_digit($timestamp) ? (int) $timestamp : null,
            'assinatura_valida' => false,
            'subscricao_id' => null,
        ];

        if ($gateway === null) {
            return $this->rejeitarWebhook($ctx, 'Gateway de webhook desconhecido.', 404);
        }

        // 1. Assinatura HMAC (comparação em tempo constante)
        $chaveSegredo = $gateway === TransacaoGateway::GATEWAY_REFERENCIA ? 'ref_webhook_segredo' : 'mcx_webhook_segredo';
        $segredo = (string) (SegredosGateway::configuracoes()[$chaveSegredo] ?? '');
        if ($segredo === '') {
            return $this->rejeitarWebhook($ctx, 'Segredo de webhook não configurado para este gateway.', 403);
        }
        $recebida = strtolower((string) preg_replace('/^sha256=/i', '', $cab['x-webhook-signature'] ?? ''));
        $esperada = hash_hmac('sha256', $timestamp.'.'.$corpoBruto, $segredo);
        if ($timestamp === '' || $recebida === '' || ! hash_equals($esperada, $recebida)) {
            return $this->rejeitarWebhook($ctx, 'Assinatura do webhook inválida.', 401);
        }
        $ctx['assinatura_valida'] = true;

        // 2. Janela temporal (anti-replay)
        if ($ctx['timestamp'] === null || abs(time() - $ctx['timestamp']) > self::JANELA_WEBHOOK_SEGUNDOS) {
            return $this->rejeitarWebhook($ctx, 'Timestamp do webhook fora da janela permitida.', 400);
        }

        // 3. Identificador único do evento (idempotência)
        if ($eventoId === null || strlen($eventoId) > 120) {
            return $this->rejeitarWebhook($ctx, 'Identificador do evento em falta ou inválido.', 400);
        }
        $eventoUnico = $gateway.':'.$eventoId;
        if (DB::table('webhooks_pagamento')->where('evento_unico', $eventoUnico)->exists()) {
            return $this->rejeitarWebhook($ctx, 'Evento já processado.', 409);
        }

        // 4. Identificação da transação, gateway, valor e moeda
        $driver = $gateway === TransacaoGateway::GATEWAY_REFERENCIA ? new GatewayReferenciaMulticaixa : new GatewayMulticaixaExpress;
        $resultado = $driver->processarWebhook($payload, $headers);
        $ctx['subscricao_id'] = $resultado->subscricaoId;

        if (! $resultado->sucesso || ! $resultado->subscricaoId) {
            return $this->rejeitarWebhook($ctx, $resultado->mensagem ?: 'Notificação não validada ou subscrição não identificada.', 422);
        }
        if ($resultado->gateway !== $gateway) {
            return $this->rejeitarWebhook($ctx, 'O gateway do webhook não corresponde ao da transação.', 422);
        }
        if ($resultado->valorPago === null || $resultado->moeda === null || $resultado->moeda === '') {
            return $this->rejeitarWebhook($ctx, 'Valor e moeda são obrigatórios no webhook.', 422);
        }

        $contextoEmpresa = app(ContextoEmpresa::class);
        $subscricao = $contextoEmpresa->semIsolamento(fn () => SubscricaoEmpresa::query()->find($resultado->subscricaoId));
        if (! $subscricao) {
            return $this->rejeitarWebhook($ctx, 'Subscrição não encontrada.', 404);
        }

        try {
            return $contextoEmpresa->executarComo((int) $subscricao->empresa_id, function () use ($subscricao, $gateway, $gatewayNome, $resultado, $ctx, $eventoId, $eventoUnico) {
                return DB::transaction(function () use ($subscricao, $gateway, $gatewayNome, $resultado, $ctx, $eventoId, $eventoUnico) {
                    // Bloqueia a subscrição: duas confirmações concorrentes não prolongam a licença duas vezes
                    $bloqueada = SubscricaoEmpresa::query()->lockForUpdate()->find($subscricao->id);
                    if (! $bloqueada) {
                        throw new ErroNegocio('Subscrição não encontrada.', 'SUBSCRICAO_INEXISTENTE', 404);
                    }
                    if ($bloqueada->estado !== SubscricaoEmpresa::PENDENTE) {
                        throw new ErroNegocio('O pedido já não se encontra pendente.', 'SUBSCRICAO_NAO_PENDENTE', 409);
                    }
                    if (number_format((float) $resultado->valorPago, 2, '.', '') !== number_format((float) $bloqueada->valor, 2, '.', '')) {
                        throw new ErroNegocio('O valor pago não corresponde ao valor da subscrição.', 'VALOR_DIFERENTE', 422);
                    }
                    if (strtoupper((string) $resultado->moeda) !== strtoupper((string) ($bloqueada->moeda ?: 'AOA'))) {
                        throw new ErroNegocio('A moeda paga não corresponde à moeda da subscrição.', 'MOEDA_DIFERENTE', 422);
                    }

                    $superAdmin = Utilizador::query()->where('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)->first()
                        ?? Utilizador::query()->first();
                    if (! $superAdmin) {
                        throw new ErroNegocio('Nenhum utilizador encontrado para registo.', 'SEM_UTILIZADOR', 500);
                    }

                    // Regista o evento aceite (a chave única garante a idempotência mesmo em concorrência)
                    $this->registarWebhook($ctx, 'ACEITE', 'Pagamento confirmado.', 200, $eventoUnico, true);

                    $subscricaoAtualizada = $this->licenca->confirmarPagamento(
                        id: $bloqueada->id,
                        metodo: 'GATEWAY_'.strtoupper($gatewayNome),
                        notas: 'Confirmado automaticamente via API Real (Ref: '.$resultado->referenciaExterna.', evento: '.$eventoId.')',
                        utilizador: $superAdmin
                    );

                    TransacaoGateway::query()
                        ->where('subscricao_id', $bloqueada->id)
                        ->where('gateway', $gateway)
                        ->where('estado', TransacaoGateway::ESTADO_PENDENTE)
                        ->update(['estado' => TransacaoGateway::ESTADO_PAGO, 'atualizado_em' => now()]);

                    $this->notificacoes->criar(
                        empresaId: $bloqueada->empresa_id,
                        tipo: 'SUCESSO',
                        categoria: 'LICENCA',
                        titulo: 'Licença Renovada com Sucesso!',
                        mensagem: 'O pagamento da subscrição '.$bloqueada->referencia.' foi confirmado via '.strtoupper($gatewayNome).'. A licença da sua empresa foi prolongada.',
                        link: '/m/config/config_licenca',
                        autorNome: 'Gateway '.strtoupper($gatewayNome),
                    );

                    return [
                        'sucesso' => true,
                        'mensagem' => 'Pagamento confirmado e licença prolongada com sucesso.',
                        'http' => 200,
                        'subscricao' => $subscricaoAtualizada,
                    ];
                });
            });
        } catch (ErroNegocio $e) {
            return $this->rejeitarWebhook($ctx, $e->getMessage(), $e->estadoHttp >= 400 && $e->estadoHttp < 500 ? $e->estadoHttp : 422);
        } catch (UniqueConstraintViolationException) {
            return $this->rejeitarWebhook($ctx, 'Evento já processado.', 409);
        }
    }

    /** Regista a tentativa rejeitada na auditoria e devolve a resposta 4xx. */
    private function rejeitarWebhook(array $ctx, string $motivo, int $http): array
    {
        $this->registarWebhook($ctx, 'REJEITADO', $motivo, $http);

        return ['sucesso' => false, 'mensagem' => $motivo, 'http' => $http];
    }

    /** Insere uma linha em `webhooks_pagamento`. Só os eventos aceites levam `evento_unico`. */
    private function registarWebhook(array $ctx, string $resultado, string $motivo, int $http, ?string $eventoUnico = null, bool $propagar = false): void
    {
        try {
            DB::table('webhooks_pagamento')->insert([
                'gateway' => $ctx['gateway'],
                'evento_id' => $ctx['evento_id'] !== null ? substr((string) $ctx['evento_id'], 0, 120) : null,
                'evento_unico' => $eventoUnico,
                'subscricao_id' => $ctx['subscricao_id'],
                'resultado' => $resultado,
                'motivo' => substr($motivo, 0, 255),
                'estado_http' => $http,
                'assinatura_valida' => $ctx['assinatura_valida'],
                'timestamp_evento' => $ctx['timestamp'],
                'ip' => $ctx['ip'],
                'payload' => json_encode($ctx['payload'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'criado_em' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw $e; // evento duplicado: tratado pelo chamador
        } catch (\Throwable $e) {
            if ($propagar) {
                throw $e; // um evento aceite sem auditoria não pode confirmar o pagamento
            }
            report($e);
        }
    }
}
