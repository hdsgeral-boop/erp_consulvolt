<?php

namespace App\Http\Resources\POS;

/**
 * Sessão POS; no detalhe acrescenta as vendas da sessão (`vendas`). O primeiro argumento é `mixed`: `JsonResource::collection()` instancia `new static([])` para ler `preserveKeys`, e um
 * construtor tipado rebentava com 500 em todas as listagens.
 */
final class SessaoPOSResource extends RecursoPOS
{
    public function __construct(mixed $sessao, private readonly mixed $vendas = null)
    {
        parent::__construct($sessao);
    }

    protected function extras(): array
    {
        return $this->vendas === null ? [] : ['vendas' => $this->vendas];
    }
}
