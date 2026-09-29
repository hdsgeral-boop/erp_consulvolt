<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EncomendaCompra;
use App\Models\ItemCompra;
use App\Models\ModeloBase;
use App\Models\PedidoCompra;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela cotacoes_compra (módulo Compras). Legado: purchase_quotes · 23 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CotacaoCompra.
 */
abstract class CotacaoCompraBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'cotacoes_compra';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'pedido_compra_id', 'fornecedor_id', 'referencia', 'montante_total', 'data', 'data_entrega', 'estado', 'estado_original', 'unidade_negocio_id', 'centro_custo_id', 'codigo_moeda', 'taxa_cambio', 'taxa_cambio_id', 'taxa_cambio_manual', 'montante_total_moeda', 'total_imposto', 'total_com_imposto', 'numero_proposta',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'pedido_compra_id' => 'integer',
            'fornecedor_id' => 'integer',
            'montante_total' => 'decimal:2',
            'data' => 'date',
            'data_entrega' => 'date',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'taxa_cambio' => 'decimal:6',
            'taxa_cambio_id' => 'integer',
            'taxa_cambio_manual' => 'boolean',
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

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'fornecedor_id');
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

    public function encomendasCompra(): HasMany
    {
        return $this->hasMany(EncomendaCompra::class, 'cotacao_compra_id');
    }

    public function itensCompra(): HasMany
    {
        return $this->hasMany(ItemCompra::class, 'cotacao_compra_id');
    }
}
