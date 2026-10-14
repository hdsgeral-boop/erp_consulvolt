<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela requisicoes_manutencao (módulo Activos). Legado: maint_requisitions · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RequisicaoManutencao.
 */
abstract class RequisicaoManutencaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'requisicoes_manutencao';

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
