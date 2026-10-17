<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/**
 * registos_conversao_contas_produtos — um registo por produto convertido pelo comando
 * erp:produtos:converter-contas-categorias (ronda 3): categoria anterior, categoria atribuída, contas copiadas e avisos.
 * Torna a conversão idempotente (um produto já registado não volta a ser convertido) e serve de relatório.
 */
class RegistoConversaoContasProduto extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'registos_conversao_contas_produtos';

    protected $fillable = ['empresa_id', 'produto_id', 'categoria_anterior_id', 'categoria_nova_id', 'categoria_criada', 'contas', 'avisos', 'executado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'produto_id' => 'integer', 'categoria_anterior_id' => 'integer', 'categoria_nova_id' => 'integer',
            'categoria_criada' => 'boolean', 'contas' => 'array', 'avisos' => 'array', 'criado_em' => 'datetime', 'atualizado_em' => 'datetime'];
    }
}
