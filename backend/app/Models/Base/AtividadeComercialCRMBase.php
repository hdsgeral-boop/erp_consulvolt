<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ContaCRM;
use App\Models\ContactoCRM;
use App\Models\ModeloBase;
use App\Models\ModeloEmailCRM;
use App\Models\OportunidadeVendaCRM;
use App\Models\SequenciaCampanhaCRM;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela atividades_comerciais_crm (módulo CRM). Legado: crm_activities · 45 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AtividadeComercialCRM.
 */
abstract class AtividadeComercialCRMBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'atividades_comerciais_crm';

    protected string $moduloAuditoria = 'CRM';

    protected $fillable = [
        'empresa_id', 'oportunidade_crm_id', 'conta_crm_id', 'contacto_crm_id', 'tipo', 'titulo', 'descricao', 'data_prevista', 'concluida', 'responsavel', 'automatica', 'modelo_email_crm_id', 'criado_por', 'concluida_em', 'resultado', 'concluida_por', 'sequencia_campanha_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'oportunidade_crm_id' => 'integer',
            'conta_crm_id' => 'integer',
            'contacto_crm_id' => 'integer',
            'data_prevista' => 'date',
            'concluida' => 'boolean',
            'automatica' => 'boolean',
            'modelo_email_crm_id' => 'integer',
            'concluida_em' => 'datetime',
            'sequencia_campanha_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function oportunidadeCrm(): BelongsTo
    {
        return $this->belongsTo(OportunidadeVendaCRM::class, 'oportunidade_crm_id');
    }

    public function contaCrm(): BelongsTo
    {
        return $this->belongsTo(ContaCRM::class, 'conta_crm_id');
    }

    public function contactoCrm(): BelongsTo
    {
        return $this->belongsTo(ContactoCRM::class, 'contacto_crm_id');
    }

    public function modeloEmailCrm(): BelongsTo
    {
        return $this->belongsTo(ModeloEmailCRM::class, 'modelo_email_crm_id');
    }

    public function sequenciaCampanha(): BelongsTo
    {
        return $this->belongsTo(SequenciaCampanhaCRM::class, 'sequencia_campanha_id');
    }
}
