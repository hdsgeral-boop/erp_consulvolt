<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** historico_reserva_imo — trilho imutável dos eventos da reserva (criação, estados, factura, recebimento, caução, vistoria). */
class HistoricoReservaImo extends ModeloBase
{
    use PertenceEmpresa;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'em';

    protected $table = 'historico_reserva_imo';

    protected $fillable = ['empresa_id', 'reserva_id', 'evento', 'estado_de', 'estado_para', 'motivo', 'dados', 'utilizador_id', 'em'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'reserva_id' => 'integer', 'utilizador_id' => 'integer', 'dados' => 'array', 'em' => 'datetime'];
    }
}
