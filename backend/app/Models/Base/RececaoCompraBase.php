<?php

namespace App\Models\Base;

use App\Models\Armazem;
use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EncomendaCompra;
use App\Models\ItemGuiaSaida;
use App\Models\ModeloBase;
use App\Models\TaxaCambio;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela rececoes_compra (módulo Compras). Legado: purchase_deliveries · 22 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RececaoCompra.
 */
abstract class RececaoCompraBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'rececoes_compra';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'encomenda_compra_id', 'numero_entrega', 'data', 'estado', 'estado_original', 'contabilizado', 'validado', 'armazem_id', 'unidade_negocio_id', 'centro_custo_id', 'codigo_moeda', 'taxa_cambio', 'taxa_cambio_id', 'taxa_cambio_manual', 'valor_total_kz', 'numero_rececao', 'numero_lan_contabilizacao', 'validado_em', 'validado_por', 'anulado_em', 'motivo_anulacao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'encomenda_compra_id' => 'integer',
            'data' => 'date',
            'contabilizado' => 'boolean',
            'validado' => 'boolean',
            'armazem_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'taxa_cambio' => 'decimal:6',
            'taxa_cambio_id' => 'integer',
            'taxa_cambio_manual' => 'boolean',
            'valor_total_kz' => 'decimal:2',
            'validado_em' => 'datetime',
            'anulado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function encomendaCompra(): BelongsTo
    {
        return $this->belongsTo(EncomendaCompra::class, 'encomenda_compra_id');
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_id');
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

    public function itensGuiaSaida(): HasMany
    {
        return $this->hasMany(ItemGuiaSaida::class, 'rececao_compra_id');
    }
}
