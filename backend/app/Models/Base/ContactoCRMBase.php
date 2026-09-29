<?php

namespace App\Models\Base;

use App\Models\AtividadeComercialCRM;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ContaCRM;
use App\Models\ModeloBase;
use App\Models\OportunidadeVendaCRM;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela contactos_crm (módulo CRM). Legado: crm_contacts · 4 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ContactoCRM.
 */
abstract class ContactoCRMBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'contactos_crm';

    protected string $moduloAuditoria = 'CRM';

    protected $fillable = [
        'empresa_id', 'conta_crm_id', 'nome', 'cargo', 'email', 'telefone', 'principal',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'conta_crm_id' => 'integer',
            'principal' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function contaCrm(): BelongsTo
    {
        return $this->belongsTo(ContaCRM::class, 'conta_crm_id');
    }

    public function oportunidadesVendaCrm(): HasMany
    {
        return $this->hasMany(OportunidadeVendaCRM::class, 'contacto_crm_id');
    }

    public function atividadesComerciaisCrm(): HasMany
    {
        return $this->hasMany(AtividadeComercialCRM::class, 'contacto_crm_id');
    }
}
