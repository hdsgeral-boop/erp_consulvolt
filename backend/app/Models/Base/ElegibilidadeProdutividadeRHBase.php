<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemProdutividadeRH;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela elegibilidade_produtividade_rh (módulo RH, Fase J). Legado: rh_prod_eligibility (origens MANUAL e ENTREGAVEL; CONTRATO
 * = itens migrados do antigo JSON `contratos_trabalho.produtividade`, que deixou de existir).
 * Escrita à mão no formato das classes geradas: o código de negócio vai em App\Models\ElegibilidadeProdutividadeRH.
 */
abstract class ElegibilidadeProdutividadeRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'elegibilidade_produtividade_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'colaborador_id', 'item_produtividade_id', 'ativo', 'origem', 'preco_unitario', 'nota', 'criado_por', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'item_produtividade_id' => 'integer',
            'ativo' => 'boolean',
            'preco_unitario' => 'decimal:4',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
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
