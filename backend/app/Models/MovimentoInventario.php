<?php

namespace App\Models;

use App\Models\Base\MovimentoInventarioBase;

/**
 * movimentos_inventario — /api/logistica/paridade/movimentos e /api/logistica/produtos/{id}/extracto (a lista genérica
 * /api/logistica/movimentos, sem consumidor, foi retirada na ronda 4).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em MovimentoInventarioBase (gerado).
 */
class MovimentoInventario extends MovimentoInventarioBase {}
