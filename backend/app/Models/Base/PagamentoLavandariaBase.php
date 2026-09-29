<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PedidoLavandaria;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela pagamentos_lavandaria (módulo POS). Legado: lav_payments · 5 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PagamentoLavandaria.
 */
abstract class PagamentoLavandariaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'pagamentos_lavandaria';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'pedido_lavandaria_id', 'numero_encomenda', 'sessao_pos_id', 'terminal_pos_id', 'cliente_id', 'data', 'montante', 'troco', 'pos_pagamentos', 'numero_recibo', 'natureza_registo', 'estado', 'criado_por', 'venda_id', 'lans_contabilizacao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'pedido_lavandaria_id' => 'integer',
            'sessao_pos_id' => 'integer',
            'terminal_pos_id' => 'integer',
            'cliente_id' => 'integer',
            'data' => 'date',
            'montante' => 'decimal:2',
            'troco' => 'decimal:2',
            'pos_pagamentos' => 'array',
            'venda_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function pedidoLavandaria(): BelongsTo
    {
        return $this->belongsTo(PedidoLavandaria::class, 'pedido_lavandaria_id');
    }

    public function sessaoPos(): BelongsTo
    {
        return $this->belongsTo(SessaoPOS::class, 'sessao_pos_id');
    }

    public function terminalPos(): BelongsTo
    {
        return $this->belongsTo(TerminalPOS::class, 'terminal_pos_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_id');
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_id');
    }
}
