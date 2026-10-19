<?php

namespace App\Services\Pagamentos;

class ResultadoConfirmacao
{
    public function __construct(
        public readonly bool $sucesso,
        public readonly ?int $subscricaoId = null,
        public readonly ?string $referenciaExterna = null,
        public readonly ?float $valorPago = null,
        public readonly ?string $mensagem = null,
        public readonly array $dadosOriginais = [],
    ) {}
}
