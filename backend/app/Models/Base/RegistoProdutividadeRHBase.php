<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemProdutividadeRH;
use App\Models\ModeloBase;
use App\Models\PeriodoProdutividadeRH;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela registos_produtividade_rh (módulo RH). Legado: rh_productivity · 6 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RegistoProdutividadeRH.
 */
abstract class RegistoProdutividadeRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'registos_produtividade_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'periodo_produtividade_id', 'mes', 'colaborador_id', 'item_produtividade_id', 'data', 'quantidade', 'preco_unitario', 'valor', 'quantidade_considerada', 'observacoes', 'origem', 'criado_por', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'periodo_produtividade_id' => 'integer',
            'colaborador_id' => 'integer',
            'item_produtividade_id' => 'integer',
            'quantidade' => 'decimal:3',
            'preco_unitario' => 'decimal:2',
            'valor' => 'decimal:2',
            'quantidade_considerada' => 'decimal:3',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function periodoProdutividade(): BelongsTo
    {
        return $this->belongsTo(PeriodoProdutividadeRH::class, 'periodo_produtividade_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function itemProdutividade(): BelongsTo
    {
        return $this->belongsTo(ItemProdutividadeRH::class, 'item_produtividade_id');
    }
}
