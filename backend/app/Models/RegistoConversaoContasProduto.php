<?php

namespace App\Models;

use App\Models\Base\RegistoConversaoContasProdutoBase;

/**
 * registos_conversao_contas_produtos — um registo por produto convertido pelo comando
 * erp:produtos:converter-contas-categorias (ronda 3): categoria anterior, categoria atribuída, contas copiadas e avisos.
 * Torna a conversão idempotente (um produto já registado não volta a ser convertido) e serve de relatório.
 * Estrutura, $fillable e relações em RegistoConversaoContasProdutoBase (gerado — TABELAS_NOVAS, ronda 4).
 */
class RegistoConversaoContasProduto extends RegistoConversaoContasProdutoBase {}
