<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ContratoFornecedor;
use App\Models\CotacaoCompra;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\ModeloBase;
use App\Models\PedidoCompra;
use App\Models\Projeto;
use App\Models\RececaoCompra;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela encomendas_compra (módulo Compras). Legado: purchase_orders · 17 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\EncomendaCompra.
 */
abstract class EncomendaCompraBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'encomendas_compra';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'pedido_compra_id', 'cotacao_compra_id', 'fornecedor_id', 'numero_encomenda', 'data', 'estado', 'contabilizado', 'venda_origem_id', 'projeto_id', 'codigo_projeto', 'contrato_fornecedor_id', 'unidade_negocio_id', 'centro_custo_id', 'codigo_moeda', 'taxa_cambio', 'taxa_cambio_id', 'taxa_cambio_manual', 'montante_total', 'montante_total_moeda', 'total_imposto', 'total_com_imposto',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'pedido_compra_id' => 'integer',
            'cotacao_compra_id' => 'integer',
            'fornecedor_id' => 'integer',
            'data' => 'datetime',
            'contabilizado' => 'boolean',
            'venda_origem_id' => 'integer',
            'projeto_id' => 'integer',
            'contrato_fornecedor_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'taxa_cambio' => 'decimal:6',
            'taxa_cambio_id' => 'integer',
            'taxa_cambio_manual' => 'boolean',
            'montante_total' => 'decimal:2',
            'montante_total_moeda' => 'decimal:2',
            'total_imposto' => 'decimal:2',
            'total_com_imposto' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function pedidoCompra(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class, 'pedido_compra_id');
    }

    public function cotacaoCompra(): BelongsTo
    {
        return $this->belongsTo(CotacaoCompra::class, 'cotacao_compra_id');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'fornecedor_id');
    }

    public function vendaOrigem(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_origem_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function contratoFornecedor(): BelongsTo
    {
        return $this->belongsTo(ContratoFornecedor::class, 'contrato_fornecedor_id');
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function taxaCambioRelacao(): BelongsTo
    {
        return $this->belongsTo(TaxaCambio::class, 'taxa_cambio_id');
    }

    public function rececoesCompra(): HasMany
    {
        return $this->hasMany(RececaoCompra::class, 'encomenda_compra_id');
    }

    public function faturasCompra(): HasMany
    {
        return $this->hasMany(FaturaCompra::class, 'encomenda_compra_id');
    }

    public function itensCompra(): HasMany
    {
        return $this->hasMany(ItemCompra::class, 'encomenda_compra_id');
    }

    public function contratosFornecedores(): HasMany
    {
        return $this->hasMany(ContratoFornecedor::class, 'encomenda_compra_id');
    }
}
