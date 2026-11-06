<?php

namespace App\Http\Requests\POS\Mesas;

use App\Http\Requests\POS\PedidoPOS;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Validation\Rule;

/** Regras do carrinho de uma mesa (conta suspensa e cobrança), partilhadas por GravarContaRequest e CobrarRequest. */
abstract class CarrinhoMesaRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_venda'];
    }

    /** Linhas obrigatórias (cobrar) ou apenas presentes (gravar: sem linhas liberta a mesa). */
    abstract protected function linhasObrigatorias(): bool;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $obrigatorio = $this->linhasObrigatorias();

        return [
            'cliente_id' => ['nullable', 'integer', Rule::exists('terceiros', 'id')->where('empresa_id', $empresa)], 'observacoes' => ['nullable', 'string', 'max:2000'],
            'percentagem_desconto' => ['nullable', 'numeric', 'between:0,100'],
            'linhas' => [$obrigatorio ? 'required' : 'present', 'array', $obrigatorio ? 'min:1' : 'min:0', 'max:1000'],
            'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'gt:0'],
            'linhas.*.preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'], 'linhas.*.descricao' => ['nullable', 'string', 'max:500'],
        ];
    }
}
