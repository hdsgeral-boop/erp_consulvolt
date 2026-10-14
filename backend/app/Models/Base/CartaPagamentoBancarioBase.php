<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DocumentoTesouraria;
use App\Models\ItemCartaPagamento;
use App\Models\ModeloBase;
use App\Models\PeriodoProcessamentoSalarial;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela cartas_pagamento_bancario (módulo RH). Legado: payment_letters · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CartaPagamentoBancario.
 */
abstract class CartaPagamentoBancarioBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'cartas_pagamento_bancario';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'tipo', 'numero', 'sequencia', 'ano', 'estado', 'data', 'documento_tesouraria_id', 'periodo_processamento_salarial_id', 'mes_ano', 'referencia_movimento', 'codigo_conta_bancaria', 'banco_nome', 'banco_balcao', 'banco_numero_conta', 'banco_iban', 'banco_swift', 'banco_titular', 'codigo_moeda', 'assunto', 'corpo', 'nome_assinatura', 'cargo_assinatura', 'local', 'montante_total', 'criado_por', 'anulada_em', 'anulada_por', 'motivo_anulacao', 'grupo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'sequencia' => 'integer',
            'ano' => 'integer',
            'data' => 'date',
            'documento_tesouraria_id' => 'integer',
            'periodo_processamento_salarial_id' => 'integer',
            'montante_total' => 'decimal:2',
            'anulada_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function documentoTesouraria(): BelongsTo
    {
        return $this->belongsTo(DocumentoTesouraria::class, 'documento_tesouraria_id');
    }

    public function periodoProcessamentoSalarial(): BelongsTo
    {
        return $this->belongsTo(PeriodoProcessamentoSalarial::class, 'periodo_processamento_salarial_id');
    }

    public function itensCartaPagamento(): HasMany
    {
        return $this->hasMany(ItemCartaPagamento::class, 'carta_pagamento_bancario_id');
    }
}
