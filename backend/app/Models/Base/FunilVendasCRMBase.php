<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\OportunidadeVendaCRM;
use App\Models\SequenciaCampanhaCRM;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela funis_vendas_crm (módulo CRM). Legado: crm_pipelines · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\FunilVendasCRM.
 */
abstract class FunilVendasCRMBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'funis_vendas_crm';

    protected string $moduloAuditoria = 'CRM';

    protected $fillable = [
        'empresa_id', 'nome', 'ordem', 'ativo', 'etapas',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ordem' => 'integer',
            'ativo' => 'boolean',
            'etapas' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function oportunidadesVendaCrm(): HasMany
    {
        return $this->hasMany(OportunidadeVendaCRM::class, 'funil_vendas_crm_id');
    }

    public function sequenciasCampanhasCrm(): HasMany
    {
        return $this->hasMany(SequenciaCampanhaCRM::class, 'funil_vendas_crm_id');
    }
}
