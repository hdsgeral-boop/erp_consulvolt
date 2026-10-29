<?php

namespace App\Services\Pagamentos;

use App\Models\SubscricaoEmpresa;

interface ContratoGatewayPagamento
{
    /** Inicia o processo de cobrança junto do gateway. */
    public function iniciar(SubscricaoEmpresa $subscricao, array $parametros = []): ResultadoIniciacao;

    /** Processa uma notificação de webhook/callback enviada pela API real (assinatura já verificada pelo chamador). */
    public function processarWebhook(array $payload): ResultadoConfirmacao;

    /** Chave em `configuracoes_gateway` com o segredo HMAC usado para verificar a assinatura do webhook deste gateway. */
    public function chaveConfigSegredo(): string;
}
