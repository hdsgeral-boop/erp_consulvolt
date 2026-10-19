<?php

namespace App\Services\Pagamentos;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\SubscricaoEmpresa;
use App\Models\TransacaoGateway;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoLicenca;
use App\Services\Sistema\ServicoNotificacoes;
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
        $linhas = DB::table('configuracoes_gateway')->pluck('valor', 'chave');

        return [
            'referencia' => [
                'ativo' => ($linhas['ref_ativo'] ?? 'true') === 'true',
                'entidade' => $linhas['ref_entidade'] ?? '00123',
                'subentidade' => $linhas['ref_subentidade'] ?? '',
                'chave_api' => ! empty($linhas['ref_chave_api']) ? '••••••••'.substr((string) $linhas['ref_chave_api'], -4) : '',
                'url_api' => $linhas['ref_url_api'] ?? '',
                'tem_chave' => ! empty($linhas['ref_chave_api']),
            ],
            'express' => [
                'ativo' => ($linhas['mcx_ativo'] ?? 'true') === 'true',
                'merchant_id' => $linhas['mcx_merchant_id'] ?? '',
                'terminal_id' => $linhas['mcx_terminal_id'] ?? '',
                'chave_api' => ! empty($linhas['mcx_chave_api']) ? '••••••••'.substr((string) $linhas['mcx_chave_api'], -4) : '',
                'url_api' => $linhas['mcx_url_api'] ?? '',
                'tem_chave' => ! empty($linhas['mcx_chave_api']),
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
            DB::table('configuracoes_gateway')->updateOrInsert(['chave' => $k], ['valor' => $v, 'atualizado_em' => now()]);
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

    /** Processa a confirmação de pagamento recebida via Webhook da API Real. */
    public function processarWebhook(string $gatewayNome, array $payload, array $headers = []): array
    {
        $driver = match (strtoupper($gatewayNome)) {
            'REFERENCIA', 'MCX_REF' => new GatewayReferenciaMulticaixa,
            'EXPRESS', 'MCX_EXPRESS' => new GatewayMulticaixaExpress,
            default => throw new ErroNegocio('Gateway de webhook desconhecido.', 'GATEWAY_DESCONHECIDO', 404),
        };

        $resultado = $driver->processarWebhook($payload, $headers);

        if (! $resultado->sucesso || ! $resultado->subscricaoId) {
            return [
                'sucesso' => false,
                'mensagem' => $resultado->mensagem ?: 'Notificação não validada ou subscrição não identificada.',
            ];
        }

        $contextoEmpresa = app(\App\Support\Tenancy\ContextoEmpresa::class);

        $subscricao = $contextoEmpresa->semIsolamento(fn () => SubscricaoEmpresa::query()->find($resultado->subscricaoId));
        if (! $subscricao || $subscricao->estado !== SubscricaoEmpresa::PENDENTE) {
            return [
                'sucesso' => true,
                'mensagem' => 'Notificação recebida, mas o pedido já não se encontra pendente.',
            ];
        }

        return $contextoEmpresa->executarComo((int) $subscricao->empresa_id, function () use ($subscricao, $gatewayNome, $resultado) {
            $superAdmin = Utilizador::query()->where('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)->first()
                ?? Utilizador::query()->first();

            if (! $superAdmin) {
                return ['sucesso' => false, 'mensagem' => 'Nenhum utilizador encontrado para registo.'];
            }

            // Confirma o pagamento atómicamente
            $subscricaoAtualizada = $this->licenca->confirmarPagamento(
                id: $subscricao->id,
                metodo: 'GATEWAY_'.strtoupper($gatewayNome),
                notas: 'Confirmado automaticamente via API Real (Ref: '.$resultado->referenciaExterna.')',
                utilizador: $superAdmin
            );

            // Atualiza a transação para PAGO
            TransacaoGateway::query()
                ->where('subscricao_id', $subscricao->id)
                ->where('estado', TransacaoGateway::ESTADO_PENDENTE)
                ->update([
                    'estado' => TransacaoGateway::ESTADO_PAGO,
                    'atualizado_em' => now(),
                ]);

            // Emite alerta/notificação de sucesso no sistema para a empresa
            $this->notificacoes->criar(
                empresaId: $subscricao->empresa_id,
                tipo: 'SUCESSO',
                categoria: 'LICENCA',
                titulo: 'Licença Renovada com Sucesso!',
                mensagem: 'O pagamento da subscrição '.$subscricao->referencia.' foi confirmado via '.strtoupper($gatewayNome).'. A licença da sua empresa foi prolongada.',
                link: '/m/config/config_licenca',
                autorNome: 'Gateway '.strtoupper($gatewayNome),
            );

            return [
                'sucesso' => true,
                'mensagem' => 'Pagamento confirmado e licença prolongada com sucesso.',
                'subscricao' => $subscricaoAtualizada,
            ];
        });
    }
}
