<?php

namespace App\Http\Resources\POS;

use App\Models\Venda;

/** Venda POS (factura-recibo) com as linhas (`itensVenda`), como a devolvem a venda ao balcão, a mesa e o check-out. */
final class VendaPOSResource extends RecursoPOS
{
    public function __construct(Venda $venda)
    {
        parent::__construct($venda->load('itensVenda'));
    }
}
