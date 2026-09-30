<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaPrevisaoOrcamental;
use App\Models\ModeloBase;
use App\Models\PrevisaoOrcamental;
use App\Models\Projeto;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela previsoes_orcamentais (módulo Orçamento). Legado: orc_forecasts · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PrevisaoOrcamental.
 */
abstract class PrevisaoOrcamentalBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'previsoes_orcamentais';

    protected string $moduloAuditoria = 'Orçamento';

    protected $fillable = [
        'empresa_id', 'tipo', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'nome', 'mes_referencia', 'revisao', 'estado', 'metodo', 'crescimento_pct', 'notas', 'criado_por', 'revisao_origem_id', 'atualizado_por', 'publicado_por', 'publicado_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'projeto_id' => 'integer',
            'revisao' => 'integer',
            'crescimento_pct' => 'decimal:4',
            'revisao_origem_id' => 'integer',
            'publicado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
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

    public function revisaoOrigem(): BelongsTo
    {
        return $this->belongsTo(PrevisaoOrcamental::class, 'revisao_origem_id');
    }

    public function previsoesOrcamentaisPorRevisaoOrigem(): HasMany
    {
        return $this->hasMany(PrevisaoOrcamental::class, 'revisao_origem_id');
    }

    public function linhasPrevisaoOrcamental(): HasMany
    {
        return $this->hasMany(LinhaPrevisaoOrcamental::class, 'previsao_orcamental_id');
    }
}
