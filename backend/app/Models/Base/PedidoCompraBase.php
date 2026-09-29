<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\CotacaoCompra;
use App\Models\EncomendaCompra;
use App\Models\ItemCompra;
use App\Models\ItemVenda;
use App\Models\ModeloBase;
use App\Models\Projeto;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela pedidos_compra (módulo Compras). Legado: purchase_requests · 21 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PedidoCompra.
 */
abstract class PedidoCompraBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'pedidos_compra';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'nome_requerente', 'data', 'estado', 'estado_original', 'venda_origem_id', 'projeto_id', 'codigo_projeto', 'descricao', 'data_entrega', 'observacoes', 'deliberacao', 'unidade_negocio_id', 'centro_custo_id', 'data_prevista', 'criado_por', 'colaborador_requerente_id', 'numero_pedido', 'anulado_em', 'motivo_anulacao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data' => 'datetime',
            'venda_origem_id' => 'integer',
            'projeto_id' => 'integer',
            'data_entrega' => 'date',
            'deliberacao' => 'array',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'data_prevista' => 'date',
            'colaborador_requerente_id' => 'integer',
            'anulado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function vendaOrigem(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_origem_id');
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

    public function colaboradorRequerente(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_requerente_id');
    }

    public function itensVenda(): HasMany
    {
        return $this->hasMany(ItemVenda::class, 'pedido_compra_id');
    }

    public function cotacoesCompra(): HasMany
    {
        return $this->hasMany(CotacaoCompra::class, 'pedido_compra_id');
    }

    public function encomendasCompra(): HasMany
    {
        return $this->hasMany(EncomendaCompra::class, 'pedido_compra_id');
    }

    public function itensCompra(): HasMany
    {
        return $this->hasMany(ItemCompra::class, 'pedido_compra_id');
    }
}
