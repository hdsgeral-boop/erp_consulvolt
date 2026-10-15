<?php

namespace App\Models;

use App\Models\Base\LinhaInventarioFisicoAtivoBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * linhas_inventario_fisico_ativos — linhas da folha de inventário físico (Fase F, Versão Actual).
 * Legado novo: asset_inventory_lines (js/activos_edificios.js:601-606, 814-819): fotografia do bem no momento da geração
 * (código, descrição, porta e andar esperados, valor) e o resultado da contagem. Resultado vazio = por contar.
 */
class LinhaInventarioFisicoAtivo extends LinhaInventarioFisicoAtivoBase
{
    public const RESULTADOS = ['ENCONTRADO', 'EM_FALTA', 'DESLOCADO', 'EXTRA'];

    protected function casts(): array
    {
        return parent::casts() + [
            'inventario_fisico_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'porta_id' => 'integer',
            'andar_id' => 'integer',
            'porta_encontrada_id' => 'integer',
            'valor' => 'decimal:2',
        ];
    }

    public function inventario(): BelongsTo
    {
        return $this->belongsTo(InventarioFisicoAtivo::class, 'inventario_fisico_id');
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id')->withTrashed();
    }
}
