<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela itens_venda (módulo Vendas). Legado: sale_items · 95 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ItemVenda.
 */
abstract class ItemVendaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'itens_venda';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = [
        'empresa_id', 'produto_id', 'quantidade', 'preco_unitario', 'taxa_imposto', 'total', 'venda_id', 'projeto_id', 'codigo_projeto', 'observacoes', 'quantidade_faturada', 'pedido_compra_id', 'quantidade_entregue', 'descricao', 'percentagem_desconto', 'total_linha', 'codigo_conta', 'preco_unitario_moeda', 'total_moeda', 'imposto_moeda', 'fe_selado', 'custo_unitario_kz', 'quantidade_stock', 'quantidade_devolvida',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'produto_id' => 'integer',
            'quantidade' => 'decimal:3',
            'preco_unitario' => 'decimal:2',
            'taxa_imposto' => 'decimal:4',
            'total' => 'decimal:2',
            'venda_id' => 'integer',
            'projeto_id' => 'integer',
            'quantidade_faturada' => 'decimal:3',
            'pedido_compra_id' => 'integer',
            'quantidade_entregue' => 'decimal:3',
            'percentagem_desconto' => 'decimal:4',
            'total_linha' => 'decimal:2',
            'preco_unitario_moeda' => 'decimal:2',
            'total_moeda' => 'decimal:2',
            'imposto_moeda' => 'decimal:2',
            'fe_selado' => 'boolean',
            'custo_unitario_kz' => 'decimal:6',
            'quantidade_stock' => 'decimal:3',
            'quantidade_devolvida' => 'decimal:3',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function pedidoCompra(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class, 'pedido_compra_id');
    }
}
