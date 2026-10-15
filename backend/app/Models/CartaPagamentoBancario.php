<?php

namespace App\Models;

use App\Models\Base\CartaPagamentoBancarioBase;

/**
 * cartas_pagamento_bancario — /api/tesouraria/cartas (e as cartas antigas do RH, /api/rh/salarios/cartas).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em CartaPagamentoBancarioBase (gerado).
 * Fase D (ADR-071): a carta nasce de um pagamento gravado na tesouraria e não gera lançamentos
 * (App\Services\Tesouraria\ServicoCartasPagamento).
 */
class CartaPagamentoBancario extends CartaPagamentoBancarioBase
{
    public const TIPO_TERCEIRO = 'TERCEIRO';

    public const TIPO_SALARIOS = 'SALARIOS';

    public const EMITIDA = 'EMITIDA';

    public const ANULADA = 'ANULADA';

    /** As cartas antigas do RH não tinham estado: contam como emitidas. */
    public function activa(): bool
    {
        return $this->estado !== self::ANULADA;
    }
}
