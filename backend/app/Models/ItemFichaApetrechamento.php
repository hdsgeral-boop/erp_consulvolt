<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** itens_ficha_apetrechamento — linha de uma ficha-tipo (artigo ou descrição, quantidade inteira ≥ 1, obrigatório ou opcional). */
class ItemFichaApetrechamento extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'itens_ficha_apetrechamento';

    protected $fillable = ['empresa_id', 'ficha_id', 'produto_id', 'descricao', 'quantidade', 'obrigatorio', 'notas', 'ordem'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'ficha_id' => 'integer', 'produto_id' => 'integer', 'quantidade' => 'integer', 'obrigatorio' => 'boolean', 'ordem' => 'integer'];
    }
}
