<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LancamentoContabil;
use App\Models\ModeloBase;
use App\Models\PeriodoLancamentoAcrescimo;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela itens_acrescimos_diferimentos (módulo Acréscimos). Legado: ad_items · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ItemAcrescimoDiferimento.
 */
abstract class ItemAcrescimoDiferimentoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'itens_acrescimos_diferimentos';

    protected string $moduloAuditoria = 'Acréscimos';

    protected $fillable = [
        'empresa_id', 'tipo', 'natureza', 'descricao', 'valor', 'conta_resultado', 'conta_balanco', 'data_inicio', 'data_fim', 'reparticao', 'data_documento', 'data_limite', 'documento_em_balanco', 'terceiro_id', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'origem', 'notas', 'atualizado_por', 'estado', 'criado_por', 'regularizacao', 'termino',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'valor' => 'decimal:2',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'data_documento' => 'date',
            'data_limite' => 'date',
            'documento_em_balanco' => 'boolean',
            'terceiro_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'projeto_id' => 'integer',
            'origem' => 'array',
            'regularizacao' => 'array',
            'termino' => 'array',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'item_acrescimo_diferimento_id');
    }

    public function periodosLancamentoAcrescimos(): HasMany
    {
        return $this->hasMany(PeriodoLancamentoAcrescimo::class, 'item_acrescimo_diferimento_id');
    }
}
