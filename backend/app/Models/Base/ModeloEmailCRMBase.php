<?php

namespace App\Models\Base;

use App\Models\AtividadeComercialCRM;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela modelos_email_crm (módulo CRM). Legado: crm_templates · 6 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ModeloEmailCRM.
 */
abstract class ModeloEmailCRMBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'modelos_email_crm';

    protected string $moduloAuditoria = 'CRM';

    protected $fillable = [
        'empresa_id', 'nome', 'assunto', 'corpo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function atividadesComerciaisCrm(): HasMany
    {
        return $this->hasMany(AtividadeComercialCRM::class, 'modelo_email_crm_id');
    }
}
