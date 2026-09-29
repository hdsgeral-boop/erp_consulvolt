<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DiarioContabil;
use App\Models\ModeloBase;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela lancamentos_estornados (módulo Contabilidade). Legado: recycled_journal_lines · 2505 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LancamentoEstornado.
 */
abstract class LancamentoEstornadoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'lancamentos_estornados';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'diario_id', 'data_documento', 'data_lancamento', 'referencia', 'numero_documento', 'descricao', 'valor', 'tipo_dc', 'codigo_conta', 'terceiro_id', 'nota_demonstracao_id', 'nota_fluxo_caixa_id', 'url_documento', 'lancamento_original_id', 'reconciliacao_codigo', 'periodo_contabil', 'periodo_id', 'unidade_negocio_id', 'centro_custo_id', 'numero_lan', 'documento_origem_id', 'tipo_documento_origem', 'tipo_documento_origem_original', 'projeto_id', 'codigo_projeto', 'sistema_origem', 'nome_utilizador', 'valor_imposto',
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
            'lancamento_original_id' => 'integer',
            'periodo_contabil' => 'integer',
            'periodo_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'projeto_id' => 'integer',
            'valor_imposto' => 'decimal:2',
            'eliminado_em' => 'datetime',
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
}
