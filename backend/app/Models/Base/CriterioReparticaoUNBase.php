<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela criterios_reparticao_un (módulo Sistema). Legado: bu_allocation_keys · 4 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CriterioReparticaoUN.
 */
abstract class CriterioReparticaoUNBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'criterios_reparticao_un';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'codigo', 'nome', 'criterio', 'indicador', 'periodo_de', 'periodo_ate', 'linhas', 'notas', 'ativo', 'atualizado_por', 'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'linhas' => 'array',
            'ativo' => 'boolean',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
