<?php

namespace App\Models;

use App\Models\Base\CategoriaAtivoBase;

/**
 * categorias_ativos — /api/ativos/categorias (ServicoCategoriasAtivos).
 * Taxa anual e vida útil padrão; contas: gasto (73), amortização acumulada (18), venda (6), perda (7) e activo (1).
 */
class CategoriaAtivo extends CategoriaAtivoBase {}
