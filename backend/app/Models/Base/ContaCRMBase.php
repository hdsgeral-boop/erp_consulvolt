<?php

namespace App\Models\Base;

use App\Models\AtividadeComercialCRM;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ContactoCRM;
use App\Models\ModeloBase;
use App\Models\OportunidadeVendaCRM;
use App\Models\Terceiro;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela contas_crm (módulo CRM). Legado: crm_accounts · 5 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ContaCRM.
 */
abstract class ContaCRMBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'contas_crm';

    protected string $moduloAuditoria = 'CRM';

    protected $fillable = [
        'empresa_id', 'tipo', 'terceiro_id', 'nome', 'nif', 'email', 'telefone', 'morada', 'origem', 'origem_original', 'responsavel', 'criado_por', 'setor', 'website', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'terceiro_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function contactosCrm(): HasMany
    {
        return $this->hasMany(ContactoCRM::class, 'conta_crm_id');
    }

    public function oportunidadesVendaCrm(): HasMany
    {
        return $this->hasMany(OportunidadeVendaCRM::class, 'conta_crm_id');
    }

    public function atividadesComerciaisCrm(): HasMany
    {
        return $this->hasMany(AtividadeComercialCRM::class, 'conta_crm_id');
    }
}
