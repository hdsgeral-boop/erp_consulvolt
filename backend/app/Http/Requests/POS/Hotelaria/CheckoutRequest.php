<?php

namespace App\Http\Requests\POS\Hotelaria;

use App\Http\Requests\POS\PedidoPOS;

/** Regras do check-out (pré-visualização e emissão): na pré-visualização os pagamentos são opcionais. */
abstract class CheckoutRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['hotel_checkout'];
    }

    abstract protected function comPagamentos(): bool;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estadias' => ['required', 'array', 'min:1'], 'estadias.*.id' => ['required', 'integer'], 'estadias.*.opcao_atraso' => ['nullable', 'in:RECALCULAR,MANTER'],
            'percentagem_desconto' => ['nullable', 'numeric', 'between:0,100'], 'modo_faturacao' => ['required', 'in:POR_QUARTO,UNICA'],
            'cliente_id' => ['nullable', 'integer', 'required_if:modo_faturacao,UNICA'], 'observacoes' => ['nullable', 'string', 'max:2000'],
            'pagamentos' => [$this->comPagamentos() ? 'required' : 'nullable', 'array', 'min:1'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'],
            'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'], 'pagamentos.*.referencia' => ['nullable', 'string', 'max:100'],
        ];
    }
}
