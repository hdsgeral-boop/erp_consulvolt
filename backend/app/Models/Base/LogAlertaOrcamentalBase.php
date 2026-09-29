<?php

namespace App\Models\Base;

use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\OrcamentoAnual;
use App\Models\RubricaOrcamental;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela logs_alertas_orcamentais (módulo Orçamento). Legado: orc_alert_log · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LogAlertaOrcamental.
 */
abstract class LogAlertaOrcamentalBase extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'logs_alertas_orcamentais';

    protected $fillable = [
        'empresa_id', 'em', 'por', 'origem', 'documento', 'rubrica_orcamental_id', 'orcamento_anual_id', 'percentagem', 'estado', 'acao', 'valor',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'em' => 'datetime',
            'rubrica_orcamental_id' => 'integer',
            'orcamento_anual_id' => 'integer',
            'percentagem' => 'decimal:4',
            'valor' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function rubricaOrcamental(): BelongsTo
    {
        return $this->belongsTo(RubricaOrcamental::class, 'rubrica_orcamental_id');
    }

    public function orcamentoAnual(): BelongsTo
    {
        return $this->belongsTo(OrcamentoAnual::class, 'orcamento_anual_id');
    }
}
