<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_crm (módulo CRM). Legado: crm_settings · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfiguracaoCRM.
 */
abstract class ConfiguracaoCRMBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_crm';

    protected string $moduloAuditoria = 'CRM';

    protected $fillable = [
        'empresa_id', 'motivos_perda', 'origens', 'dias_sem_atividade', 'prazo_pagamento_dias',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'dias_sem_atividade' => 'integer',
            'prazo_pagamento_dias' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
