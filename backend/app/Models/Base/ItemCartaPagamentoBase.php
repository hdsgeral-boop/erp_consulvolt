<?php

namespace App\Models\Base;

use App\Models\CartaPagamentoBancario;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela itens_carta_pagamento (módulo RH). Legado: payment_letter_items · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ItemCartaPagamento.
 */
abstract class ItemCartaPagamentoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'itens_carta_pagamento';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'carta_pagamento_bancario_id', 'colaborador_id', 'montante', 'iban',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'carta_pagamento_bancario_id' => 'integer',
            'colaborador_id' => 'integer',
            'montante' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function cartaPagamentoBancario(): BelongsTo
    {
        return $this->belongsTo(CartaPagamentoBancario::class, 'carta_pagamento_bancario_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }
}
