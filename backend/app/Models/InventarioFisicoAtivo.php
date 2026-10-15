<?php

namespace App\Models;

use App\Models\Base\InventarioFisicoAtivoBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * inventarios_fisicos_ativos — /api/ativos/inventarios-fisicos (Fase F, Versão Actual; ServicoInventarioFisicoAtivos).
 * Legado novo: asset_inventories (db_v2.js v1268; js/activos_edificios.js:594-610). Folha de inventário físico de um
 * edifício, de um andar ou de uma porta. As colunas vêm da migração 2026_10_14_300000_ff_activos_inventario_fisico
 * (a estrutura gerada só tinha empresa_id). A folha não se elimina: anula-se (estado ANULADO, com motivo).
 */
class InventarioFisicoAtivo extends InventarioFisicoAtivoBase
{
    public const ABERTO = 'ABERTO';

    public const FECHADO = 'FECHADO';

    public const ANULADO = 'ANULADO';

    public const AMBITOS = ['EDIFICIO', 'ANDAR', 'PORTA'];

    protected function casts(): array
    {
        return parent::casts() + [
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
        ];
    }

    public function edificio(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'edificio_id')->withTrashed();
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(LinhaInventarioFisicoAtivo::class, 'inventario_fisico_id');
    }
}
