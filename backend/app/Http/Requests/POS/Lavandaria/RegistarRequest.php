<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::registar (regras e mensagens tal como estavam no controlador). */
final class RegistarRequest extends PedidoPOS
{
    private const PAGAMENTOS = ['pagamentos' => ['nullable', 'array'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'],
        'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'], 'pagamentos.*.referencia' => ['nullable', 'string', 'max:100']];

    protected function permissoes(): array
    {
        return ['lav_ordens'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'integer'], 'modo_faturacao' => ['nullable', 'in:RECEPCAO,ENTREGA'], 'urgente' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string', 'max:2000'], 'valor' => ['nullable', 'numeric', 'min:0'],
            'itens' => ['required', 'array', 'min:1'], 'itens.*.peca_id' => ['required', 'integer'], 'itens.*.produto_id' => ['required', 'integer'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0'], 'itens.*.numero_pecas' => ['nullable', 'integer', 'min:1'], 'itens.*.preco' => ['nullable', 'numeric', 'min:0'],
            'itens.*.cor' => ['nullable', 'string', 'max:100'], 'itens.*.tecido' => ['nullable', 'string', 'max:100'], 'itens.*.descricao_peca' => ['nullable', 'string', 'max:255'],
            'itens.*.estado_entrada' => ['nullable', 'string', 'max:60'], 'itens.*.notas_entrada' => ['nullable', 'string', 'max:1000'],
            'itens.*.valor_declarado' => ['nullable', 'numeric', 'min:0'],
            'recolha' => ['nullable', 'array'], 'recolha.ativa' => ['nullable', 'boolean'], 'recolha.morada' => ['nullable', 'string', 'max:500'], 'recolha.data' => ['nullable', 'date'],
            'recolha.taxa' => ['nullable', 'numeric', 'min:0'],
            'entrega' => ['nullable', 'array'], 'entrega.ativa' => ['nullable', 'boolean'], 'entrega.morada' => ['nullable', 'string', 'max:500'], 'entrega.data' => ['nullable', 'date'],
            'entrega.taxa' => ['nullable', 'numeric', 'min:0'],
        ] + self::PAGAMENTOS;
    }
}
