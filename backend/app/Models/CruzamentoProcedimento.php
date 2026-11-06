<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** procedimentos_cruzamentos — onde um procedimento toca noutro (ou num documento externo) e o que passa de um para o outro. */
class CruzamentoProcedimento extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'procedimentos_cruzamentos';

    protected $fillable = ['empresa_id', 'procedimento_id', 'ordem', 'com_chave', 'onde', 'o_que'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'procedimento_id' => 'integer', 'ordem' => 'integer'];
    }
}
