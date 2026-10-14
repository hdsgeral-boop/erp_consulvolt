<?php

namespace App\Models;

use App\Models\Base\ContaBancariaTerceiroBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * contas_bancarias_terceiros — /api/terceiros/{terceiro}/contas-bancarias (Fase A, Versão Actual).
 * Legado novo: third_party_bank_details (db_v2.js v1271; ui_aux.js:920-1005). Um terceiro pode ter várias contas
 * (banco, moeda, IBAN), uma delas predefinida — é a que a carta de ordem de pagamento usa por omissão.
 * As colunas vêm da migração 2026_10_13_210000_fa_terceiros_contas_bancarias (a estrutura gerada só tinha empresa_id).
 */
class ContaBancariaTerceiro extends ContaBancariaTerceiroBase
{
    protected $fillable = [
        'empresa_id', 'terceiro_id', 'banco_id', 'banco', 'numero_conta', 'iban', 'titular', 'codigo_moeda', 'predefinida',
    ];

    protected function casts(): array
    {
        return parent::casts() + [
            'terceiro_id' => 'integer',
            'banco_id' => 'integer',
            'predefinida' => 'boolean',
        ];
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }
}
