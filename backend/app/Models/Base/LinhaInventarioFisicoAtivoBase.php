<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\InventarioFisicoAtivo;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela linhas_inventario_fisico_ativos (módulo Activos). Legado: asset_inventory_lines · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaInventarioFisicoAtivo.
 */
abstract class LinhaInventarioFisicoAtivoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_inventario_fisico_ativos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'inventario_fisico_id', 'ativo_imobilizado_id', 'codigo', 'descricao', 'porta_id', 'andar_id', 'valor', 'resultado', 'porta_encontrada_id', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'inventario_fisico_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'porta_id' => 'integer',
            'andar_id' => 'integer',
            'valor' => 'decimal:2',
            'porta_encontrada_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function inventarioFisico(): BelongsTo
    {
        return $this->belongsTo(InventarioFisicoAtivo::class, 'inventario_fisico_id');
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id');
    }
}
