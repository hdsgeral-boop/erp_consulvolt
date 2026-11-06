<?php

namespace App\Http\Resources\POS;

use App\Models\TerminalPOS;

/** Terminal POS com a sessão aberta (id, código, operador, abertura) ou null. */
final class TerminalPOSResource extends RecursoPOS
{
    public function __construct(TerminalPOS $terminal, private readonly mixed $sessaoAberta = null)
    {
        parent::__construct($terminal);
    }

    protected function extras(): array
    {
        return ['sessao_aberta' => $this->sessaoAberta];
    }
}
