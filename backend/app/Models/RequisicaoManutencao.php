<?php

namespace App\Models;

use App\Models\Base\RequisicaoManutencaoBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * requisicoes_manutencao — /api/ativos/manutencao/requisicoes (Fase F, Versão Actual; ServicoRequisicoesManutencao).
 * Legado novo: maint_requisitions (js/activos_manutencao.js:457-473, 528-624): material de armazém pedido por uma ordem de
 * manutenção (RQ AAAA/NNNN). As colunas vêm da migração 2026_10_14_300100_ff_activos_manutencao.
 */
class RequisicaoManutencao extends RequisicaoManutencaoBase
{
    public const PENDENTE = 'PENDENTE';

    public const PARCIAL = 'PARCIAL';

    public const SATISFEITA = 'SATISFEITA';

    public const CANCELADA = 'CANCELADA';

    protected function casts(): array
    {
        return parent::casts() + [
            'ordem_manutencao_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'armazem_id' => 'integer',
            'data' => 'date',
            'satisfeita_em' => 'datetime',
            'cancelado_em' => 'datetime',
        ];
    }

    public function ordemManutencao(): BelongsTo
    {
        return $this->belongsTo(OrdemManutencaoAtivo::class, 'ordem_manutencao_id');
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id')->withTrashed();
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_id');
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(LinhaRequisicaoManutencao::class, 'requisicao_manutencao_id');
    }
}
