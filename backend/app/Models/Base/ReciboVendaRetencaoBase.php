<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\ReciboVenda;
use App\Models\TipoRetencaoFonte;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela recibos_venda_retencoes (módulo Vendas): a retenção na fonte de cada recibo de cliente (1:1).
 */
abstract class ReciboVendaRetencaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'recibos_venda_retencoes';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = [
        'empresa_id', 'recibo_venda_id', 'tipo_retencao_fonte_id', 'codigo_tipo', 'descricao_tipo', 'percentagem', 'base_incidencia',
        'valor_retido', 'valor_calculado', 'referencia_comprovativo', 'data_comprovativo', 'conta_imposto',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'recibo_venda_id' => 'integer',
            'tipo_retencao_fonte_id' => 'integer',
            'percentagem' => 'decimal:4',
            'base_incidencia' => 'decimal:2',
            'valor_retido' => 'decimal:2',
            'valor_calculado' => 'decimal:2',
            'data_comprovativo' => 'date',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function recibo(): BelongsTo
    {
        return $this->belongsTo(ReciboVenda::class, 'recibo_venda_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoRetencaoFonte::class, 'tipo_retencao_fonte_id');
    }
}
