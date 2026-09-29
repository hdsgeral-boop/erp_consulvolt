<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\CorrespondenciaReconciliacao;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela linhas_extrato_bancario (módulo Tesouraria). Legado: bank_statement_lines · 2821 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaExtratoBancario.
 */
abstract class LinhaExtratoBancarioBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_extrato_bancario';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'codigo_conta', 'data', 'referencia', 'descricao', 'valor', 'tipo_dc', 'estado', 'estado_original', 'reconciliacao_codigo', 'lote_codigo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data' => 'date',
            'valor' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function correspondenciasReconciliacao(): HasMany
    {
        return $this->hasMany(CorrespondenciaReconciliacao::class, 'linha_extrato_bancario_id');
    }
}
