<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela modelos_cartas_pagamento (módulo Tesouraria). Legado: payment_letter_templates · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ModeloCartaPagamento.
 */
abstract class ModeloCartaPagamentoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'modelos_cartas_pagamento';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'tipo', 'assunto', 'corpo', 'nome_assinatura', 'cargo_assinatura', 'local', 'nome', 'predefinido',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'predefinido' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
