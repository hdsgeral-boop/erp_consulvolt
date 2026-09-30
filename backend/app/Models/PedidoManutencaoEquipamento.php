<?php

namespace App\Models;

use App\Models\Base\PedidoManutencaoEquipamentoBase;

/**
 * pedidos_manutencao_equipamentos — apesar do nome contratual, são os pedidos de MANUTENÇÃO DE DADOS com aprovação
 * dupla do legado (js/manutencao.js; ADR-021), não manutenção de equipamentos. Pertence a Sistema › Manutenção de dados.
 */
class PedidoManutencaoEquipamento extends PedidoManutencaoEquipamentoBase {}
