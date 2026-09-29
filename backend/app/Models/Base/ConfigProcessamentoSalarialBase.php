<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_processamento_salarial (módulo RH). Legado: pa_settings · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfigProcessamentoSalarial.
 */
abstract class ConfigProcessamentoSalarialBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_processamento_salarial';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'niveis', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'niveis' => 'array',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
