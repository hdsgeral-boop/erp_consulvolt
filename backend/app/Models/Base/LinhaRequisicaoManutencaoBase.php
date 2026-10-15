<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemOrdemManutencao;
use App\Models\ModeloBase;
use App\Models\Produto;
use App\Models\RequisicaoManutencao;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela linhas_requisicao_manutencao (módulo Activos). Legado: maint_requisition_lines · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaRequisicaoManutencao.
 */
abstract class LinhaRequisicaoManutencaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_requisicao_manutencao';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'requisicao_manutencao_id', 'item_ordem_manutencao_id', 'produto_id', 'descricao', 'quantidade', 'quantidade_entregue', 'estado', 'custo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'requisicao_manutencao_id' => 'integer',
            'item_ordem_manutencao_id' => 'integer',
            'produto_id' => 'integer',
            'quantidade' => 'decimal:3',
            'quantidade_entregue' => 'decimal:3',
            'custo' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function requisicaoManutencao(): BelongsTo
    {
        return $this->belongsTo(RequisicaoManutencao::class, 'requisicao_manutencao_id');
    }

    public function itemOrdemManutencao(): BelongsTo
    {
        return $this->belongsTo(ItemOrdemManutencao::class, 'item_ordem_manutencao_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }
}
