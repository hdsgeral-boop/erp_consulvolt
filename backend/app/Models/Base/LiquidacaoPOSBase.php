<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DocumentoTesouraria;
use App\Models\ModeloBase;
use App\Models\MovimentoCaixa;
use App\Models\SessaoCaixa;
use App\Models\SessaoPOS;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela liquidacoes_pos (módulo POS). Legado: pos_settlements · 6 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LiquidacaoPOS.
 */
abstract class LiquidacaoPOSBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'liquidacoes_pos';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'estado', 'criado_por', 'sessao_pos_id', 'numero_z', 'chave_item', 'natureza_registo', 'meio_pagamento_codigo', 'data', 'montante_bruto', 'comissao', 'montante_liquido', 'alvo', 'conta_destino', 'conta_transitoria', 'sessao_caixa_id', 'movimento_caixa_id', 'conta_comissao', 'documento_tesouraria_id', 'referencia_lote', 'numero_documento', 'referencia', 'comissao_deduzida', 'documento_comissao_id', 'cancelado_em', 'cancelado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'sessao_pos_id' => 'integer',
            'data' => 'date',
            'montante_bruto' => 'decimal:2',
            'comissao' => 'decimal:2',
            'montante_liquido' => 'decimal:2',
            'sessao_caixa_id' => 'integer',
            'movimento_caixa_id' => 'integer',
            'documento_tesouraria_id' => 'integer',
            'comissao_deduzida' => 'boolean',
            'documento_comissao_id' => 'integer',
            'cancelado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function sessaoPos(): BelongsTo
    {
        return $this->belongsTo(SessaoPOS::class, 'sessao_pos_id');
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    public function movimentoCaixa(): BelongsTo
    {
        return $this->belongsTo(MovimentoCaixa::class, 'movimento_caixa_id');
    }

    public function documentoTesouraria(): BelongsTo
    {
        return $this->belongsTo(DocumentoTesouraria::class, 'documento_tesouraria_id');
    }

    public function documentoComissao(): BelongsTo
    {
        return $this->belongsTo(DocumentoTesouraria::class, 'documento_comissao_id');
    }
}
