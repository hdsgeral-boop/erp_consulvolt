<?php

namespace App\Services\Pagamentos;

use App\Models\SubscricaoEmpresa;

interface ContratoGatewayPagamento
{
    /** Inicia o processo de cobrança junto do gateway. */
    public function iniciar(SubscricaoEmpresa $subscricao, array $parametros = []): ResultadoIniciacao;

    /**
     * Processa uma notificação de webhook/callback enviada pela API real (assinatura já verificada pelo chamador). Devolve o
     * valor e a moeda DECLARADOS no payload (o chamador confronta-os com a subscrição) e o gateway da transação encontrada.
     */
    public function processarWebhook(array $payload, array $headers = []): ResultadoConfirmacao;

    /** Chave em `configuracoes_gateway` com o segredo HMAC usado para verificar a assinatura do webhook deste gateway. */
    public function chaveConfigSegredo(): string;
}
