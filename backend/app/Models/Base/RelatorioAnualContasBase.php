<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela relatorios_anuais_contas (módulo Contabilidade). Legado: annual_reports · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RelatorioAnualContas.
 */
abstract class RelatorioAnualContasBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'relatorios_anuais_contas';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'ano_relatorio', 'estado', 'estado_original', 'configuracao', 'textos', 'notas_incluir', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ano_relatorio' => 'integer',
            'configuracao' => 'array',
            'textos' => 'array',
            'notas_incluir' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
