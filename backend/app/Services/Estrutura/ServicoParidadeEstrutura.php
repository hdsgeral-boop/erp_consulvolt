<?php

namespace App\Services\Estrutura;

use App\Models\Colaborador;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\ResultadoFolhaSalarial;
use App\Models\UnidadeOrganica;
use Illuminate\Support\Facades\DB;

/**
 * Paridade com o sistema anterior (js/modules/estrutura/estrutura_ui.js, verificação visual de 2026-10-08):
 *   - «Salário mensal» dos colaboradores afectos e «Massa salarial» por cargo (unidade + posto) — o legado somava o
 *     ilíquido do contrato activo; aqui usa-se o mesmo critério do mapa de pessoal do sistema novo (ADR-064): ilíquido
 *     (bruto) do último processamento FECHADO ou VALIDADO, só dos colaboradores activos da empresa activa;
 *   - «Empilhar últimos níveis» / «Tudo lado a lado» do organigrama (org_units.disposicao = 'COLUNA' ou nulo).
 * Todas as consultas passam pelo âmbito da empresa (PertenceEmpresa). Nada disto vai para a cache (salários
 * individuais — CACHE.md §4.6).
 */
final class ServicoParidadeEstrutura
{
    public const DISPOSICOES = ['COLUNA'];

    /**
     * @return array{periodo_salarial: ?string, por_colaborador: list<array{colaborador_id: int, valor: string}>}
     */
    public function salarios(): array
    {
        $ultimo = PeriodoProcessamentoSalarial::query()->whereIn('estado', ['FECHADO', 'VALIDADO'])
            ->orderByRaw('substring(mes_ano from 4 for 4) DESC, substring(mes_ano from 1 for 2) DESC')->first(['id', 'mes_ano']);
        if (! $ultimo) {
            return ['periodo_salarial' => null, 'por_colaborador' => []];
        }
        $activos = Colaborador::query()->where('estado', 'ACTIVO')->pluck('id')->all();
        $soma = [];
        ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $ultimo->id)->whereIn('colaborador_id', $activos)
            ->get(['colaborador_id', 'bruto'])
            ->each(function ($r) use (&$soma) {
                $id = (int) $r->colaborador_id;
                $soma[$id] = bcadd($soma[$id] ?? '0.00', (string) ($r->bruto ?? '0'), 2);
            });
        ksort($soma);

        return [
            'periodo_salarial' => $ultimo->mes_ano,
            'por_colaborador' => array_map(fn ($id, $v) => ['colaborador_id' => $id, 'valor' => $v], array_keys($soma), array_values($soma)),
        ];
    }

    /**
     * Disposição das subunidades no organigrama vertical. Os ids já foram validados contra a empresa activa; o âmbito
     * do model volta a garantir que só se alteram unidades desta empresa.
     *
     * @param  list<int>  $ids
     */
    public function disposicao(array $ids, ?string $valor): int
    {
        $valor = in_array($valor, self::DISPOSICOES, true) ? $valor : null;

        return DB::transaction(function () use ($ids, $valor) {
            $n = 0;
            foreach (UnidadeOrganica::query()->whereKey($ids)->lockForUpdate()->get() as $u) {
                if ($u->disposicao !== $valor) {
                    $u->update(['disposicao' => $valor]);
                }
                $n++;
            }

            return $n;
        });
    }
}
