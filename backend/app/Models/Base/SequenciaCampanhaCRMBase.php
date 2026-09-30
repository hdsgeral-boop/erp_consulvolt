<?php

namespace App\Models\Base;

use App\Models\AtividadeComercialCRM;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\FunilVendasCRM;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela sequencias_campanhas_crm (módulo CRM). Legado: crm_sequences · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\SequenciaCampanhaCRM.
 */
abstract class SequenciaCampanhaCRMBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'sequencias_campanhas_crm';

    protected string $moduloAuditoria = 'CRM';

    protected $fillable = [
        'empresa_id', 'nome', 'funil_vendas_crm_id', 'etapa_codigo', 'ativo', 'passos',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'funil_vendas_crm_id' => 'integer',
            'ativo' => 'boolean',
            'passos' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function funilVendasCrm(): BelongsTo
    {
        return $this->belongsTo(FunilVendasCRM::class, 'funil_vendas_crm_id');
    }

    public function atividadesComerciaisCrm(): HasMany
    {
        return $this->hasMany(AtividadeComercialCRM::class, 'sequencia_campanha_id');
    }
}
