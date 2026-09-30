<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela conferencias_caixa (módulo Tesouraria). Legado: cash_audits · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConferenciaCaixa.
 */
abstract class ConferenciaCaixaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'conferencias_caixa';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'codigo_conta', 'nome_conta', 'data_conferencia', 'nome_operador', 'denominacoes', 'total_fisico', 'total_sistema', 'saldo_externo', 'diferenca', 'justificacao', 'conta_regularizacao', 'nome_conta_regularizacao', 'referencia_lancamento', 'estado', 'estado_original', 'nome_gerente', 'assinado_gerente_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data_conferencia' => 'datetime',
            'total_fisico' => 'decimal:2',
            'total_sistema' => 'decimal:2',
            'saldo_externo' => 'decimal:2',
            'diferenca' => 'decimal:2',
            'assinado_gerente_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
