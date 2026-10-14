<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\OrdemManutencaoAtivo;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela itens_ordem_manutencao (módulo Activos). Legado: asset_maintenance_order_items · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ItemOrdemManutencao.
 */
abstract class ItemOrdemManutencaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'itens_ordem_manutencao';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'ordem_manutencao_id', 'tipo', 'produto_id', 'descricao', 'quantidade', 'quantidade_recebida', 'estado', 'destino', 'origem_tipo', 'origem_id', 'custo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ordem_manutencao_id' => 'integer',
            'produto_id' => 'integer',
            'quantidade' => 'decimal:3',
            'quantidade_recebida' => 'decimal:3',
            'custo' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function ordemManutencao(): BelongsTo
    {
        return $this->belongsTo(OrdemManutencaoAtivo::class, 'ordem_manutencao_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }
}
