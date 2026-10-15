<?php

namespace App\Models;

use App\Models\Base\ContratoTrabalhoBase;

/**
 * contratos_trabalho — /api/rh/contratos.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ContratoTrabalhoBase (gerado).
 *
 * Fase B (Versão Actual): estados ACTIVO | SUSPENSO | CESSADO (INACTIVO continua aceite nos contratos antigos), com a
 * suspensão e a cessação registadas (ServicoEstadoContrato). As colunas novas (suspensao, suspensoes, cessacao,
 * cessacoes_anuladas) fazem parte do esquema gerado (glossario.mjs / campos_codigo_legado.json); a migração
 * 2026_10_14_100100_fb_rh_estado_contrato_escalas_emprestimos só as cria numa base anterior à regeneração.
 */
class ContratoTrabalho extends ContratoTrabalhoBase
{
    public const ESTADOS = ['ACTIVO', 'INACTIVO', 'SUSPENSO', 'CESSADO'];

    /** Colunas da Fase B (JSON da suspensão e da cessação). */
    public const COLUNAS_FASE_B = ['suspensao', 'suspensoes', 'cessacao', 'cessacoes_anuladas'];

    protected function casts(): array
    {
        return parent::casts() + array_fill_keys(self::COLUNAS_FASE_B, 'array');
    }
}
