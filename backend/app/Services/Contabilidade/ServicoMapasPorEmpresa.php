<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\ExecucaoConsolidacao;
use App\Models\GrupoConsolidacao;
use App\Services\Consolidacao\ServicoConsolidacaoGrupos;
use App\Support\Tenancy\ContextoEmpresa;

/**
 * Mapas legais da holding «Por empresa» (legado: renderPorEmpresa, js/consolidacao.js:155-250): Balancete, Balanço, DR e
 * Fluxo de caixa com uma coluna por empresa do grupo, as Eliminações, a Conversão cambial, o lançado directamente na
 * holding e o Consolidado — sem comparativo nem AV/AH, como o legado.
 *
 * Os valores vêm das linhas que a ÚLTIMA execução da consolidação gravou na holding (empresa_origem_id/tipo_consolidacao);
 * cada coluna é o MESMO cálculo do mapa normal (ServicoRelatoriosContabeis / ServicoDemonstracoesFinanceiras) restrito às
 * linhas dessa origem (FiltroMapas: origem_consolidacao), pelo que a soma das colunas é o consolidado (o Balanço/DR de cada
 * coluna é o dessa origem; no Balanço a reclassificação dos saldos credores das notas 8–10 faz-se só sobre o consolidado e aparece
 * numa coluna «Reclassificação» — o legado fazia-a por coluna e a soma das empresas deixava de dar o consolidado).
 * Acesso: a holding activa e todas as empresas do grupo (ServicoConsolidacaoGrupos::grupo(…, exigirMembros: true)).
 * No Fluxo de caixa, a caixa inicial/final (saldo da classe 4, sem filtros) só tem sentido no consolidado.
 */
final class ServicoMapasPorEmpresa
{
    public const MAPAS = ['balancete', 'balanco', 'dr', 'fluxo'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoConsolidacaoGrupos $grupos,
        private readonly ServicoRelatoriosContabeis $relatorios,
        private readonly ServicoDemonstracoesFinanceiras $df,
    ) {}

    /**
     * @param  array<string, mixed>  $f  parâmetros do mapa (datas, opções, filtros validados)
     * @return array{mapa: string, execucao: array<string, mixed>, colunas: list<array<string, mixed>>, valores: array<string, array<string, string>>, linhas?: list<array<string, mixed>>}
     */
    public function calcular(string $mapa, array $f): array
    {
        $holding = $this->contexto->obrigatorio();
        $empresa = $this->contexto->semIsolamento(fn () => Empresa::query()->find($holding));
        if (! $empresa?->e_consolidacao) {
            throw new ErroNegocio('A vista «Por empresa» só existe na empresa de consolidação (holding).', 'NAO_E_HOLDING', 422);
        }
        $grupoId = $this->contexto->semIsolamento(fn () => GrupoConsolidacao::query()->where('empresa_holding_id', $holding)->value('id'));
        if (! $grupoId) {
            throw new ErroNegocio('A holding não tem grupo de consolidação.', 'GRUPO_INEXISTENTE', 422);
        }
        $grupo = $this->grupos->grupo((int) $grupoId, true);   // 403 se o utilizador não acede a todas as empresas do grupo
        $execucao = $grupo->ultima_execucao_id ? $this->contexto->semIsolamento(fn () => ExecucaoConsolidacao::query()->find($grupo->ultima_execucao_id)) : null;
        if (! $execucao) {
            throw new ErroNegocio('Consolide a holding antes de ver os mapas por empresa.', 'SEM_CONSOLIDACAO', 422);
        }
        $membros = $this->grupos->membros($grupo);
        $nomes = $this->contexto->semIsolamento(fn () => Empresa::withTrashed()->whereIn('id', $membros)->pluck('nome', 'id'));
        $colunas = array_merge(
            array_map(fn ($e) => ['chave' => "E{$e}", 'nome' => (string) ($nomes[$e] ?? "Empresa {$e}"), 'empresa_id' => $e, 'tipo' => 'empresa'], $membros),
            [['chave' => 'ELIM', 'nome' => 'Eliminações', 'tipo' => 'ajuste'], ['chave' => 'CONV', 'nome' => 'Conversão cambial', 'tipo' => 'ajuste'],
                ['chave' => 'OUTROS', 'nome' => 'Lançado na holding', 'tipo' => 'ajuste'], ['chave' => 'TOTAL', 'nome' => 'Consolidado', 'tipo' => 'consolidado']],
        );

        $f = array_diff_key($f, ['origem_consolidacao' => 1, 'comparativo' => 1]);
        $valores = [];
        $linhas = [];
        foreach ($colunas as $c) {
            // Balanço: as colunas não reclassificam os saldos credores das notas 8–10 (seria por empresa e a soma deixava de dar
            // o consolidado); a reclassificação do consolidado aparece numa coluna própria (RECL)
            $fc = $c['chave'] === 'TOTAL' ? $f : $f + ['origem_consolidacao' => $c['chave'], 'sem_reclassificacao' => true];
            [$valores[$c['chave']], $l] = $this->valores($mapa, $fc, $c['chave'] === 'TOTAL');
            // balancete: as contas de todas as colunas (uma conta eliminada pode ficar a zero no consolidado)
            foreach ($l ?? [] as $x) {
                $linhas[$x['codigo_conta']] ??= $x;
            }
        }
        if ($mapa === 'balanco') {
            $recl = [];
            foreach ($valores['TOTAL'] as $k => $total) {
                $soma = '0.00';
                foreach ($valores as $col => $v) {
                    $soma = $col === 'TOTAL' ? $soma : bcadd($soma, $v[$k] ?? '0.00', 2);
                }
                $recl[$k] = bcsub($total, $soma, 2);
            }
            array_splice($colunas, count($colunas) - 1, 0, [['chave' => 'RECL', 'nome' => 'Reclassificação (saldos credores 8–10 → 21)', 'tipo' => 'ajuste']]);
            $valores = array_slice($valores, 0, -1, true) + ['RECL' => $recl, 'TOTAL' => $valores['TOTAL']];
        }
        usort($linhas, fn ($a, $b) => strcmp((string) $a['codigo_conta'], (string) $b['codigo_conta']) ?: ($b['totalizadora'] <=> $a['totalizadora']));
        // ajustes sem valores (ex.: sem conversão cambial) não aparecem — como o legado (juntarColunas)
        $colunas = array_values(array_filter($colunas, fn ($c) => $c['tipo'] !== 'ajuste' || array_filter($valores[$c['chave']], fn ($v) => bccomp($v, '0', 2) !== 0)));
        $valores = array_intersect_key($valores, array_flip(array_column($colunas, 'chave')));

        return ['mapa' => $mapa, 'execucao' => ['id' => $execucao->id, 'data_fim' => $execucao->data_fim?->toDateString(), 'moeda' => $execucao->codigo_moeda],
            'colunas' => $colunas, 'valores' => $valores] + ($mapa === 'balancete' ? ['linhas' => $linhas] : []);
    }

    /**
     * Valores de uma coluna: rubrica → valor (demonstrações) ou conta → saldo final D − C (balancete).
     *
     * @return array{0: array<string, string>, 1: ?list<array<string, mixed>>} valores e, no consolidado do balancete, as linhas (estrutura)
     */
    private function valores(string $mapa, array $f, bool $consolidado): array
    {
        switch ($mapa) {
            case 'balancete':
                $b = $this->relatorios->balancete($f);
                $v = [];
                foreach ($b['linhas'] as $l) {
                    $v[$l['codigo_conta']] = $l['saldo_final'];
                }

                return [$v, array_map(fn ($l) => ['codigo_conta' => $l['codigo_conta'], 'descricao' => $l['descricao'] ?? null, 'totalizadora' => (bool) ($l['totalizadora'] ?? false)], $b['linhas'])];
            case 'balanco':
                $fim = (string) $f['data_fim'];
                $c = $this->df->calcularBalanco((string) ($f['data_inicio'] ?? substr($fim, 0, 4).'-01-01'), $fim, $f);

                return [array_map(fn ($x) => (string) $x, $c['v'] + $c['totais']), null];
            case 'dr':
                $c = $this->df->calcularDR((string) $f['data_inicio'], (string) $f['data_fim'], $f);
                $t = $c;
                unset($t['v']);

                return [array_map(fn ($x) => (string) $x, $c['v'] + $t), null];
            case 'fluxo':
                $c = $this->df->calcularFluxo((string) $f['data_inicio'], (string) $f['data_fim'], $f);
                $v = $c['v'] + ['operacionais' => $c['operacionais'], 'investimento' => $c['investimento'], 'financiamento' => $c['financiamento'], 'variacao' => $c['variacao']];
                if ($consolidado) {
                    $v += ['caixa_inicial' => $c['caixa_inicial'], 'caixa_final' => bcadd($c['variacao'], $c['caixa_inicial'], 2)];
                }

                return [array_map(fn ($x) => (string) $x, $v), null];
        }
        throw new ErroNegocio('Mapa desconhecido.', 'MAPA_INVALIDO', 422);
    }
}
