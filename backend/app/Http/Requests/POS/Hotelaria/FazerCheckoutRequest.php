<?php

namespace App\Http\Requests\POS\Hotelaria;

/** Check-out com facturação POS (pagamentos obrigatórios). */
final class FazerCheckoutRequest extends CheckoutRequest
{
    protected function comPagamentos(): bool
    {
        return true;
    }
}
