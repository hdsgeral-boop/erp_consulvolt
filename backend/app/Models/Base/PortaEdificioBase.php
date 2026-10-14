<?php

namespace App\Models\Base;

use App\Models\AndarEdificio;
use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela portas_edificios (módulo Activos). Legado: asset_rooms · 8 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PortaEdificio.
 */
abstract class PortaEdificioBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'portas_edificios';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'edificio_id', 'andar_id', 'numero', 'nome', 'tipo', 'capacidade', 'ordem',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'edificio_id' => 'integer',
            'andar_id' => 'integer',
            'capacidade' => 'integer',
            'ordem' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function edificio(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'edificio_id');
    }

    public function andar(): BelongsTo
    {
        return $this->belongsTo(AndarEdificio::class, 'andar_id');
    }

    public function ativosImobilizados(): HasMany
    {
        return $this->hasMany(AtivoImobilizado::class, 'porta_id');
    }
}
