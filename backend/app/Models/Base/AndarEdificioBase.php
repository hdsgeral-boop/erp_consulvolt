<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PortaEdificio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela andares_edificios (módulo Activos). Legado: asset_floors · 3 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AndarEdificio.
 */
abstract class AndarEdificioBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'andares_edificios';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'edificio_id', 'numero', 'nome', 'notas', 'ordem',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'edificio_id' => 'integer',
            'ordem' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function edificio(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'edificio_id');
    }

    public function ativosImobilizados(): HasMany
    {
        return $this->hasMany(AtivoImobilizado::class, 'andar_id');
    }

    public function portasEdificios(): HasMany
    {
        return $this->hasMany(PortaEdificio::class, 'andar_id');
    }
}
