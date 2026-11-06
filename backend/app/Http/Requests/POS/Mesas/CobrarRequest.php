<?php

namespace App\Http\Requests\POS\Mesas;

/** POST /sessoes/{s}/mesas/{m}/cobrar — emite a factura-recibo da mesa e fecha a conta. */
final class CobrarRequest extends CarrinhoMesaRequest
{
    protected function linhasObrigatorias(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'pagamentos' => ['required', 'array', 'min:1', 'max:50'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'], 'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'],
            'pagamentos.*.referencia' => ['nullable', 'string', 'max:100'], 'versao' => ['nullable', 'integer'],
        ];
    }
}
