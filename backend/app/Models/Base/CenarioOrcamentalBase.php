<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\OrcamentoAnual;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela cenarios_orcamentais (módulo Orçamento). Legado: orc_scenarios · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CenarioOrcamental.
 */
abstract class CenarioOrcamentalBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'cenarios_orcamentais';

    protected string $moduloAuditoria = 'Orçamento';

    protected $fillable = [
        'empresa_id', 'orcamento_anual_id', 'nome', 'tipo', 'variaveis', 'ajustes', 'notas', 'atualizado_por', 'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'orcamento_anual_id' => 'integer',
            'tipo' => 'array',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function orcamentoAnual(): BelongsTo
    {
        return $this->belongsTo(OrcamentoAnual::class, 'orcamento_anual_id');
    }
}
