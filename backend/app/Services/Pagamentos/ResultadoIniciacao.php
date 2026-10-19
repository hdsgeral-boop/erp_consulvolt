<?php

namespace App\Services\Pagamentos;

class ResultadoIniciacao
{
    public function __construct(
        public readonly bool $sucesso,
        public readonly string $gateway,
        public readonly ?string $referenciaExterna = null,
        public readonly ?string $entidade = null,
        public readonly ?string $referencia = null,
        public readonly ?string $telemovel = null,
        public readonly ?string $instrucoes = null,
        public readonly ?string $estado = 'PENDENTE',
        public readonly array $metadados = [],
        public readonly ?string $mensagemErro = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'sucesso' => $this->sucesso,
            'gateway' => $this->gateway,
            'referencia_externa' => $this->referenciaExterna,
            'entidade' => $this->entidade,
            'referencia' => $this->referencia,
            'telemovel' => $this->telemovel,
            'instrucoes' => $this->instrucoes,
            'estado' => $this->estado,
            'metadados' => $this->metadados,
            'erro' => $this->mensagemErro,
        ], fn ($v) => $v !== null);
    }
}
