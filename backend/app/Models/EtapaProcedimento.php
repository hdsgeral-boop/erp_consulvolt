<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** procedimentos_etapas — etapas de um procedimento editado ou próprio (a gravação substitui o conjunto). */
class EtapaProcedimento extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'procedimentos_etapas';

    protected $fillable = ['empresa_id', 'procedimento_id', 'ordem', 'nome', 'quem', 'descricao', 'controlos', 'resultado'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'procedimento_id' => 'integer', 'ordem' => 'integer'];
    }
}
