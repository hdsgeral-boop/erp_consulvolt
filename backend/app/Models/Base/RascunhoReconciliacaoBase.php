<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela rascunhos_reconciliacao (módulo Tesouraria). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RascunhoReconciliacao.
 */
abstract class RascunhoReconciliacaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'rascunhos_reconciliacao';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'codigo_conta', 'periodo_inicio', 'periodo_fim', 'grupos', 'observacoes', 'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'periodo_inicio' => 'date',
            'periodo_fim' => 'date',
            'grupos' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
