<?php

namespace App\Models;

use App\Models\Base\FaturacaoProjetoBase;

/**
 * faturacao_projetos — plano de facturação do projecto (/api/projetos/{id}/financeiro/plano): prestações/marcos previstos
 * (`montante` = valor previsto sem IVA, `data_prevista`, `retencao_pct`) e a factura de venda que as cumpre (`venda_id`).
 */
class FaturacaoProjeto extends FaturacaoProjetoBase
{
    public const ESTADOS = ['PLANEADO', 'FACTURADO', 'CANCELADO'];

    protected $fillable = ['empresa_id', 'projeto_id', 'numero_documento', 'data', 'montante', 'estado', 'descricao', 'marco_projeto_id', 'data_prevista', 'retencao_pct', 'venda_id'];

    protected function casts(): array
    {
        return parent::casts() + ['marco_projeto_id' => 'integer', 'data_prevista' => 'date', 'retencao_pct' => 'decimal:2', 'venda_id' => 'integer'];
    }
}
