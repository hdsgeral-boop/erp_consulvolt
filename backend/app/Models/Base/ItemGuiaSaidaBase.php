<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\GuiaSaida;
use App\Models\ItemCompra;
use App\Models\ModeloBase;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\RececaoCompra;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela itens_guia_saida (módulo Logística). Legado: delivery_items · 47 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ItemGuiaSaida.
 */
abstract class ItemGuiaSaidaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'itens_guia_saida';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'produto_id', 'quantidade', 'projeto_id', 'codigo_projeto', 'cambial_q1', 'cambial_v1', 'cambial_q2', 'cambial_v2', 'valor_kz', 'custo_unitario_kz', 'item_compra_id', 'guia_saida_id', 'rececao_compra_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'produto_id' => 'integer',
            'quantidade' => 'decimal:3',
            'projeto_id' => 'integer',
            'cambial_q1' => 'decimal:3',
            'cambial_v1' => 'decimal:2',
            'cambial_q2' => 'decimal:3',
            'cambial_v2' => 'decimal:2',
            'valor_kz' => 'decimal:2',
            'custo_unitario_kz' => 'decimal:2',
            'item_compra_id' => 'integer',
            'guia_saida_id' => 'integer',
            'rececao_compra_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function itemCompra(): BelongsTo
    {
        return $this->belongsTo(ItemCompra::class, 'item_compra_id');
    }

    public function guiaSaida(): BelongsTo
    {
        return $this->belongsTo(GuiaSaida::class, 'guia_saida_id');
    }

    public function rececaoCompra(): BelongsTo
    {
        return $this->belongsTo(RececaoCompra::class, 'rececao_compra_id');
    }
}
