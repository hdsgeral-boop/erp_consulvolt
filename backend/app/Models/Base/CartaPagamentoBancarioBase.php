<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemCartaPagamento;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela cartas_pagamento_bancario (módulo RH). Legado: payment_letters · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CartaPagamentoBancario.
 */
abstract class CartaPagamentoBancarioBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'cartas_pagamento_bancario';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'mes_ano', 'codigo_conta_bancaria', 'nome_assinatura', 'data', 'montante_total',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data' => 'date',
            'montante_total' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function itensCartaPagamento(): HasMany
    {
        return $this->hasMany(ItemCartaPagamento::class, 'carta_pagamento_bancario_id');
    }
}
