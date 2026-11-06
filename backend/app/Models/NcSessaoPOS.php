<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** ncs_sessao_pos — nota de crédito sobre factura-recibo de sessão POS, com a repartição por meio (F6, Q17). Migração 2026_12_11_090000. */
class NcSessaoPOS extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'ncs_sessao_pos';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = ['empresa_id', 'sessao_pos_id', 'venda_nc_id', 'venda_origem_id', 'valor', 'distribuicao', 'data', 'abatida_em_aberta', 'estado', 'numero_lan', 'conta_cliente'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'sessao_pos_id' => 'integer', 'venda_nc_id' => 'integer', 'venda_origem_id' => 'integer', 'valor' => 'decimal:2',
            'distribuicao' => 'array', 'data' => 'date', 'abatida_em_aberta' => 'boolean'];
    }
}
