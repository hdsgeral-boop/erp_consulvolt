<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** pagamentos_negocio_imo — sinal e prestações de um negócio (pagos na compra, recebidos na venda). Anula-se com motivo, nunca se apaga. */
class PagamentoNegocioImo extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const TIPOS = ['SINAL', 'PRESTACAO', 'SALDO'];

    protected $table = 'pagamentos_negocio_imo';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'negocio_id', 'tipo', 'valor', 'data', 'meio', 'referencia', 'estado', 'chave', 'anulado_motivo', 'anulado_por', 'anulado_em', 'criado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'negocio_id' => 'integer', 'valor' => 'float', 'data' => 'date:Y-m-d', 'anulado_em' => 'datetime'];
    }
}
