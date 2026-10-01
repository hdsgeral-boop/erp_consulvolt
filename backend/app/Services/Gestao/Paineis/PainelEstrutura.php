<?php

namespace App\Services\Gestao\Paineis;

use App\Services\RH\ServicoEstruturaOrg;
use Illuminate\Support\Facades\DB;

/**
 * Estrutura orgânica (CONSTRUTORES.estrutura, ui_painel_modulos.js:655-684). Só por empresa (não na holding).
 *
 * Reutiliza a árvore do módulo (ServicoEstruturaOrg::arvore — unidades, postos com vagas/ocupados/livres e colaboradores sem
 * unidade, ADR-040): unidades (e sem responsável), vagas previstas, em aberto e acima do previsto, colaboradores sem unidade;
 * pessoas e vagas em aberto por unidade de 1.º nível (com as subunidades); cargos com vagas em aberto ou acima do previsto.
 *
 * Massa salarial (só com est_ver_salarios, como no legado): passa a ser o ilíquido do último processamento fechado até ao mês
 * (fotografia resultados_folha_salarial, por unidade orgânica actual do colaborador) — o legado somava as remunerações dos
 * contratos activos, que não incluem faltas, subsídios nem rubricas variáveis do mês.
 */
final class PainelEstrutura implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas, private readonly ServicoEstruturaOrg $estrutura) {}

    public function filtraDimensoes(): bool
    {
        return false;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $a = $this->estrutura->arvore();
        $unidades = collect($a['unidades'])->keyBy('id');
        if ($unidades->isEmpty()) {
            return ['aviso' => 'Ainda não há estrutura orgânica. Crie-a em Estrutura Orgânica › Unidades e Cargos.', 'kpis' => [], 'graficos' => [], 'tabelas' => [],
                'atalhos' => [['rotulo' => 'Unidades e Cargos', 'vista' => 'est_estrutura']]];
        }
        $postos = $unidades->flatMap(fn ($u) => $u['postos'])->values();
        $filhos = $unidades->groupBy(fn ($u) => (int) ($u['unidade_organica_pai_id'] ?? 0));
        $raizes = $unidades->filter(fn ($u) => empty($u['unidade_organica_pai_id']) || ! $unidades->has($u['unidade_organica_pai_id']))->values();
        $subarvore = function (int $id, int $prof = 0) use (&$subarvore, $filhos): array {
            return $prof > 50 ? [$id] : array_merge([$id], ...array_map(fn ($u) => $subarvore((int) $u['id'], $prof + 1), ($filhos[$id] ?? collect())->all()));
        };
        $caminho = function (int $id) use ($unidades): string {
            $nomes = [];
            for ($i = 0, $u = $unidades[$id] ?? null; $u && $i < 50; $i++, $u = $unidades[$u['unidade_organica_pai_id'] ?? 0] ?? null) {
                array_unshift($nomes, $u['nome']);
            }

            return implode(' › ', $nomes);
        };
        $acima = fn ($x) => max(0, (int) $x['ocupados'] - (int) $x['vagas']);
        $nivel1 = $raizes->count() === 1 ? ($filhos[(int) $raizes[0]['id']] ?? collect())->values() : $raizes;
        $verSalarios = ! empty($f['ver_salarios']);
        $massa = $verSalarios ? $this->massaPorUnidade($p) : ['total' => '0.00', 'por_unidade' => []];
        $tot1 = $nivel1->map(function ($u) use ($subarvore, $unidades, $massa) {
            $ids = $subarvore((int) $u['id']);
            $sub = $unidades->only($ids);

            return ['rotulo' => $u['codigo'] ?: $u['nome'], 'pessoas' => $sub->sum('membros'), 'em_aberto' => $sub->flatMap(fn ($x) => $x['postos'])->sum('livres'),
                'massa' => array_reduce($ids, fn ($s, $id) => bcadd($s, $massa['por_unidade'][$id] ?? '0', 2), '0.00')];
        });
        $alerta = $postos->filter(fn ($x) => (int) $x['livres'] > 0 || $acima($x) > 0)->take(15)->map(fn ($x) => ['posto_trabalho_id' => $x['id'], 'unidade' => $caminho((int) $x['unidade_organica_id']),
            'cargo' => $x['titulo'], 'vagas' => (int) $x['vagas'], 'ocupados' => (int) $x['ocupados'], 'em_aberto' => (int) $x['livres'], 'acima' => $acima($x)])->values()->all();
        $semResp = $unidades->filter(fn ($u) => ! in_array($u['ativo'] ?? true, [false, 0, '0'], true) && empty($u['colaborador_responsavel_id']))->count();
        $activos = $unidades->sum('membros') + (int) $a['sem_unidade'];

        $kpis = [
            Indicadores::kpi('unidades', 'Unidades Orgânicas', $unidades->count(), 'num', "{$semResp} sem responsável"),
            Indicadores::kpi('vagas_previstas', 'Vagas Previstas', (int) $postos->sum('vagas'), 'num'),
            Indicadores::kpi('vagas_em_aberto', 'Vagas em Aberto', (int) $postos->sum('livres'), 'num'),
            Indicadores::kpi('acima_vagas', 'Acima das Vagas', (int) $postos->sum($acima), 'num'),
            Indicadores::kpi('sem_unidade', 'Colaboradores sem Unidade', (int) $a['sem_unidade'], 'num', "{$activos} activos"),
        ];
        $graficos = [Indicadores::grafico('pessoas_unidade', 'Pessoas por Unidade (1.º nível)', 'barras', $tot1->pluck('rotulo')->all(), [
            Indicadores::serie('pessoas', 'Pessoas', $tot1->pluck('pessoas')->all()), Indicadores::serie('em_aberto', 'Vagas em aberto', $tot1->pluck('em_aberto')->all())], false)];
        if ($verSalarios) {
            $kpis[] = Indicadores::kpi('massa_salarial', 'Massa Salarial Mensal', $massa['total'], 'kz', $massa['periodo'] ? "Ilíquido de {$massa['periodo']}" : 'Sem processamento fechado');
            $graficos[] = Indicadores::grafico('massa_unidade', 'Massa Salarial por Unidade (1.º nível)', 'circular', $tot1->pluck('rotulo')->all(),
                [Indicadores::serie('massa', 'Massa salarial', $tot1->pluck('massa')->all())]);
        }

        return [
            'kpis' => $kpis,
            'graficos' => $graficos,
            'tabelas' => [Indicadores::tabela('cargos_alerta', 'Cargos com Vagas em Aberto ou Acima do Previsto', [['unidade', 'Unidade'], ['cargo', 'Cargo'], ['vagas', 'Vagas', 'num'],
                ['ocupados', 'Ocupados', 'num'], ['em_aberto', 'Em aberto', 'num'], ['acima', 'Acima', 'num']], $alerta)],
            'atalhos' => [['rotulo' => 'Unidades e Cargos', 'vista' => 'est_estrutura'], ['rotulo' => 'Organigrama', 'vista' => 'est_organigrama'], ['rotulo' => 'Mapa de Pessoal', 'vista' => 'est_mapa']],
        ];
    }

    /** @return array{total: string, periodo: ?string, por_unidade: array<int, string>} */
    private function massaPorUnidade(PeriodoPainel $p): array
    {
        $empresa = $this->consultas->empresa();
        $chave = "to_char(to_date(mes_ano, 'MM/YYYY'), 'YYYY-MM')";
        $ultimo = DB::selectOne("SELECT {$chave} AS chave, string_agg(mes_ano, ', ') AS periodo FROM periodos_processamento_salarial
            WHERE empresa_id = ? AND estado IN ('FECHADO', 'VALIDADO') AND {$chave} <= ? GROUP BY 1 ORDER BY 1 DESC LIMIT 1", [$empresa, $p->chaveMes]);
        if (! $ultimo) {
            return ['total' => '0.00', 'periodo' => null, 'por_unidade' => []];
        }
        $linhas = DB::select("SELECT c.unidade_organica_id, SUM(r.bruto) AS bruto FROM resultados_folha_salarial r
            JOIN periodos_processamento_salarial pp ON pp.id = r.periodo_processamento_salarial_id LEFT JOIN colaboradores c ON c.id = r.colaborador_id
            WHERE pp.empresa_id = ? AND pp.estado IN ('FECHADO', 'VALIDADO') AND to_char(to_date(pp.mes_ano, 'MM/YYYY'), 'YYYY-MM') = ? GROUP BY 1", [$empresa, $ultimo->chave]);

        return ['total' => array_reduce($linhas, fn ($s, $l) => bcadd($s, Indicadores::dinheiro($l->bruto), 2), '0.00'), 'periodo' => $ultimo->periodo,
            'por_unidade' => collect($linhas)->filter(fn ($l) => $l->unidade_organica_id)->mapWithKeys(fn ($l) => [(int) $l->unidade_organica_id => Indicadores::dinheiro($l->bruto)])->all()];
    }
}
