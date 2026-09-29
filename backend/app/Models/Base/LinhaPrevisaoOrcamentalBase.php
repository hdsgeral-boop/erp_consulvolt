<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PrevisaoOrcamental;
use App\Models\RubricaOrcamental;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela linhas_previsao_orcamental (módulo Orçamento). Legado: orc_forecast_lines · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaPrevisaoOrcamental.
 */
abstract class LinhaPrevisaoOrcamentalBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_previsao_orcamental';

    protected string $moduloAuditoria = 'Orçamento';

    protected $fillable = [
        'empresa_id', 'previsao_orcamental_id', 'rubrica_orcamental_id', 'valores',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'previsao_orcamental_id' => 'integer',
            'rubrica_orcamental_id' => 'integer',
            'valores' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function previsaoOrcamental(): BelongsTo
    {
        return $this->belongsTo(PrevisaoOrcamental::class, 'previsao_orcamental_id');
    }

    public function rubricaOrcamental(): BelongsTo
    {
        return $this->belongsTo(RubricaOrcamental::class, 'rubrica_orcamental_id');
    }
}
