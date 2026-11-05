<?php

namespace App\Support\Autenticacao;

use Illuminate\Http\Request;

/**
 * Distingue a actividade LEGÍTIMA do utilizador dos pedidos automáticos do navegador (polling, heartbeat, actualização
 * em segundo plano) — só a primeira renova a inactividade de 15 minutos (ADR-085, OWASP Session Management).
 *
 * O frontend marca as chamadas automáticas com `X-Erp-Automatico: 1`. A marca só é aceite em GET/HEAD (leituras): um
 * pedido que altera estado é sempre acção do utilizador. Um cliente que a envie sem ser automático só consegue encurtar
 * a sua própria sessão (nunca prolongá-la), por isso a marca não é um ponto de abuso.
 */
final class PedidoAutomatico
{
    public static function e(?Request $pedido): bool
    {
        if ($pedido === null || ! in_array($pedido->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        return trim((string) $pedido->header((string) config('erp.sessao.cabecalho_automatico', 'X-Erp-Automatico'))) === '1';
    }
}
