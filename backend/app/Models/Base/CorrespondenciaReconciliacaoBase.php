<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LancamentoContabil;
use App\Models\LinhaExtratoBancario;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela correspondencias_reconciliacao (módulo Tesouraria). Legado: reconciliation_matches · 1788 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CorrespondenciaReconciliacao.
 */
abstract class CorrespondenciaReconciliacaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'correspondencias_reconciliacao';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'reconciliacao_codigo', 'lancamento_contabil_id', 'linha_extrato_bancario_id', 'tipo_correspondencia', 'tipo_correspondencia_original', 'valor', 'data',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'lancamento_contabil_id' => 'integer',
            'linha_extrato_bancario_id' => 'integer',
            'valor' => 'decimal:2',
            'data' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function lancamentoContabil(): BelongsTo
    {
        return $this->belongsTo(LancamentoContabil::class, 'lancamento_contabil_id');
    }

    public function linhaExtratoBancario(): BelongsTo
    {
        return $this->belongsTo(LinhaExtratoBancario::class, 'linha_extrato_bancario_id');
    }
}
