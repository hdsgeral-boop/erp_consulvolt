<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\FaturaCompra;
use App\Models\LiquidacaoPOS;
use App\Models\ModeloBase;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela movimentos_caixa (módulo Tesouraria). Legado: cash_lines · 186 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\MovimentoCaixa.
 */
abstract class MovimentoCaixaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'movimentos_caixa';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'sessao_caixa_id', 'tipo', 'tipo_original', 'data_documento', 'numero_documento', 'referencia', 'terceiro_id', 'produto_id', 'conta_debito', 'conta_credito', 'descricao', 'valor', 'unidade_negocio_id', 'centro_custo_id', 'tipo_origem', 'tipo_origem_original', 'origem_id', 'contabilizado', 'codigo_moeda', 'taxa_cambio', 'taxa_cambio_id', 'valor_kz', 'nota_demonstracao_id', 'nota_fluxo_caixa_id', 'url_documento', 'contravalor_kz', 'contra_moeda', 'contravalor_moeda', 'venda_id', 'fatura_compra_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'sessao_caixa_id' => 'integer',
            'data_documento' => 'date',
            'terceiro_id' => 'integer',
            'produto_id' => 'integer',
            'valor' => 'decimal:2',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'origem_id' => 'integer',
            'contabilizado' => 'boolean',
            'taxa_cambio' => 'decimal:6',
            'taxa_cambio_id' => 'integer',
            'valor_kz' => 'decimal:2',
            'nota_demonstracao_id' => 'integer',
            'nota_fluxo_caixa_id' => 'integer',
            'contravalor_kz' => 'decimal:2',
            'venda_id' => 'integer',
            'fatura_compra_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
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

    public function notaDemonstracao(): BelongsTo
    {
        return $this->belongsTo(NotaDemonstracao::class, 'nota_demonstracao_id');
    }

    public function notaFluxoCaixa(): BelongsTo
    {
        return $this->belongsTo(NotaFluxoCaixa::class, 'nota_fluxo_caixa_id');
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_id');
    }

    public function faturaCompra(): BelongsTo
    {
        return $this->belongsTo(FaturaCompra::class, 'fatura_compra_id');
    }

    public function liquidacoesPos(): HasMany
    {
        return $this->hasMany(LiquidacaoPOS::class, 'movimento_caixa_id');
    }
}
