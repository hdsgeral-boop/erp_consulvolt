<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EncomendaCompra;
use App\Models\ItemCompra;
use App\Models\ModeloBase;
use App\Models\Projeto;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela faturas_compra (módulo Compras). Legado: purchase_invoices · 25 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\FaturaCompra.
 */
abstract class FaturaCompraBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'faturas_compra';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'encomenda_compra_id', 'fornecedor_id', 'numero_fatura', 'data', 'montante_total', 'total_imposto', 'estado', 'contabilizado', 'itens', 'projeto_id', 'codigo_projeto', 'unidade_negocio_id', 'centro_custo_id', 'codigo_moeda', 'taxa_cambio', 'taxa_cambio_id', 'taxa_cambio_manual', 'montante_total_moeda', 'total_imposto_moeda',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'encomenda_compra_id' => 'integer',
            'fornecedor_id' => 'integer',
            'data' => 'date',
            'montante_total' => 'decimal:2',
            'total_imposto' => 'decimal:2',
            'contabilizado' => 'boolean',
            'itens' => 'array',
            'projeto_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'taxa_cambio' => 'decimal:6',
            'taxa_cambio_id' => 'integer',
            'taxa_cambio_manual' => 'boolean',
            'montante_total_moeda' => 'decimal:2',
            'total_imposto_moeda' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function encomendaCompra(): BelongsTo
    {
        return $this->belongsTo(EncomendaCompra::class, 'encomenda_compra_id');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'fornecedor_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
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

    public function itensCompra(): HasMany
    {
        return $this->hasMany(ItemCompra::class, 'fatura_compra_id');
    }
}
