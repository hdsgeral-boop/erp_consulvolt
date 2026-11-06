<?php

namespace App\Http\Resources\POS;

use App\Models\SessaoPOS;

/** Sessão POS; no detalhe acrescenta as vendas da sessão (`vendas`). */
final class SessaoPOSResource extends RecursoPOS
{
    public function __construct(SessaoPOS $sessao, private readonly mixed $vendas = null)
    {
        parent::__construct($sessao);
    }

    protected function extras(): array
    {
        return $this->vendas === null ? [] : ['vendas' => $this->vendas];
    }
}
