<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

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
        'empresa_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
