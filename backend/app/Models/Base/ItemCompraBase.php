<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\CotacaoCompra;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ModeloBase;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela itens_compra (módulo Compras). Legado: purchase_items · 90 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ItemCompra.
 */
abstract class ItemCompraBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'itens_compra';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'tipo_documento_origem', 'tipo_documento_origem_original', 'produto_id', 'quantidade', 'preco_unitario', 'projeto_id', 'codigo_projeto', 'quantidade_recebida', 'quantidade_faturada', 'encomenda_compra_id', 'descricao', 'total', 'tarefa_projeto_id', 'preco_unitario_moeda', 'total_moeda', 'total_kz', 'cambial_recebido_por_faturar_qtd', 'cambial_recebido_por_faturar_kz', 'cambial_faturado_por_receber_qtd', 'cambial_faturado_por_receber_kz', 'taxa_imposto', 'pedido_compra_id', 'cotacao_compra_id', 'fatura_compra_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'produto_id' => 'integer',
            'quantidade' => 'decimal:3',
            'preco_unitario' => 'decimal:2',
            'projeto_id' => 'integer',
            'quantidade_recebida' => 'decimal:3',
            'quantidade_faturada' => 'decimal:3',
            'encomenda_compra_id' => 'integer',
            'total' => 'decimal:2',
            'tarefa_projeto_id' => 'integer',
            'preco_unitario_moeda' => 'decimal:2',
            'total_moeda' => 'decimal:2',
            'total_kz' => 'decimal:2',
            'cambial_recebido_por_faturar_qtd' => 'decimal:3',
            'cambial_recebido_por_faturar_kz' => 'decimal:2',
            'cambial_faturado_por_receber_qtd' => 'decimal:3',
            'cambial_faturado_por_receber_kz' => 'decimal:2',
            'taxa_imposto' => 'decimal:4',
            'pedido_compra_id' => 'integer',
            'cotacao_compra_id' => 'integer',
            'fatura_compra_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function encomendaCompra(): BelongsTo
    {
        return $this->belongsTo(EncomendaCompra::class, 'encomenda_compra_id');
    }

    public function tarefaProjeto(): BelongsTo
    {
        return $this->belongsTo(TarefaProjeto::class, 'tarefa_projeto_id');
    }

    public function pedidoCompra(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class, 'pedido_compra_id');
    }

    public function cotacaoCompra(): BelongsTo
    {
        return $this->belongsTo(CotacaoCompra::class, 'cotacao_compra_id');
    }

    public function faturaCompra(): BelongsTo
    {
        return $this->belongsTo(FaturaCompra::class, 'fatura_compra_id');
    }
}
