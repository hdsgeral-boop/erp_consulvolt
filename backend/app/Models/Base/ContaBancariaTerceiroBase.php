<?php

namespace App\Models\Base;

use App\Models\Banco;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Terceiro;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela contas_bancarias_terceiros (módulo Terceiros). Legado: third_party_bank_details · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ContaBancariaTerceiro.
 */
abstract class ContaBancariaTerceiroBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'contas_bancarias_terceiros';

    protected string $moduloAuditoria = 'Terceiros';

    protected $fillable = [
        'empresa_id', 'terceiro_id', 'banco_id', 'banco', 'numero_conta', 'iban', 'titular', 'predefinida', 'codigo_moeda',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'terceiro_id' => 'integer',
            'banco_id' => 'integer',
            'predefinida' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function bancoRelacao(): BelongsTo
    {
        return $this->belongsTo(Banco::class, 'banco_id');
    }
}
