<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * movimentos_caixa_pos — suprimentos e sangrias da sessão POS (F1b). Tabela criada pela migração 2026_12_09_090000
 * (não vem do dicionário do legado, que não tinha estes movimentos).
 */
class MovimentoCaixaPOS extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const SUPRIMENTO = 'SUPRIMENTO';

    public const SANGRIA = 'SANGRIA';

    protected $table = 'movimentos_caixa_pos';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = ['empresa_id', 'sessao_pos_id', 'tipo', 'valor', 'motivo', 'data', 'meio_id', 'conta_transitoria', 'conta_contrapartida', 'operador_id',
        'nome_operador', 'estado', 'anulado_em', 'anulado_por', 'motivo_anulacao'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'sessao_pos_id' => 'integer', 'valor' => 'decimal:2', 'data' => 'date', 'operador_id' => 'integer', 'anulado_em' => 'datetime'];
    }
}
