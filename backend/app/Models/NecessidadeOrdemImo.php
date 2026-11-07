<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/**
 * necessidades_ordem_imo — o material que uma ordem pede. Liga-se ao armazém (linha da requisição de manutenção) e às Compras (linha do
 * pedido) sem duplicar nenhum dos dois: a quantidade recebida lê-se dessas origens (ServicoObrasImoveis::sincronizar).
 */
class NecessidadeOrdemImo extends ModeloBase
{
    use PertenceEmpresa;

    public const ESTADOS = ['POR_PEDIR', 'REQUISITADO', 'PEDIDO_COMPRA', 'RECEBIDO', 'CANCELADO'];

    protected $table = 'necessidades_ordem_imo';

    protected $fillable = ['empresa_id', 'ordem_id', 'produto_id', 'descricao', 'quantidade', 'estado', 'quantidade_recebida', 'quantidade_armazem', 'quantidade_compra',
        'quantidade_apetrechada', 'requisicao_manutencao_id', 'linha_requisicao_id', 'pedido_compra_id', 'linha_compra_id', 'ordem'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'ordem_id' => 'integer', 'produto_id' => 'integer', 'requisicao_manutencao_id' => 'integer', 'linha_requisicao_id' => 'integer',
            'pedido_compra_id' => 'integer', 'linha_compra_id' => 'integer', 'ordem' => 'integer', 'quantidade' => 'decimal:3', 'quantidade_recebida' => 'decimal:3',
            'quantidade_armazem' => 'decimal:3', 'quantidade_compra' => 'decimal:3', 'quantidade_apetrechada' => 'decimal:3'];
    }
}
