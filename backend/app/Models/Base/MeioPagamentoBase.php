<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela meios_pagamento (módulo Tesouraria). Legado: payment_methods · 3 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\MeioPagamento.
 */
abstract class MeioPagamentoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'meios_pagamento';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'nome', 'codigo_conta', 'iban', 'swift', 'ativo', 'predefinido', 'codigo_moeda',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo' => 'boolean',
            'predefinido' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }
}
