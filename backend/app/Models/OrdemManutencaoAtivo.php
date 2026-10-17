<?php

namespace App\Models;

use App\Models\Base\OrdemManutencaoAtivoBase;

/**
 * ordens_manutencao_ativos — /api/ativos/manutencao/ordens (Fase F, Versão Actual; ServicoOrdensManutencao).
 * Legado novo: asset_maintenance_orders (js/activos_manutencao.js:219-391). Estados PLANEADA → AGUARDA_MATERIAL → EM_CURSO
 * → CONCLUIDA | CANCELADA; custo total = materiais + serviços + mão-de-obra.
 * r3_activos: `tarefas` = [{id, texto, obrigatoria, feito, feito_por, feito_em}]; `historico` regista quem/quando concluiu
 * ou reabriu cada tarefa e a ordem; `plano_antes` guarda o plano antes da conclusão (repõe-se se a ordem for reaberta).
 */
class OrdemManutencaoAtivo extends OrdemManutencaoAtivoBase
{
    public const PLANEADA = 'PLANEADA';

    public const AGUARDA_MATERIAL = 'AGUARDA_MATERIAL';

    public const EM_CURSO = 'EM_CURSO';

    public const CONCLUIDA = 'CONCLUIDA';

    public const CANCELADA = 'CANCELADA';

    public const ABERTAS = [self::PLANEADA, self::AGUARDA_MATERIAL, self::EM_CURSO];

    public const ESTADOS = [self::PLANEADA, self::AGUARDA_MATERIAL, self::EM_CURSO, self::CONCLUIDA, self::CANCELADA];

    public const PRIORIDADES = ['BAIXA', 'NORMAL', 'ALTA', 'URGENTE'];

    /**
     * Colunas acrescentadas pela migração r3_activos. Não entram no $fillable (gerado e conferido com o contrato do esquema,
     * EsquemaContratoTest): fill() aceita-as por esta lista explícita, com forceFill.
     */
    public const CAMPOS_EXTRA = ['historico', 'plano_antes', 'reaberta_em', 'reaberta_por'];

    public function fill(array $attributes)
    {
        if (static::isUnguarded()) {
            return parent::fill($attributes);
        }
        $extra = array_intersect_key($attributes, array_flip(self::CAMPOS_EXTRA));
        parent::fill(array_diff_key($attributes, $extra));

        return $extra ? $this->forceFill($extra) : $this;
    }

    protected function casts(): array
    {
        return parent::casts() + ['historico' => 'array', 'plano_antes' => 'array', 'reaberta_em' => 'datetime'];
    }
}
