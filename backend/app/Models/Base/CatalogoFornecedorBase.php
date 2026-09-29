<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela catalogo_fornecedores (módulo Compras). Legado: purchase_catalog · 70 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CatalogoFornecedor.
 */
abstract class CatalogoFornecedorBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'catalogo_fornecedores';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'produto_id', 'codigo', 'nome', 'preco_unitario',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'produto_id' => 'integer',
            'preco_unitario' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }
}
