<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** movimentos_caucao_reserva — ledger da caução de uma reserva (recebida, devolvida, retida); imutável: corrige-se anulando (estorno), nunca apagando. */
class MovimentoCaucaoReserva extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const TIPOS = ['RECEBIDA', 'DEVOLVIDA', 'RETIDA'];

    protected $table = 'movimentos_caucao_reserva';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'reserva_id', 'tipo', 'valor', 'data', 'conta_financeira', 'motivo', 'chave', 'estado', 'lancamento_numero', 'estorno_numero', 'venda_id',
        'anulado_por', 'anulado_em', 'anulado_motivo', 'criado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'reserva_id' => 'integer', 'venda_id' => 'integer', 'valor' => 'float', 'data' => 'date:Y-m-d', 'anulado_em' => 'datetime'];
    }
}
