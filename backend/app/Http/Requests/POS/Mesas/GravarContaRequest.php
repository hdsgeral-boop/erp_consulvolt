<?php

namespace App\Http\Requests\POS\Mesas;

/** PUT /mesas/{m}/conta — «Suspender»: grava a conta da mesa (sem linhas, liberta a mesa). */
final class GravarContaRequest extends CarrinhoMesaRequest
{
    protected function linhasObrigatorias(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['versao' => ['nullable', 'integer']];
    }
}
