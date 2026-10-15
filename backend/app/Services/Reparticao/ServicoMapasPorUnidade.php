<?php

namespace App\Services\Reparticao;

use App\Models\UnidadeNegocio;
use App\Services\Contabilidade\FiltroMapas;
use App\Services\Contabilidade\ServicoDemonstracoesFinanceiras;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Balanço e DR com uma coluna por unidade de negócio (legado novo js/ui_reports.js:376-406, `colunasPorUN`, Balanço
 * :1041-1082, DR :1275-1365). Cada linha do Diário já traz a sua unidade — incluindo as repartidas por um critério —, por
 * isso basta correr o mesmo cálculo por unidade: as unidades com movimento no período, mais a coluna «Sem unidade» quando
 * há lançamentos sem unidade, e o Total (o mapa normal). Sem comparativo (o legado também o desliga neste modo).
 */
final class ServicoMapasPorUnidade
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoDemonstracoesFinanceiras $df,
    ) {}

    /**
     * @param  array<string, mixed>  $f  filtros do Balanço (data_fim, data_inicio, filtro_contas, centro_custo_id, ...)
     * @return array{colunas: list<array{unidade_negocio_id: ?int, rotulo: string, mapa: array<string, mixed>}>, total: array<string, mixed>}
     */
    public function balanco(array $f): array
    {
        $f = $this->semFiltroUnidade($f);

        return $this->montar($f, null, fn (array $g) => $this->df->balanco($g));
    }

    /**
     * @param  array<string, mixed>  $f  filtros da DR (data_inicio, data_fim, ...)
     * @return array{colunas: list<array{unidade_negocio_id: ?int, rotulo: string, mapa: array<string, mixed>}>, total: array<string, mixed>}
     */
    public function demonstracaoResultados(array $f): array
    {
        $f = $this->semFiltroUnidade($f);

        return $this->montar($f, $f['data_inicio'], fn (array $g) => $this->df->demonstracaoResultados($g));
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $calcular */
    private function montar(array $f, ?string $desde, callable $calcular): array
    {
        [$ids, $semUnidade] = $this->unidadesComMovimento($f['data_fim'], $desde, $f);
        $unidades = UnidadeNegocio::query()->whereIn('id', $ids)->get(['id', 'codigo', 'nome'])->keyBy('id');
        $colunas = collect($ids)->map(fn ($id) => ['id' => $id, 'u' => $unidades->get($id)])
            ->sortBy(fn ($c) => $c['u']?->codigo ?? 'zzz'.$c['id'], SORT_NATURAL)
            ->map(fn ($c) => ['unidade_negocio_id' => (int) $c['id'],
                'rotulo' => $c['u'] ? trim(($c['u']->codigo ? "{$c['u']->codigo} — " : '').$c['u']->nome) : "Unidade #{$c['id']}",
                'mapa' => $calcular($f + ['unidade_negocio_id' => (int) $c['id']])])
            ->values()->all();
        // nada com unidade: uma coluna «Sem unidade» não diz nada (colunasPorUN devolve null)
        if ($colunas && $semUnidade) {
            $colunas[] = ['unidade_negocio_id' => null, 'rotulo' => 'Sem unidade', 'mapa' => $calcular($f + ['sem_unidade_negocio' => true])];
        }

        return ['colunas' => $colunas, 'total' => $calcular($f)];
    }

    /** @return array{0: list<int>, 1: bool} unidades com movimento e se há movimento sem unidade */
    private function unidadesComMovimento(string $fim, ?string $desde, array $f): array
    {
        [$filtro, $p] = FiltroMapas::sql('l', $f, (int) substr($fim, 0, 4));
        $linhas = DB::select('SELECT DISTINCT l.unidade_negocio_id AS id FROM lancamentos_contabeis l
            WHERE l.empresa_id = ? AND l.data_documento <= ?'.($desde ? ' AND l.data_documento >= ?' : '').$filtro,
            array_merge([$this->contexto->obrigatorio(), $fim], $desde ? [$desde] : [], $p));
        $ids = [];
        $sem = false;
        foreach ($linhas as $l) {
            $l->id === null ? $sem = true : $ids[] = (int) $l->id;
        }

        return [$ids, $sem];
    }

    /** @return array<string, mixed> */
    private function semFiltroUnidade(array $f): array
    {
        unset($f['unidade_negocio_id'], $f['sem_unidade_negocio']);

        return ['comparativo' => false] + $f;
    }
}
