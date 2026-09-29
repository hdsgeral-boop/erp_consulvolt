<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DiarioContabil;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela periodos_lancamento_acrescimos (módulo Acréscimos). Legado: ad_postings · 8 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PeriodoLancamentoAcrescimo.
 */
abstract class PeriodoLancamentoAcrescimoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'periodos_lancamento_acrescimos';

    protected string $moduloAuditoria = 'Acréscimos';

    protected $fillable = [
        'empresa_id', 'item_acrescimo_diferimento_id', 'periodo', 'tipo', 'valor', 'diario_id', 'numero_lan', 'numero_documento', 'data_documento', 'estado', 'por', 'em', 'diferenca', 'anulado_por', 'anulado_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'item_acrescimo_diferimento_id' => 'integer',
            'valor' => 'decimal:2',
            'diario_id' => 'integer',
            'data_documento' => 'date',
            'em' => 'datetime',
            'diferenca' => 'decimal:2',
            'anulado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function itemAcrescimoDiferimento(): BelongsTo
    {
        return $this->belongsTo(ItemAcrescimoDiferimento::class, 'item_acrescimo_diferimento_id');
    }

    public function diario(): BelongsTo
    {
        return $this->belongsTo(DiarioContabil::class, 'diario_id');
    }
}
