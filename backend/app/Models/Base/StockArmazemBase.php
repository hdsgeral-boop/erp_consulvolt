<?php

namespace App\Models\Base;

use App\Models\Armazem;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela stock_armazem (módulo Logística). Legado: warehouse_stock · 16 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\StockArmazem.
 */
abstract class StockArmazemBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'stock_armazem';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'armazem_id', 'produto_id', 'quantidade_stock',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'armazem_id' => 'integer',
            'produto_id' => 'integer',
            'quantidade_stock' => 'decimal:3',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }
}
