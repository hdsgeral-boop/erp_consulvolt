<?php

namespace App\Http\Requests\POS\Hotelaria;

/** Pré-visualização do check-out (sem pagamentos obrigatórios). */
final class SimularCheckoutRequest extends CheckoutRequest
{
    protected function comPagamentos(): bool
    {
        return false;
    }
}
