<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\OrcamentoAnual;
use App\Models\RubricaOrcamental;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela linhas_orcamento (módulo Orçamento). Legado: orc_lines · 6 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaOrcamento.
 */
abstract class LinhaOrcamentoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_orcamento';

    protected string $moduloAuditoria = 'Orçamento';

    protected $fillable = [
        'empresa_id', 'orcamento_anual_id', 'rubrica_orcamental_id', 'valores', 'total', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'orcamento_anual_id' => 'integer',
            'rubrica_orcamental_id' => 'integer',
            'valores' => 'array',
            'total' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function orcamentoAnual(): BelongsTo
    {
        return $this->belongsTo(OrcamentoAnual::class, 'orcamento_anual_id');
    }

    public function rubricaOrcamental(): BelongsTo
    {
        return $this->belongsTo(RubricaOrcamental::class, 'rubrica_orcamental_id');
    }
}
