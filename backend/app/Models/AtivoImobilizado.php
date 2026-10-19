<?php

namespace App\Models;

use App\Models\Base\AtivoImobilizadoBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ativos_imobilizados — /api/ativos/bens (ServicoAtivos).
 * `amortizacao_acumulada` é derivada: inicial + Σ quotas contabilizadas (ServicoAtivos::recalcularAcumulado).
 * `lancamento_contabil_id` liga à linha do lançamento de aquisição (classe 11/12 a débito).
 * `propriedade` (r3_activos): PROPRIO | ALHEIO (arrendado, cedido, de terceiros). Só os próprios amortizam, entram nos mapas
 * fiscais e se ligam a lançamentos de aquisição (scope proprios()); os alheios podem ter dados do proprietário/contrato.
 */
class AtivoImobilizado extends AtivoImobilizadoBase
{
    public const ESTADO_ATIVO = 'ACTIVO';

    public const ESTADO_INATIVO = 'INACTIVO';

    public const ESTADO_ABATIDO = 'ABATIDO';

    public const ESTADOS = [self::ESTADO_ATIVO, self::ESTADO_INATIVO, self::ESTADO_ABATIDO];

    public const PROPRIO = 'PROPRIO';

    public const ALHEIO = 'ALHEIO';

    public const PROPRIEDADES = [self::PROPRIO, self::ALHEIO];

    public const REGIMES_ALHEIO = ['ARRENDADO', 'CEDIDO', 'TERCEIROS', 'OUTRO'];

    /**
     * Campos da propriedade (migração r3_activos; no esquema gerado e no $fillable desde a ronda 4 — esquema_extra.mjs).
     * Lista de domínio usada pelo ServicoAtivos (dados do proprietário/contrato dos bens alheios).
     */
    public const CAMPOS_PROPRIEDADE = ['propriedade', 'regime_alheio', 'proprietario_terceiro_id', 'proprietario_nome', 'contrato_referencia', 'contrato_inicio',
        'contrato_fim', 'renda_mensal', 'propriedade_notas'];

    public function proprietario(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'proprietario_terceiro_id');
    }

    /** Bem próprio (NULL conta como próprio: linhas anteriores à coluna). */
    public function eProprio(): bool
    {
        return ($this->propriedade ?? self::PROPRIO) !== self::ALHEIO;
    }

    /** Só os bens próprios (amortizações, mapas fiscais, contabilização da aquisição). */
    public function scopeProprios(Builder $q): Builder
    {
        return $q->where(fn ($x) => $x->whereNull('propriedade')->orWhere('propriedade', '<>', self::ALHEIO));
    }
}
