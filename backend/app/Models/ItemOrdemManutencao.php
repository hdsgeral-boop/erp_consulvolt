<?php

namespace App\Models;

use App\Models\Base\ItemOrdemManutencaoBase;

/**
 * itens_ordem_manutencao — necessidades da ordem de manutenção (Fase F, Versão Actual; ServicoOrdensManutencao).
 * Legado novo: asset_maintenance_order_items (js/activos_manutencao.js:253-264, 502-511). As colunas da ligação à
 * requisição e ao pedido de compra (requisicao_id, purchase_request_id, qtd_armazem, qtd_compra) vêm da migração
 * 2026_10_14_300100_ff_activos_manutencao.
 */
class ItemOrdemManutencao extends ItemOrdemManutencaoBase
{
    public const TIPOS = ['MATERIAL', 'SERVICO'];

    public const ESTADOS = ['POR_PEDIR', 'REQUISITADO', 'EM_COMPRA', 'RECEBIDO', 'CANCELADO'];

    protected function casts(): array
    {
        return parent::casts() + [
            'requisicao_manutencao_id' => 'integer',
            'pedido_compra_id' => 'integer',
            'quantidade_armazem' => 'decimal:3',
            'quantidade_compra' => 'decimal:3',
        ];
    }
}
