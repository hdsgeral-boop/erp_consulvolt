<?php

namespace App\Models\Base;

use App\Models\Armazem;
use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemOrdemManutencao;
use App\Models\LinhaRequisicaoManutencao;
use App\Models\ModeloBase;
use App\Models\OrdemManutencaoAtivo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela requisicoes_manutencao (módulo Activos). Legado: maint_requisitions · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RequisicaoManutencao.
 */
abstract class RequisicaoManutencaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'requisicoes_manutencao';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'numero', 'ordem_manutencao_id', 'ativo_imobilizado_id', 'armazem_id', 'requerente', 'data', 'estado', 'criado_por', 'satisfeita_em', 'motivo_cancelamento', 'cancelado_em', 'cancelado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ordem_manutencao_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'armazem_id' => 'integer',
            'data' => 'date',
            'satisfeita_em' => 'datetime',
            'cancelado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function ordemManutencao(): BelongsTo
    {
        return $this->belongsTo(OrdemManutencaoAtivo::class, 'ordem_manutencao_id');
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id');
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_id');
    }

    public function itensOrdemManutencao(): HasMany
    {
        return $this->hasMany(ItemOrdemManutencao::class, 'requisicao_manutencao_id');
    }

    public function linhasRequisicaoManutencao(): HasMany
    {
        return $this->hasMany(LinhaRequisicaoManutencao::class, 'requisicao_manutencao_id');
    }
}
