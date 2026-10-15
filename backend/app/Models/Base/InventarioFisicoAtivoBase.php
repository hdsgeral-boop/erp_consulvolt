<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaInventarioFisicoAtivo;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela inventarios_fisicos_ativos (módulo Activos). Legado: asset_inventories · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\InventarioFisicoAtivo.
 */
abstract class InventarioFisicoAtivoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'inventarios_fisicos_ativos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'edificio_id', 'ambito', 'andar_id', 'porta_id', 'data', 'responsavel', 'notas', 'estado', 'esperados', 'valor_esperado', 'contado_em', 'aplicado_em', 'fechado_em', 'fechado_por', 'criado_por', 'anulado_em', 'anulado_por', 'motivo_anulacao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'edificio_id' => 'integer',
            'andar_id' => 'integer',
            'porta_id' => 'integer',
            'data' => 'date',
            'esperados' => 'integer',
            'valor_esperado' => 'decimal:2',
            'contado_em' => 'datetime',
            'aplicado_em' => 'datetime',
            'fechado_em' => 'datetime',
            'anulado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function edificio(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'edificio_id');
    }

    public function linhasInventarioFisicoAtivos(): HasMany
    {
        return $this->hasMany(LinhaInventarioFisicoAtivo::class, 'inventario_fisico_id');
    }
}
