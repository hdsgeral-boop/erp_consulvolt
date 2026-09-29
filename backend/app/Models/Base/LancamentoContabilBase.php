<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\CorrespondenciaReconciliacao;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\ExecucaoConsolidacao;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\LancamentoContabil;
use App\Models\ModeloBase;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\SessaoPOS;
use App\Models\TarefaProjeto;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela lancamentos_contabeis (módulo Contabilidade). Legado: journal_lines · 44400 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LancamentoContabil.
 */
abstract class LancamentoContabilBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'lancamentos_contabeis';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'diario_id', 'data_documento', 'data_lancamento', 'referencia', 'numero_documento', 'descricao', 'valor', 'tipo_dc', 'codigo_conta', 'terceiro_id', 'nota_demonstracao_id', 'nota_fluxo_caixa_id', 'projeto_id', 'codigo_projeto', 'reconciliacao_codigo', 'periodo_id', 'referencia_documento', 'periodo_contabil', 'documento_origem_id', 'tipo_documento_origem', 'tipo_documento_origem_original', 'url_documento', 'fonte_dados', 'unidade_negocio_id', 'centro_custo_id', 'numero_lan', 'codigo_moeda', 'valor_moeda', 'taxa_cambio', 'taxa_cambio_id', 'taxa_cambio_manual', 'tipo_origem', 'sessao_pos_id', 'tarefa_projeto_id', 'empresa_origem_id', 'linha_origem_id', 'execucao_consolidacao_id', 'tipo_consolidacao', 'valor_kz_origem', 'empresa_intragrupo_id', 'item_acrescimo_diferimento_id', 'arredondamento_cambial', 'sistema_origem', 'nome_utilizador', 'valor_imposto', 'estorno_de_id', 'estornado_por_id', 'estornado_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'diario_id' => 'integer',
            'data_documento' => 'date',
            'data_lancamento' => 'datetime',
            'valor' => 'decimal:2',
            'terceiro_id' => 'integer',
            'nota_demonstracao_id' => 'integer',
            'nota_fluxo_caixa_id' => 'integer',
            'projeto_id' => 'integer',
            'periodo_id' => 'integer',
            'periodo_contabil' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'valor_moeda' => 'decimal:2',
            'taxa_cambio' => 'decimal:6',
            'taxa_cambio_id' => 'integer',
            'taxa_cambio_manual' => 'boolean',
            'sessao_pos_id' => 'integer',
            'tarefa_projeto_id' => 'integer',
            'empresa_origem_id' => 'integer',
            'linha_origem_id' => 'integer',
            'execucao_consolidacao_id' => 'integer',
            'valor_kz_origem' => 'decimal:2',
            'empresa_intragrupo_id' => 'integer',
            'item_acrescimo_diferimento_id' => 'integer',
            'arredondamento_cambial' => 'integer',
            'valor_imposto' => 'decimal:2',
            'estorno_de_id' => 'integer',
            'estornado_por_id' => 'integer',
            'estornado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function diario(): BelongsTo
    {
        return $this->belongsTo(DiarioContabil::class, 'diario_id');
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function notaDemonstracao(): BelongsTo
    {
        return $this->belongsTo(NotaDemonstracao::class, 'nota_demonstracao_id');
    }

    public function notaFluxoCaixa(): BelongsTo
    {
        return $this->belongsTo(NotaFluxoCaixa::class, 'nota_fluxo_caixa_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function taxaCambioRelacao(): BelongsTo
    {
        return $this->belongsTo(TaxaCambio::class, 'taxa_cambio_id');
    }

    public function sessaoPos(): BelongsTo
    {
        return $this->belongsTo(SessaoPOS::class, 'sessao_pos_id');
    }

    public function tarefaProjeto(): BelongsTo
    {
        return $this->belongsTo(TarefaProjeto::class, 'tarefa_projeto_id');
    }

    public function empresaOrigem(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_origem_id');
    }

    public function execucaoConsolidacao(): BelongsTo
    {
        return $this->belongsTo(ExecucaoConsolidacao::class, 'execucao_consolidacao_id');
    }

    public function empresaIntragrupo(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_intragrupo_id');
    }

    public function itemAcrescimoDiferimento(): BelongsTo
    {
        return $this->belongsTo(ItemAcrescimoDiferimento::class, 'item_acrescimo_diferimento_id');
    }

    public function estornoDe(): BelongsTo
    {
        return $this->belongsTo(LancamentoContabil::class, 'estorno_de_id');
    }

    public function estornadoPor(): BelongsTo
    {
        return $this->belongsTo(LancamentoContabil::class, 'estornado_por_id');
    }

    public function lancamentosContabeisPorEstornoDe(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'estorno_de_id');
    }

    public function lancamentosContabeisPorEstornadoPor(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'estornado_por_id');
    }

    public function correspondenciasReconciliacao(): HasMany
    {
        return $this->hasMany(CorrespondenciaReconciliacao::class, 'lancamento_contabil_id');
    }

    public function ativosImobilizados(): HasMany
    {
        return $this->hasMany(AtivoImobilizado::class, 'lancamento_contabil_id');
    }

    public function razaoAnaliticoProjetos(): HasMany
    {
        return $this->hasMany(RazaoAnaliticoProjeto::class, 'lancamento_contabil_id');
    }
}
