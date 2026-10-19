<?php

namespace App\Services\Pagamentos;

use App\Models\SubscricaoEmpresa;

interface ContratoGatewayPagamento
{
    /** Inicia o processo de cobrança junto do gateway. */
    public function iniciar(SubscricaoEmpresa $subscricao, array $parametros = []): ResultadoIniciacao;

    /** Processa uma notificação de webhook/callback enviada pela API real. */
    public function processarWebhook(array $payload, array $headers = []): ResultadoConfirmacao;
}
