<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Produto;
use App\Models\SessaoInventario;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela linhas_sessao_inventario (módulo Logística). Legado: inventory_session_lines · 6 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaSessaoInventario.
 */
abstract class LinhaSessaoInventarioBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_sessao_inventario';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'sessao_inventario_id', 'produto_id', 'quantidade_sistema', 'quantidade_contada', 'diferenca', 'observacoes', 'justificacao', 'custo_personalizado',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'sessao_inventario_id' => 'integer',
            'produto_id' => 'integer',
            'quantidade_sistema' => 'decimal:3',
            'quantidade_contada' => 'decimal:3',
            'diferenca' => 'decimal:2',
            'custo_personalizado' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function sessaoInventario(): BelongsTo
    {
        return $this->belongsTo(SessaoInventario::class, 'sessao_inventario_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }
}
