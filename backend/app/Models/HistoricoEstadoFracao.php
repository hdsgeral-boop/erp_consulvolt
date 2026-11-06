<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** historico_estado_fracao — trilho imutável das mudanças de estado de uma fracção (substitui estado_desde/estado_motivo do legado). */
class HistoricoEstadoFracao extends ModeloBase
{
    use PertenceEmpresa;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'em';

    protected $table = 'historico_estado_fracao';

    protected $fillable = ['empresa_id', 'fracao_id', 'estado_de', 'estado_para', 'motivo', 'utilizador_id', 'em'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'fracao_id' => 'integer', 'utilizador_id' => 'integer', 'em' => 'datetime'];
    }
}
