<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** movimentos_caucao_imo — ledger da caução do contrato (recebida, devolvida, retida); imutável: corrige-se anulando (estorno), nunca apagando. */
class MovimentoCaucaoImo extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const TIPOS = ['RECEBIDA', 'DEVOLVIDA', 'RETIDA'];

    protected $table = 'movimentos_caucao_imo';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'contrato_id', 'tipo', 'valor', 'data', 'conta_financeira', 'motivo', 'chave', 'estado', 'lancamento_numero', 'estorno_numero',
        'anulado_por', 'anulado_em', 'anulado_motivo', 'criado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'contrato_id' => 'integer', 'valor' => 'float', 'data' => 'date:Y-m-d', 'anulado_em' => 'datetime'];
    }
}
