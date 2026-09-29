<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violação de uma regra de negócio. Renderizada no envelope da API com o `codigo` indicado,
 * para que o frontend possa reagir sem interpretar a mensagem.
 */
class ErroNegocio extends RuntimeException
{
    public function __construct(
        string $mensagem,
        public readonly string $codigo = 'REGRA_NEGOCIO',
        public readonly int $estadoHttp = 422,
        public readonly array $detalhes = [],
    ) {
        parent::__construct($mensagem);
    }
}
