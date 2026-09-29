<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela saldos_historicos (módulo Contabilidade). Legado: historical_balances · 114 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\SaldoHistorico.
 */
abstract class SaldoHistoricoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'saldos_historicos';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'ano', 'tipo', 'tipo_original', 'codigo', 'valor',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ano' => 'integer',
            'valor' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
