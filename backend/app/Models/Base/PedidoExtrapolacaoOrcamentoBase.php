<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\OrcamentoAnual;
use App\Models\RubricaOrcamental;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela pedidos_extrapolacao_orcamento (módulo Orçamento). Legado: orc_excess_requests · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PedidoExtrapolacaoOrcamento.
 */
abstract class PedidoExtrapolacaoOrcamentoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'pedidos_extrapolacao_orcamento';

    protected string $moduloAuditoria = 'Orçamento';

    protected $fillable = [
        'empresa_id', 'estado', 'rubrica_orcamental_id', 'orcamento_anual_id', 'origem', 'documento', 'chave_documento', 'data_documento', 'valor', 'valor_orcado', 'valor_consumido', 'percentagem', 'valor_excesso', 'motivo', 'pedido_por', 'pedido_em', 'decidido_por', 'decidido_em', 'nota_decisao', 'autoaprovado', 'utilizado_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'rubrica_orcamental_id' => 'integer',
            'orcamento_anual_id' => 'integer',
            'data_documento' => 'date',
            'valor' => 'decimal:2',
            'valor_orcado' => 'decimal:2',
            'valor_consumido' => 'decimal:2',
            'percentagem' => 'decimal:4',
            'valor_excesso' => 'decimal:2',
            'pedido_em' => 'datetime',
            'decidido_em' => 'datetime',
            'utilizado_em' => 'datetime',
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
