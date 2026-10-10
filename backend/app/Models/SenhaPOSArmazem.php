<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/**
 * senhas_pos_armazem — senhas de atendimento do POS de armazém (Monitor de Chamada de Senhas, RELATORIO 2.6.1).
 */
class SenhaPOSArmazem extends ModeloBase
{
    use PertenceEmpresa;

    public const AGUARDA = 'AGUARDA';

    public const CHAMADA = 'CHAMADA';

    protected $table = 'senhas_pos_armazem';

    protected $fillable = ['empresa_id', 'armazem_id', 'data', 'numero', 'codigo', 'estado', 'chamada_em', 'chamadas', 'criado_por', 'chamado_por'];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'armazem_id' => 'integer',
            'data' => 'date:Y-m-d',
            'numero' => 'integer',
            'chamadas' => 'integer',
            'chamada_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
