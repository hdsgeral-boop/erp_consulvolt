<?php

namespace App\Models\Base;

use App\Models\CenarioOrcamental;
use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaOrcamento;
use App\Models\LogAlertaOrcamental;
use App\Models\ModeloBase;
use App\Models\OrcamentoAnual;
use App\Models\PedidoExtrapolacaoOrcamento;
use App\Models\Projeto;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela orcamentos_anuais (módulo Orçamento). Legado: orc_budgets · 8 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\OrcamentoAnual.
 */
abstract class OrcamentoAnualBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'orcamentos_anuais';

    protected string $moduloAuditoria = 'Orçamento';

    protected $fillable = [
        'empresa_id', 'ano', 'tipo', 'tipo_original', 'unidade_negocio_id', 'centro_custo_id', 'nome', 'descricao', 'versao', 'versao_origem_id', 'estado', 'estado_original', 'criado_por', 'rejeicoes', 'atualizado_por', 'abordagem', 'dimensao_filhos', 'projeto_id', 'metodo', 'origem', 'crescimento_proveitos_pct', 'crescimento_custos_pct', 'inflacao_pct', 'responsavel', 'orcamento_pai_id', 'saldo_inicial', 'submetido_por', 'submetido_em', 'substituido_por_id', 'substituido_em', 'aprovado_por', 'aprovado_em', 'prazo_contributo', 'consolidado_em', 'consolidado_por', 'consolidado_ids',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ano' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'versao' => 'integer',
            'versao_origem_id' => 'integer',
            'rejeicoes' => 'array',
            'projeto_id' => 'integer',
            'crescimento_proveitos_pct' => 'decimal:4',
            'crescimento_custos_pct' => 'decimal:4',
            'inflacao_pct' => 'decimal:4',
            'orcamento_pai_id' => 'integer',
            'saldo_inicial' => 'decimal:2',
            'submetido_em' => 'datetime',
            'substituido_por_id' => 'integer',
            'substituido_em' => 'datetime',
            'aprovado_em' => 'datetime',
            'prazo_contributo' => 'date',
            'consolidado_em' => 'datetime',
            'consolidado_ids' => 'array',
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

    public function versaoOrigem(): BelongsTo
    {
        return $this->belongsTo(OrcamentoAnual::class, 'versao_origem_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function orcamentoPai(): BelongsTo
    {
        return $this->belongsTo(OrcamentoAnual::class, 'orcamento_pai_id');
    }

    public function substituidoPor(): BelongsTo
    {
        return $this->belongsTo(OrcamentoAnual::class, 'substituido_por_id');
    }

    public function orcamentosAnuaisPorVersaoOrigem(): HasMany
    {
        return $this->hasMany(OrcamentoAnual::class, 'versao_origem_id');
    }

    public function orcamentosAnuaisPorOrcamentoPai(): HasMany
    {
        return $this->hasMany(OrcamentoAnual::class, 'orcamento_pai_id');
    }

    public function orcamentosAnuaisPorSubstituidoPor(): HasMany
    {
        return $this->hasMany(OrcamentoAnual::class, 'substituido_por_id');
    }

    public function linhasOrcamento(): HasMany
    {
        return $this->hasMany(LinhaOrcamento::class, 'orcamento_anual_id');
    }

    public function cenariosOrcamentais(): HasMany
    {
        return $this->hasMany(CenarioOrcamental::class, 'orcamento_anual_id');
    }

    public function pedidosExtrapolacaoOrcamento(): HasMany
    {
        return $this->hasMany(PedidoExtrapolacaoOrcamento::class, 'orcamento_anual_id');
    }

    public function logsAlertasOrcamentais(): HasMany
    {
        return $this->hasMany(LogAlertaOrcamental::class, 'orcamento_anual_id');
    }
}
