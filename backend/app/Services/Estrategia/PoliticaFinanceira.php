<?php

namespace App\Services\Estrategia;

use Illuminate\Support\Facades\Gate;

/**
 * Valores financeiros do plano estratégico: quem vê o plano vê o semáforo de todos os indicadores, mas os valores em
 * kwanzas (valor, meta, linha de base, cumprimento) só aparecem com a tarefa sensível est_ver_financeiros. Sem ela os
 * valores vêm a nulo com `oculto: true`; o semáforo mantém-se (não revela o valor). Quando entrarem as origens financeiras
 * (contas, orçamento, indicadores de finanças e tesouraria) a mesma regra aplica-se também por origem.
 */
final class PoliticaFinanceira
{
    public static function oculta(string $formato, ?string $origem = null): bool
    {
        $financeiro = $formato === 'kz' || in_array($origem, ['CONTA', 'ORCAMENTO'], true);

        return $financeiro && ! Gate::allows('est_ver_financeiros');
    }
}
