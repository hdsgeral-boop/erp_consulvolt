<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EncomendaCompra;
use App\Models\MarcoContratoFornecedor;
use App\Models\ModeloBase;
use App\Models\Terceiro;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela contratos_fornecedores (módulo Compras). Legado: purchase_contracts · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ContratoFornecedor.
 */
abstract class ContratoFornecedorBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'contratos_fornecedores';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'fornecedor_id', 'encomenda_compra_id', 'referencia', 'descricao', 'data_inicio', 'data_fim', 'valor_total', 'estado', 'encomendas_ids_legado',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'fornecedor_id' => 'integer',
            'encomenda_compra_id' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'valor_total' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'fornecedor_id');
    }

    public function encomendaCompra(): BelongsTo
    {
        return $this->belongsTo(EncomendaCompra::class, 'encomenda_compra_id');
    }

    public function encomendasCompra(): HasMany
    {
        return $this->hasMany(EncomendaCompra::class, 'contrato_fornecedor_id');
    }

    public function marcosContratoFornecedor(): HasMany
    {
        return $this->hasMany(MarcoContratoFornecedor::class, 'contrato_fornecedor_id');
    }
}
