<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela reconciliacoes_bancarias (módulo Tesouraria). Legado: reconciliations · 2469 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ReconciliacaoBancaria.
 */
abstract class ReconciliacaoBancariaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'reconciliacoes_bancarias';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'reconciliacao_codigo', 'data', 'valor_total', 'estado', 'importacao_codigo', 'tipo', 'tipo_original', 'detalhes',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data' => 'datetime',
            'valor_total' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
