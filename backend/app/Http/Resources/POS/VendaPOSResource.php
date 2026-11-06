<?php

namespace App\Http\Resources\POS;

use App\Models\Venda;

/** Venda POS (factura-recibo) com as linhas (`itensVenda`), como a devolvem a venda ao balcão, a mesa e o check-out. */
final class VendaPOSResource extends RecursoPOS
{
    public function __construct(mixed $venda)
    {
        // `JsonResource::collection()` instancia `new static([])`: só se carrega a relação num modelo
        parent::__construct($venda instanceof Venda ? $venda->load('itensVenda') : $venda);
    }
}
