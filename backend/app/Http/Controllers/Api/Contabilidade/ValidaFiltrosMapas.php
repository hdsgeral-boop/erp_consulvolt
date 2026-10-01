<?php

namespace App\Http\Controllers\Api\Contabilidade;

use Illuminate\Http\Request;

/** Regras de validação dos filtros comuns dos mapas contabilísticos (FiltroMapas). */
trait ValidaFiltrosMapas
{
    /** @return array<string, list<string>> */
    protected function regrasFiltros(): array
    {
        return [
            'filtro_contas' => ['nullable', 'string', 'max:500', 'regex:/^[0-9A-Za-z.*,\- ]+$/'],
            'unidade_negocio_id' => ['nullable', 'integer'], 'centro_custo_id' => ['nullable', 'integer'], 'diario_id' => ['nullable', 'integer'],
            'terceiro_id' => ['nullable', 'integer'], 'incluir_classe_9' => ['nullable', 'boolean'], 'incluir_apuramento' => ['nullable', 'boolean'],
        ];
    }

    /** @param  array<string, mixed>  $extra */
    protected function filtros(Request $request, array $extra): array
    {
        $f = $request->validate($extra + $this->regrasFiltros(), [], [
            'data_inicio' => 'data inicial', 'data_fim' => 'data final', 'filtro_contas' => 'filtro de contas',
        ]);
        foreach (['incluir_classe_9', 'incluir_apuramento', 'comparativo', 'por_terceiro', 'totalizadoras', 'sem_saldo_inicial', 'so_movimento', 'sem_saldo_zero'] as $b) {
            if (array_key_exists($b, $f)) {
                $f[$b] = filter_var($f[$b], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $f;
    }
}
