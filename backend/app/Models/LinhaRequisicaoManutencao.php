<?php

namespace App\Models;

use App\Models\Base\LinhaRequisicaoManutencaoBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * linhas_requisicao_manutencao — linhas da requisição ao armazém (Fase F, Versão Actual).
 * Legado novo: maint_requisition_lines (js/activos_manutencao.js:466-471, 567-572).
 */
class LinhaRequisicaoManutencao extends LinhaRequisicaoManutencaoBase
{
    protected function casts(): array
    {
        return parent::casts() + [
            'requisicao_manutencao_id' => 'integer',
            'item_ordem_manutencao_id' => 'integer',
            'produto_id' => 'integer',
            'quantidade' => 'decimal:3',
            'quantidade_entregue' => 'decimal:3',
            'custo' => 'decimal:2',
        ];
    }

    public function requisicao(): BelongsTo
    {
        return $this->belongsTo(RequisicaoManutencao::class, 'requisicao_manutencao_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }
}
