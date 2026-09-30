<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ContratoFornecedor;
use App\Models\FaturaCompra;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela marcos_contrato_fornecedor (módulo Compras). Legado: purchase_contract_milestones · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\MarcoContratoFornecedor.
 */
abstract class MarcoContratoFornecedorBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'marcos_contrato_fornecedor';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'contrato_fornecedor_id', 'titulo', 'data_prevista', 'montante', 'estado', 'fatura_compra_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'contrato_fornecedor_id' => 'integer',
            'data_prevista' => 'date',
            'montante' => 'decimal:2',
            'fatura_compra_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function contratoFornecedor(): BelongsTo
    {
        return $this->belongsTo(ContratoFornecedor::class, 'contrato_fornecedor_id');
    }

    public function faturaCompra(): BelongsTo
    {
        return $this->belongsTo(FaturaCompra::class, 'fatura_compra_id');
    }
}
