<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\TicketSuporte;

/**
 * Máquina de estados dos tickets de suporte (Fase 6 da extensão SaaS, secção 13.3 de `segurança.txt`):
 * "implementar uma máquina de estados consistente... validar as transições permitidas... impedir alterações de
 * estado não autorizadas." Até aqui, `SuporteController::atualizar()` aceitava qualquer estado do catálogo sem
 * validar a transição (ex. ABERTO directo para FECHADO).
 */
final class MaquinaEstadosTicket
{
    /**
     * Estados não-terminais: de qualquer um destes pode-se ir para qualquer outro estado (incluindo directamente
     * para RESOLVIDO/FECHADO/CANCELADO) — os estados intermédios (triagem/análise/atribuído/aguardar) são opcionais,
     * não obrigatórios, e o fluxo simples já em produção (ex. ABERTO directo para RESOLVIDO, um pedido trivial
     * tratado de imediato) continua válido. A única protecção real é sobre os estados TERMINAIS: nunca reabrir
     * RESOLVIDO/FECHADO a não ser para REABERTO, e CANCELADO nunca tem saída.
     */
    private const NAO_TERMINAIS = [
        TicketSuporte::ABERTO, TicketSuporte::EM_TRIAGEM, TicketSuporte::EM_ANALISE, TicketSuporte::ATRIBUIDO,
        TicketSuporte::EM_CURSO, TicketSuporte::A_AGUARDAR_CLIENTE, TicketSuporte::A_AGUARDAR_TERCEIRO, TicketSuporte::REABERTO,
    ];

    private const TRANSICOES_DE_TERMINAIS = [
        TicketSuporte::RESOLVIDO => [TicketSuporte::FECHADO, TicketSuporte::REABERTO],
        TicketSuporte::FECHADO => [TicketSuporte::REABERTO],
        TicketSuporte::CANCELADO => [],
    ];

    /** Lança ErroNegocio se a transição não for permitida; sem efeito se o estado não mudar. */
    public function validar(string $estadoActual, string $estadoNovo): void
    {
        if ($estadoActual === $estadoNovo) {
            return;
        }
        $permitido = in_array($estadoActual, self::NAO_TERMINAIS, true)
            ? in_array($estadoNovo, TicketSuporte::ESTADOS, true)
            : in_array($estadoNovo, self::TRANSICOES_DE_TERMINAIS[$estadoActual] ?? [], true);

        if (! $permitido) {
            throw new ErroNegocio("Transição de '{$estadoActual}' para '{$estadoNovo}' não é permitida.", 'TRANSICAO_INVALIDA', 422);
        }
    }
}
