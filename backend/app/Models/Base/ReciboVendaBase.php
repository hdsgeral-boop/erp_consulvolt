<?php

namespace App\Models\Base;

use App\Models\Banco;
use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemReciboVenda;
use App\Models\ModeloBase;
use App\Models\Projeto;
use App\Models\SerieFaturacaoEletronica;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela recibos_venda (módulo Vendas). Legado: receipts · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ReciboVenda.
 */
abstract class ReciboVendaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'recibos_venda';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = [
        'empresa_id', 'cliente_id', 'unidade_negocio_id', 'centro_custo_id', 'numero_recibo', 'data', 'montante_total', 'meio_pagamento', 'banco_id', 'codigo_conta', 'referencia_pagamento', 'contabilizado', 'montante_total_moeda', 'referencia', 'projeto_id', 'codigo_projeto', 'estado', 'venda_origem_id', 'serie_faturacao_eletronica_id', 'numero_lan_contabilizacao', 'anulado_em', 'motivo_anulacao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'cliente_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'data' => 'date',
            'montante_total' => 'decimal:2',
            'banco_id' => 'integer',
            'contabilizado' => 'boolean',
            'montante_total_moeda' => 'decimal:2',
            'projeto_id' => 'integer',
            'venda_origem_id' => 'integer',
            'serie_faturacao_eletronica_id' => 'integer',
            'anulado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_id');
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class, 'banco_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function vendaOrigem(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_origem_id');
    }

    public function serieFaturacaoEletronica(): BelongsTo
    {
        return $this->belongsTo(SerieFaturacaoEletronica::class, 'serie_faturacao_eletronica_id');
    }

    public function itensReciboVenda(): HasMany
    {
        return $this->hasMany(ItemReciboVenda::class, 'recibo_venda_id');
    }
}
