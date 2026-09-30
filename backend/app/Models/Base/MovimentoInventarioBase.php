<?php

namespace App\Models\Base;

use App\Models\Armazem;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\Terceiro;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela movimentos_inventario (módulo Logística). Legado: inventory_movements · 102 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\MovimentoInventario.
 */
abstract class MovimentoInventarioBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'movimentos_inventario';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'produto_id', 'armazem_id', 'tipo', 'tipo_original', 'quantidade', 'data', 'terceiro_id', 'referencia', 'projeto_id', 'codigo_projeto', 'preco_unitario', 'sentido', 'valor', 'custo_medio_apos', 'documento_tipo', 'documento_id', 'armazem_contraparte_id', 'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'produto_id' => 'integer',
            'armazem_id' => 'integer',
            'quantidade' => 'decimal:3',
            'data' => 'datetime',
            'terceiro_id' => 'integer',
            'projeto_id' => 'integer',
            'preco_unitario' => 'decimal:2',
            'valor' => 'decimal:2',
            'custo_medio_apos' => 'decimal:6',
            'documento_id' => 'integer',
            'armazem_contraparte_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_id');
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function armazemContraparte(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_contraparte_id');
    }
}
