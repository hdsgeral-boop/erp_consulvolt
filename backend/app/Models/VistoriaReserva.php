<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** vistorias_reserva — vistoria de entrada (ANTES) ou de saída (DEPOIS) de uma reserva; a última de cada momento é a que vale. A de saída é a prova da retenção da caução. */
class VistoriaReserva extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const QUANDO = ['ANTES', 'DEPOIS'];

    public const ESTADOS = ['BOM', 'SUJO', 'DANIFICADO', 'EM_FALTA'];

    protected $table = 'vistorias_reserva';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'reserva_id', 'quando', 'estado', 'observacoes', 'url', 'data', 'custo_danos', 'criado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'reserva_id' => 'integer', 'data' => 'date:Y-m-d', 'custo_danos' => 'float'];
    }
}
