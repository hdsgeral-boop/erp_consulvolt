<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\ReciboVenda;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela itens_recibo_venda (módulo Vendas). Legado: receipt_items · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ItemReciboVenda.
 */
abstract class ItemReciboVendaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'itens_recibo_venda';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = [
        'empresa_id', 'recibo_venda_id', 'venda_id', 'montante_pago', 'numero_lan_contabilizacao', 'data_alocacao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'recibo_venda_id' => 'integer',
            'venda_id' => 'integer',
            'montante_pago' => 'decimal:2',
            'data_alocacao' => 'date',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function reciboVenda(): BelongsTo
    {
        return $this->belongsTo(ReciboVenda::class, 'recibo_venda_id');
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_id');
    }
}
