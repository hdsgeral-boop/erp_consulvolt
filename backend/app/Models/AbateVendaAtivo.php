<?php

namespace App\Models;

use App\Models\Base\AbateVendaAtivoBase;

/**
 * abates_vendas_ativos — /api/ativos/abates (ServicoAbatesAtivos).
 * O lançamento do abate (quando contabilizado) tem o n.º de documento "ABT-<id>" no diário AM.
 */
class AbateVendaAtivo extends AbateVendaAtivoBase
{
    public const TIPOS = ['SINISTRO', 'VENDA', 'FIM_VIDA'];

    public const TIPO_ORIGEM = 'ABATE_ATIVO';

    public function numeroDocumento(): string
    {
        return 'ABT-'.$this->id;
    }
}
