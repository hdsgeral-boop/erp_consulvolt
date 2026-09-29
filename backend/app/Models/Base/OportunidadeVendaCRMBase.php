<?php

namespace App\Models\Base;

use App\Models\AtividadeComercialCRM;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ContaCRM;
use App\Models\ContactoCRM;
use App\Models\FunilVendasCRM;
use App\Models\ModeloBase;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela oportunidades_venda_crm (módulo CRM). Legado: crm_opportunities · 5 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\OportunidadeVendaCRM.
 */
abstract class OportunidadeVendaCRMBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'oportunidades_venda_crm';

    protected string $moduloAuditoria = 'CRM';

    protected $fillable = [
        'empresa_id', 'funil_vendas_crm_id', 'conta_crm_id', 'contacto_crm_id', 'titulo', 'valor', 'probabilidade', 'data_fecho_prevista', 'responsavel', 'origem', 'origem_original', 'notas', 'itens', 'etapa_codigo', 'estado', 'historico', 'etapa_desde', 'vendas', 'criado_por', 'fechado_em', 'motivo_perda', 'concorrente', 'notas_perda', 'ultima_atividade_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'funil_vendas_crm_id' => 'integer',
            'conta_crm_id' => 'integer',
            'contacto_crm_id' => 'integer',
            'valor' => 'decimal:2',
            'probabilidade' => 'decimal:4',
            'data_fecho_prevista' => 'date',
            'itens' => 'array',
            'historico' => 'array',
            'etapa_desde' => 'datetime',
            'vendas' => 'array',
            'fechado_em' => 'datetime',
            'ultima_atividade_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function funilVendasCrm(): BelongsTo
    {
        return $this->belongsTo(FunilVendasCRM::class, 'funil_vendas_crm_id');
    }

    public function contaCrm(): BelongsTo
    {
        return $this->belongsTo(ContaCRM::class, 'conta_crm_id');
    }

    public function contactoCrm(): BelongsTo
    {
        return $this->belongsTo(ContactoCRM::class, 'contacto_crm_id');
    }

    public function vendas2(): HasMany
    {
        return $this->hasMany(Venda::class, 'oportunidade_crm_id');
    }

    public function atividadesComerciaisCrm(): HasMany
    {
        return $this->hasMany(AtividadeComercialCRM::class, 'oportunidade_crm_id');
    }
}
