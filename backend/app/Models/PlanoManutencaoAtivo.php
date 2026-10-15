<?php

namespace App\Models;

use App\Models\Base\PlanoManutencaoAtivoBase;

/**
 * planos_manutencao_ativos — /api/ativos/manutencao/planos (Fase F, Versão Actual; ServicoPlanosManutencao).
 * Legado novo: asset_maintenance_plans (Dexie v1277; js/activos_manutencao.js:116-217): plano PM-AAAA/NNNN para um activo
 * ou para toda uma categoria, periodicidade por TEMPO (dias/semanas/meses/anos) ou por CONTADOR (horas/km).
 * `periodicidade` = {modo, intervalo, unidade}; `tarefas` = lista de textos; `itens` = [{tipo, produto_id, descricao, quantidade}].
 */
class PlanoManutencaoAtivo extends PlanoManutencaoAtivoBase
{
    /** Tipos de manutenção (TIPOS, activos_manutencao.js:54-60). */
    public const TIPOS = ['PREVENTIVA', 'CORRECTIVA', 'LEGAL', 'CALIBRACAO', 'MELHORIA'];

    public const UNIDADES_TEMPO = ['DIAS', 'SEMANAS', 'MESES', 'ANOS'];

    public const UNIDADES_CONTADOR = ['HORAS', 'KM'];
}
