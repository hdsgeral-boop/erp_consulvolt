<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Support\Tenancy\ContextoEmpresa;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Demonstrações financeiras (PGC Angola) a partir das NOTAS das linhas de lançamento (nota_demonstracao_id e
 * nota_fluxo_caixa_id), com o comparativo do ano anterior e os saldos históricos (saldos_historicos).
 * Fontes: js/ui_reports.js:862-1472 (Balanço, DR, Fluxo de Caixa) e o motor revisto js/relatorio_contas.js:88-209,
 * que é a referência (é o mais recente e corrige o primeiro). Tudo agregado no PostgreSQL por (nota, antes/depois
 * do início do exercício); o PHP só aplica as regras de sinal às poucas dezenas de somas.
 *
 * Regras (paridade):
 *   - Activo (notas 4–11) = Σ(D−C); Capital próprio e passivo (12, 13, 15–21) = Σ(C−D); 14 e 14.1 → Resultados transitados;
 *   - notas de resultados (22–35): as linhas anteriores ao início do exercício vão para Resultados transitados (14) e as do
 *     exercício formam o Resultado líquido = (Σ22..26 − Σ27..30) + 31 + 32 + 33 − 35 + 34, igual ao da DR;
 *   - notas 8, 9 e 10 com saldo credor passam para Outros passivos correntes (21);
 *   - excluídas a classe 9 e as linhas dos períodos 13/14 (apuramento) do exercício em análise — de CADA exercício
 *     (actual e comparativo);
 *   - linhas sem nota não entram e são reportadas ("Movimentos por mapear", com débitos e créditos);
 *   - comparativo: saldos históricos do ano anterior (tipo DEMO/FLUXO), quando existem, substituem os valores calculados
 *     por código, e o resultado líquido é recalculado a partir deles.
 *
 * Correcções face ao legado:
 *   1. o Balanço do ecrã de mapas ignorava as notas 16 e 20 e somava a nota 6 sem a mostrar (js/ui_reports.js:882-944):
 *      usa-se a estrutura completa do Relatório e Contas (js/relatorio_contas.js:99-115);
 *   2. retirado o ajuste "SPAZIO" (js/ui_reports.js:978-982), que somava outra vez o resultado do ano anterior aos
 *      resultados transitados — esse resultado já lá está pelas linhas do ano anterior; desequilibrava o Balanço;
 *   3. o comparativo do ecrã de mapas incluía o apuramento (período 13) do ano anterior, o que anulava a DR desse ano
 *      (js/ui_reports.js:291-298); agora exclui-se o apuramento de cada exercício (como js/relatorio_contas.js:229);
 *   4. notas com código fora da estrutura (ex.: 1, 2, 3 ou códigos errados) eram descartadas em silêncio e desequilibravam
 *      o Balanço sem explicação: entram no controlo "notas_fora_da_estrutura";
 *   5. Fluxo de caixa: o legado forçava o sinal linha a linha (|valor|), pelo que um estorno (ADR-016) contava duas vezes
 *      em vez de anular; agora soma-se o efeito em caixa com sinal por nota e só depois se aplica a convenção
 *      (pagamentos negativos, recebimentos positivos) — igual ao legado quando todas as linhas da nota têm o mesmo sentido;
 *   6. o início do exercício do Balanço é, por omissão, 1 de Janeiro do ano da data de fim (o legado usava a data inicial
 *      do filtro, que fazia passar o resultado dos meses anteriores para "resultados transitados"); pode indicar-se outra.
 */
final class ServicoDemonstracoesFinanceiras
{
    public const NOTAS_ACTIVO = ['4', '5', '6', '7', '8', '9', '10', '11'];

    public const NOTAS_PASSIVO_CP = ['12', '13', '15', '16', '17', '18', '19', '20', '21'];

    public const NOTAS_PROVEITOS = ['22', '23', '24', '25', '26', '31', '32', '33', '34'];

    public const NOTAS_CUSTOS = ['27', '28', '29', '30', '35'];

    /** Códigos de fluxo que são pagamentos (js/ui_reports.js:1285; js/relatorio_contas.js:144). */
    public const FLUXO_PAGAMENTOS = ['112', '113', '12', '13', '142', '152', '22', '221', '222', '32', '321', '322', '323', '324', '325', '326', '327', '328'];

    /** Mesma lista, por igualdade, para os saldos históricos (js/ui_reports.js:1330). */
    private const FLUXO_PAGAMENTOS_HISTORICO = ['112', '113', '12', '13', '142', '152', '221', '222', '321', '322', '323', '324', '325', '326', '327', '328'];

    /** Títulos das notas (js/relatorio_contas.js:32-68), usados quando a empresa não tem a nota nas tabelas auxiliares. */
    public const TITULOS = [
        '4' => 'Imobilizações corpóreas', '5' => 'Imobilizações incorpóreas', '6' => 'Investimentos em subsidiárias e associadas',
        '7' => 'Outros activos financeiros', '8' => 'Existências', '9' => 'Contas a receber', '10' => 'Disponibilidades',
        '11' => 'Outros activos correntes', '12' => 'Capital', '13' => 'Reservas', '14' => 'Resultados transitados',
        'res_liq' => 'Resultado líquido do exercício', '15' => 'Empréstimos de médio e longo prazos', '16' => 'Impostos diferidos',
        '17' => 'Provisões para pensões', '18' => 'Provisões para outros riscos e encargos', '19' => 'Contas a pagar',
        '20' => 'Empréstimos de curto prazo', '21' => 'Outros passivos correntes', '22' => 'Vendas', '23' => 'Prestações de serviços',
        '24' => 'Outros proveitos operacionais', '25' => 'Variação nos produtos acabados e em vias de fabrico',
        '26' => 'Trabalhos para a própria empresa', '27' => 'Custo das mercadorias vendidas e das matérias consumidas',
        '28' => 'Custos com o pessoal', '29' => 'Amortizações', '30' => 'Outros custos e perdas operacionais',
        '31' => 'Resultados financeiros', '32' => 'Resultados de filiais e associadas', '33' => 'Resultados não operacionais',
        '34' => 'Resultados extraordinários', '35' => 'Imposto sobre o rendimento',
    ];

    /** Estrutura do Balanço (js/relatorio_contas.js:775-793). */
    private const ESTRUTURA_BALANCO = [
        'activo_nao_corrente' => ['4', '5', '6', '7'],
        'activo_corrente' => ['8', '9', '10', '11'],
        'capital_proprio' => ['12', '13', '14', 'res_liq'],
        'passivo_nao_corrente' => ['16', '17', '18'],
        'passivo_corrente' => ['19', '20', '15', '21'],
    ];

    /** Linhas do Fluxo de caixa (js/ui_reports.js:1414-1464). */
    private const ESTRUTURA_FLUXO = [
        'operacionais' => ['111', '112', '113', '11', '12', '13', '14', '141', '142', '15', '151', '152'],
        'investimento' => ['21', '211', '212', '213', '214', '215', '216', '22', '221', '222'],
        'financiamento' => ['31', '311', '312', '313', '314', '32', '321', '322', '323', '324', '325', '326', '327', '328'],
    ];

    private const TITULOS_FLUXO = [
        '111' => 'Recebimentos (de caixa) de clientes', '112' => 'Pagamentos (de caixa) a fornecedores', '113' => 'Pagamentos ao pessoal',
        '11' => 'Caixa gerada pelas operações', '12' => 'Juros pagos', '13' => 'Pagamentos de impostos sobre lucros',
        '14' => 'Fluxos de caixa antes das rubricas outras actividades operacionais', '141' => 'Outros recebimentos relativos à actividade operacional',
        '142' => 'Outros pagamentos relativos à actividade operacional', '15' => 'Fluxo de caixa antes das rubricas extraordinárias',
        '151' => 'Recebimentos relacionados com rubricas extraordinárias', '152' => 'Pagamentos relacionados com rubricas extraordinárias',
        '21' => 'Recebimentos (de caixa) de:', '211' => 'Imobilizações corpóreas', '212' => 'Imobilizações incorpóreas', '213' => 'Investimentos financeiros',
        '214' => 'Subsídios a investimento', '215' => 'Juros e proveitos similares', '216' => 'Dividendos ou lucros recebidos',
        '22' => 'Pagamentos (de caixa) de:', '221' => 'Imobilizações corpóreas', '222' => 'Imobilizações incorpóreas',
        '31' => 'Recebimentos (de caixa) de:', '311' => 'Aumentos de capital, prestações suplementares e prémios de emissão',
        '312' => 'Cobertura de prejuízos', '313' => 'Empréstimos obtidos', '314' => 'Subsídios à exploração e doações',
        '32' => 'Pagamentos (de caixa) de:', '321' => 'Reduções de capital e prestações suplementares', '322' => 'Compra de acções ou quotas próprias',
        '323' => 'Dividendos ou lucros pagos', '324' => 'Empréstimos pagos (amortização)', '325' => 'Amortização de contratos de locação financeira',
        '326' => 'Dividendos ou lucros pagos (P)', '327' => 'Empréstimos obtidos (P)', '328' => 'Juros e custos similares (P)',
    ];

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    // ------------------------------------------------------------------ Balanço

    /**
     * @param  array<string, mixed>  $f  data_fim; data_inicio (início do exercício; omissão = 1/1 do ano de data_fim); comparativo (bool);
     *                                   filtro_contas, unidade_negocio_id, centro_custo_id, incluir_apuramento
     */
    public function balanco(array $f): array
    {
        $fim = $f['data_fim'];
        $ini = $f['data_inicio'] ?? substr($fim, 0, 4).'-01-01';
        $actual = $this->calcularBalanco($ini, $fim, $f);
        $comparativo = ($f['comparativo'] ?? true) !== false;
        $anterior = $comparativo ? $this->calcularBalanco(self::menosUmAno($ini), self::menosUmAno($fim), $f) : null;
        $historico = false;
        if ($anterior && ($h = $this->historico((int) substr($fim, 0, 4) - 1)['demo'])) {
            $anterior = $this->aplicarHistoricoBalanco($anterior, $h);
            $historico = true;
        }
        $titulos = $this->titulosNotas();

        $seccoes = [];
        foreach (self::ESTRUTURA_BALANCO as $seccao => $codigos) {
            $linhas = array_map(fn ($c) => ['nota' => $c === 'res_liq' ? null : $c, 'codigo' => $c, 'descricao' => $c === 'res_liq' ? self::TITULOS['res_liq'] : ($titulos[$c] ?? self::TITULOS[$c] ?? "Nota {$c}"),
                'atual' => $actual['v'][$c] ?? '0.00', 'anterior' => $anterior ? ($anterior['v'][$c] ?? '0.00') : null], $codigos);
            $seccoes[$seccao] = ['linhas' => $linhas, 'total' => ['atual' => $actual['totais'][$seccao], 'anterior' => $anterior['totais'][$seccao] ?? null]];
        }
        $controlo = fn (?array $b) => $b === null ? null : [
            'diferenca' => bcsub($b['totais']['activo'], $b['totais']['capital_proprio_passivo'], 2),
            'equilibrado' => bccomp($b['totais']['activo'], $b['totais']['capital_proprio_passivo'], 2) === 0,
            'sem_nota' => $b['sem_nota'], 'notas_fora_da_estrutura' => $b['ignoradas'],
        ];

        return [
            'data_inicio' => $ini, 'data_fim' => $fim, 'ano' => (int) substr($fim, 0, 4), 'ano_anterior' => (int) substr($fim, 0, 4) - 1,
            'seccoes' => $seccoes,
            'totais' => ['atual' => $actual['totais'], 'anterior' => $anterior['totais'] ?? null],
            'resultado_liquido' => ['atual' => $actual['v']['res_liq'], 'anterior' => $anterior['v']['res_liq'] ?? null],
            'notas' => ['atual' => $actual['v'], 'anterior' => $anterior['v'] ?? null],
            'controlo' => ['atual' => $controlo($actual), 'anterior' => $controlo($anterior)],
            'historico_anterior' => $historico,
        ];
    }

    /**
     * Cálculo do Balanço até $fim, com o exercício a começar em $ini (as notas de resultados anteriores vão para a 14).
     *
     * @return array{v: array<string, string>, dr: array<string, string>, totais: array<string, string>, sem_nota: array<string, mixed>, ignoradas: list<array<string, string>>}
     */
    public function calcularBalanco(string $ini, string $fim, array $f = []): array
    {
        $v = array_fill_keys(array_merge(self::NOTAS_ACTIVO, self::NOTAS_PASSIVO_CP, ['14', 'res_liq']), '0.00');
        $dr = array_fill_keys(array_merge(self::NOTAS_PROVEITOS, self::NOTAS_CUSTOS), '0.00');
        $semNota = ['linhas' => 0, 'debito' => '0.00', 'credito' => '0.00'];
        $ignoradas = [];
        foreach ($this->somasPorNota($fim, null, $ini, $f) as $r) {
            $saldoD = bcsub(FiltroMapas::dinheiro($r->debito), FiltroMapas::dinheiro($r->credito), 2);
            $c = $r->nota;
            if ($c === null || $c === '') {
                $semNota['linhas'] += (int) $r->linhas;
                $semNota['debito'] = bcadd($semNota['debito'], FiltroMapas::dinheiro($r->debito), 2);
                $semNota['credito'] = bcadd($semNota['credito'], FiltroMapas::dinheiro($r->credito), 2);

                continue;
            }
            $alvo = $r->anterior ? '14' : 'res_liq';
            if (in_array($c, self::NOTAS_ACTIVO, true)) {
                $v[$c] = bcadd($v[$c], $saldoD, 2);
            } elseif (in_array($c, self::NOTAS_PASSIVO_CP, true)) {
                $v[$c] = bcsub($v[$c], $saldoD, 2);
            } elseif ($c === '14' || $c === '14.1') {
                $v['14'] = bcsub($v['14'], $saldoD, 2);
            } elseif (in_array($c, self::NOTAS_PROVEITOS, true) || in_array($c, self::NOTAS_CUSTOS, true)) {
                $v[$alvo] = bcsub($v[$alvo], $saldoD, 2);
                if (! $r->anterior) {
                    $dr[$c] = in_array($c, self::NOTAS_CUSTOS, true) ? bcadd($dr[$c], $saldoD, 2) : bcsub($dr[$c], $saldoD, 2);
                }
            } else {
                $ignoradas[$c] = bcadd($ignoradas[$c] ?? '0.00', $saldoD, 2);
            }
        }
        $v['res_liq'] = self::resultadoLiquido($dr);
        foreach (['8', '9', '10'] as $n) {
            if (bccomp($v[$n], '0', 2) < 0) {
                $v['21'] = bcadd($v['21'], bcmul($v[$n], '-1', 2), 2);
                $v[$n] = '0.00';
            }
        }
        ksort($ignoradas, SORT_NATURAL);

        return ['v' => $v, 'dr' => $dr, 'totais' => self::totaisBalanco($v), 'sem_nota' => $semNota,
            'ignoradas' => array_map(fn ($c, $s) => ['codigo' => (string) $c, 'saldo_devedor' => $s], array_keys($ignoradas), $ignoradas)];
    }

    // ------------------------------------------------------------------ Demonstração de resultados

    /**
     * @param  array<string, mixed>  $f  data_inicio, data_fim; comparativo (bool); modo_comparativo: ano_anterior (omissão: ano civil anterior
     *                                   completo) | homologo (mesmo período do ano anterior)
     */
    public function demonstracaoResultados(array $f): array
    {
        $actual = $this->calcularDR($f['data_inicio'], $f['data_fim'], $f);
        $anterior = null;
        $historico = false;
        if (($f['comparativo'] ?? true) !== false) {
            $anoAnt = (int) substr($f['data_fim'], 0, 4) - 1;
            [$pi, $pf] = ($f['modo_comparativo'] ?? 'ano_anterior') === 'homologo'
                ? [self::menosUmAno($f['data_inicio']), self::menosUmAno($f['data_fim'])] : ["{$anoAnt}-01-01", "{$anoAnt}-12-31"];
            $anterior = $this->calcularDR($pi, $pf, $f);
            if ($h = $this->historico($anoAnt)['demo']) {
                foreach ($h as $k => $valor) {
                    if (array_key_exists($k, $anterior['v'])) {
                        $anterior['v'][$k] = $valor;
                    }
                }
                $anterior = ['v' => $anterior['v']] + self::totaisDR($anterior['v']);
                $historico = true;
            }
        }
        $titulos = $this->titulosNotas();
        $linha = fn (string $c) => ['nota' => $c, 'descricao' => $titulos[$c] ?? self::TITULOS[$c], 'atual' => $actual['v'][$c], 'anterior' => $anterior['v'][$c] ?? null];
        $total = fn (string $k, string $rotulo) => ['descricao' => $rotulo, 'atual' => $actual[$k], 'anterior' => $anterior[$k] ?? null];

        return [
            'data_inicio' => $f['data_inicio'], 'data_fim' => $f['data_fim'], 'ano' => (int) substr($f['data_fim'], 0, 4),
            'proveitos_operacionais' => ['linhas' => array_map($linha, ['22', '23', '24', '25', '26']), 'total' => $total('total_proveitos', 'Total proveitos operacionais')],
            'custos_operacionais' => ['linhas' => array_map($linha, ['27', '28', '29', '30']), 'total' => $total('total_custos', 'Total custos operacionais')],
            'resultados_operacionais' => $total('resultados_operacionais', 'Resultados operacionais'),
            'outros_resultados' => array_map($linha, ['31', '32', '33']),
            'resultados_antes_impostos' => $total('resultados_antes_impostos', 'Resultados antes de impostos'),
            'imposto' => $linha('35'),
            'resultado_actividades_correntes' => $total('resultado_actividades_correntes', 'Resultado líquido das actividades correntes'),
            'resultados_extraordinarios' => $linha('34'),
            'resultado_liquido' => $total('resultado_liquido', 'Resultado líquido do exercício'),
            'notas' => ['atual' => $actual['v'], 'anterior' => $anterior['v'] ?? null],
            'historico_anterior' => $historico,
        ];
    }

    /** @return array<string, mixed> v (por nota) + totais da DR */
    public function calcularDR(string $ini, string $fim, array $f = []): array
    {
        $v = array_fill_keys(array_merge(self::NOTAS_PROVEITOS, self::NOTAS_CUSTOS), '0.00');
        foreach ($this->somasPorNota($fim, $ini, null, $f) as $r) {
            if ($r->nota === null || ! array_key_exists($r->nota, $v)) {
                continue;
            }
            $saldoD = bcsub(FiltroMapas::dinheiro($r->debito), FiltroMapas::dinheiro($r->credito), 2);
            $v[$r->nota] = in_array($r->nota, self::NOTAS_CUSTOS, true) ? bcadd($v[$r->nota], $saldoD, 2) : bcsub($v[$r->nota], $saldoD, 2);
        }
        uksort($v, fn ($a, $b) => (int) $a <=> (int) $b);

        return ['v' => $v] + self::totaisDR($v);
    }

    // ------------------------------------------------------------------ Fluxo de caixa

    /** @param  array<string, mixed>  $f  data_inicio, data_fim, comparativo (bool) */
    public function fluxoCaixa(array $f): array
    {
        $actual = $this->calcularFluxo($f['data_inicio'], $f['data_fim'], $f);
        $anterior = null;
        $historico = false;
        if (($f['comparativo'] ?? true) !== false) {
            $anterior = $this->calcularFluxo(self::menosUmAno($f['data_inicio']), self::menosUmAno($f['data_fim']), $f);
            $h = $this->historico((int) substr($f['data_fim'], 0, 4) - 1)['fluxo'];
            if ($h) {
                foreach ($h as $k => $valor) {
                    $k = (string) $k;
                    if (in_array($k, ['tot2', 'caixainicio'], true)) {
                        continue;
                    }
                    $anterior['v'][$k] = in_array($k, self::FLUXO_PAGAMENTOS_HISTORICO, true) && bccomp($valor, '0', 2) > 0 ? bcmul($valor, '-1', 2) : $valor;
                }
                $anterior = self::totaisFluxo($anterior['v']) + ['caixa_inicial' => $anterior['caixa_inicial'], 'variacao_classe_4' => $anterior['variacao_classe_4']];
                $historico = true;
            }
            // js/ui_reports.js:1353 — "tot2" ou "caixainicio" do histórico têm prioridade (valor 0 conta como ausente, como no legado)
            $hi = $h['tot2'] ?? $h['caixainicio'] ?? null;
            if ($hi !== null && bccomp($hi, '0', 2) !== 0) {
                $anterior['caixa_inicial'] = $hi;
            }
        }
        $titulos = NotaFluxoCaixa::query()->orderBy('id')->get(['codigo', 'descricao'])->reverse()->mapWithKeys(fn ($n) => [trim((string) $n->codigo) => $n->descricao])->all();
        $seccoes = [];
        foreach (self::ESTRUTURA_FLUXO as $seccao => $codigos) {
            $seccoes[$seccao] = [
                'linhas' => array_map(fn ($c) => ['codigo' => $c, 'descricao' => self::TITULOS_FLUXO[$c] ?? ($titulos[$c] ?? $c), 'subtotal' => strlen($c) === 2,
                    'atual' => $actual['v'][$c] ?? '0.00', 'anterior' => $anterior ? ($anterior['v'][$c] ?? '0.00') : null], $codigos),
                'total' => ['atual' => $actual[$seccao], 'anterior' => $anterior[$seccao] ?? null],
            ];
        }
        $fimA = $anterior ? bcadd($anterior['variacao'], $anterior['caixa_inicial'], 2) : null;

        return [
            'data_inicio' => $f['data_inicio'], 'data_fim' => $f['data_fim'], 'ano' => (int) substr($f['data_fim'], 0, 4),
            'seccoes' => $seccoes,
            'variacao_caixa' => ['atual' => $actual['variacao'], 'anterior' => $anterior['variacao'] ?? null],
            'caixa_inicial' => ['atual' => $actual['caixa_inicial'], 'anterior' => $anterior['caixa_inicial'] ?? null],
            'caixa_final' => ['atual' => bcadd($actual['variacao'], $actual['caixa_inicial'], 2), 'anterior' => $fimA],
            'notas' => ['atual' => $actual['v'], 'anterior' => $anterior['v'] ?? null],
            'controlo' => ['variacao_classe_4' => $actual['variacao_classe_4'], 'nao_explicado' => bcsub($actual['variacao_classe_4'], $actual['variacao'], 2)],
            'historico_anterior' => $historico,
        ];
    }

    /** @return array<string, mixed> */
    public function calcularFluxo(string $ini, string $fim, array $f = []): array
    {
        $empresa = $this->contexto->obrigatorio();
        [$filtro, $pf] = FiltroMapas::sql('l', $f, (int) substr($fim, 0, 4));
        // efeito em caixa: linha de meios monetários (classe 4) D−C; contrapartida C−D
        $linhas = DB::select("SELECT trim(n.codigo) AS nota,
                SUM(CASE WHEN l.codigo_conta LIKE '4%' THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END)
                         ELSE (CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE -l.valor END) END) AS caixa
            FROM lancamentos_contabeis l JOIN notas_fluxo_caixa n ON n.id = l.nota_fluxo_caixa_id AND n.empresa_id = l.empresa_id
            WHERE l.empresa_id = ? AND l.data_documento BETWEEN ? AND ?{$filtro}
            GROUP BY 1", array_merge([$empresa, $ini, $fim], $pf));
        $v = [];
        foreach ($linhas as $r) {
            if ($r->nota === null || $r->nota === '') {
                continue;
            }
            $valor = FiltroMapas::dinheiro($r->caixa);
            $abs = ltrim($valor, '-');
            $pagamento = (bool) array_filter(self::FLUXO_PAGAMENTOS, fn ($p) => str_starts_with($r->nota, $p));
            $v[$r->nota] = bcadd($v[$r->nota] ?? '0.00', $pagamento ? bcmul($abs, '-1', 2) : $abs, 2);
        }
        // js/ui_reports.js:1310-1322 — saldo da classe 4 antes do início (todas as linhas, sem filtros); e, como controlo
        // (novo), a variação real da classe 4 no período, para mostrar o que as notas de fluxo não explicam
        $caixa = DB::selectOne("SELECT COALESCE(SUM(CASE WHEN data_documento < ? THEN (CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END) END), 0) AS inicial,
                COALESCE(SUM(CASE WHEN data_documento >= ? THEN (CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END) END), 0) AS variacao
            FROM lancamentos_contabeis WHERE empresa_id = ? AND data_documento <= ? AND codigo_conta LIKE '4%'", [$ini, $ini, $empresa, $fim]);

        return self::totaisFluxo($v) + ['caixa_inicial' => FiltroMapas::dinheiro($caixa->inicial), 'variacao_classe_4' => FiltroMapas::dinheiro($caixa->variacao)];
    }

    // ------------------------------------------------------------------ Controlo e detalhe

    /**
     * "Movimentos por mapear" (js/ui_reports.js:381-408): linhas sem nota às demonstrações (ou com nota inexistente) no período.
     *
     * @return array{resumo: array<string, mixed>, linhas: list<object>}
     */
    public function movimentosSemNota(array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        [$filtro, $pf] = FiltroMapas::sql('l', $f, (int) substr($f['data_fim'], 0, 4));
        $periodo = ! empty($f['data_inicio']) ? ' AND l.data_documento >= ?' : '';
        $params = array_merge([$empresa, $f['data_fim']], $periodo ? [$f['data_inicio']] : [], $pf);
        $base = "FROM lancamentos_contabeis l LEFT JOIN notas_demonstracao_resultados n ON n.id = l.nota_demonstracao_id AND n.empresa_id = l.empresa_id
            LEFT JOIN diarios_contabeis d ON d.id = l.diario_id
            WHERE l.empresa_id = ? AND l.data_documento <= ?{$periodo} AND n.id IS NULL{$filtro}";
        $resumo = DB::selectOne("SELECT COUNT(*) AS linhas, COALESCE(SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END), 0) AS debito,
            COALESCE(SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END), 0) AS credito {$base}", $params);
        $porConta = DB::select("SELECT l.codigo_conta, COUNT(*) AS linhas, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS saldo
            {$base} GROUP BY 1 ORDER BY 1", $params);
        $linhas = DB::select("SELECT l.id, l.data_documento, d.codigo AS diario, l.numero_lan, l.numero_documento, l.codigo_conta, l.descricao, l.tipo_dc, l.valor
            {$base} ORDER BY l.data_documento, l.id LIMIT ".max(1, min((int) ($f['limite'] ?? 2000), 20000)), $params);
        foreach ($porConta as $c) {
            $c->saldo = FiltroMapas::dinheiro($c->saldo);
        }

        return [
            'resumo' => ['linhas' => (int) $resumo->linhas, 'debito' => FiltroMapas::dinheiro($resumo->debito), 'credito' => FiltroMapas::dinheiro($resumo->credito),
                'diferenca' => bcsub(FiltroMapas::dinheiro($resumo->debito), FiltroMapas::dinheiro($resumo->credito), 2)],
            'por_conta' => $porConta,
            'linhas' => array_map(function ($l) {
                $l->valor = FiltroMapas::dinheiro($l->valor);

                return $l;
            }, $linhas),
        ];
    }

    /**
     * Detalhe de uma nota (drillDownToLancamentos, js/ui_reports.js:2300-2436): linhas da nota e das suas sub-notas
     * ("14" inclui "14.1"). Correcção: o legado usava "começa por" (a nota 1 apanhava 10–19) e não aplicava as exclusões
     * dos mapas (classe 9, apuramento), pelo que o total do detalhe não batia com o mapa.
     */
    public function detalheNota(string $tipo, int $notaId, array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        $fluxo = $tipo === 'fluxo';
        $nota = ($fluxo ? NotaFluxoCaixa::query() : NotaDemonstracao::query())->findOrFail($notaId);
        $codigo = trim((string) $nota->codigo);
        $tabela = $fluxo ? 'notas_fluxo_caixa' : 'notas_demonstracao_resultados';
        $coluna = $fluxo ? 'nota_fluxo_caixa_id' : 'nota_demonstracao_id';
        [$filtro, $pf] = FiltroMapas::sql('l', $f, (int) substr($f['data_fim'], 0, 4));
        $periodo = ! empty($f['data_inicio']) ? ' AND l.data_documento >= ?' : '';
        $linhas = DB::select("SELECT l.id, l.data_documento, d.codigo AS diario, l.numero_lan, l.numero_documento, l.codigo_conta, l.descricao, l.tipo_dc, l.valor, trim(n.codigo) AS nota
            FROM lancamentos_contabeis l JOIN {$tabela} n ON n.id = l.{$coluna} AND n.empresa_id = l.empresa_id
            LEFT JOIN diarios_contabeis d ON d.id = l.diario_id
            WHERE l.empresa_id = ? AND l.data_documento <= ?{$periodo} AND (trim(n.codigo) = ? OR trim(n.codigo) LIKE ?){$filtro}
            ORDER BY l.data_documento, l.id", array_merge([$empresa, $f['data_fim']], $periodo ? [$f['data_inicio']] : [], [$codigo, FiltroMapas::like($codigo.'.')], $pf));
        $d = $c = '0.00';
        foreach ($linhas as $l) {
            $l->valor = FiltroMapas::dinheiro($l->valor);
            $l->tipo_dc === 'D' ? $d = bcadd($d, $l->valor, 2) : $c = bcadd($c, $l->valor, 2);
        }

        return ['nota' => ['id' => $nota->id, 'codigo' => $codigo, 'descricao' => $nota->descricao, 'tipo' => $fluxo ? 'fluxo' : 'demonstracao'],
            'totais' => ['linhas' => count($linhas), 'debito' => $d, 'credito' => $c, 'saldo_devedor' => bcsub($d, $c, 2)], 'linhas' => $linhas];
    }

    // ------------------------------------------------------------------ Auxiliares

    /**
     * Somas por (código da nota, anterior ao início do exercício), até $fim.
     *
     * @return list<object{nota: ?string, anterior: bool, debito: string, credito: string, linhas: int}>
     */
    private function somasPorNota(string $fim, ?string $desde, ?string $inicioExercicio, array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        [$filtro, $pf] = FiltroMapas::sql('l', $f, (int) substr($fim, 0, 4));
        $anterior = $inicioExercicio ? '(l.data_documento < ?)' : 'FALSE';

        return DB::select("SELECT trim(n.codigo) AS nota, {$anterior} AS anterior,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS debito,
                SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS credito, COUNT(*) AS linhas
            FROM lancamentos_contabeis l LEFT JOIN notas_demonstracao_resultados n ON n.id = l.nota_demonstracao_id AND n.empresa_id = l.empresa_id
            WHERE l.empresa_id = ? AND l.data_documento <= ?".($desde ? ' AND l.data_documento >= ?' : '')."{$filtro}
            GROUP BY 1, 2", array_merge($inicioExercicio ? [$inicioExercicio] : [], [$empresa, $fim], $desde ? [$desde] : [], $pf));
    }

    /** @return array{demo: array<string, string>, fluxo: array<string, string>} códigos em minúsculas => valor */
    public function historico(int $ano): array
    {
        $h = ['demo' => [], 'fluxo' => []];
        foreach (DB::table('saldos_historicos')->where('empresa_id', $this->contexto->obrigatorio())->where('ano', $ano)
            ->whereIn('tipo', ['DEMONSTRACAO_RESULTADOS', 'FLUXO_CAIXA'])->orderBy('id')->get(['tipo', 'codigo', 'valor']) as $r) {
            $h[$r->tipo === 'FLUXO_CAIXA' ? 'fluxo' : 'demo'][mb_strtolower(trim((string) $r->codigo))] = FiltroMapas::dinheiro($r->valor);
        }

        return $h;
    }

    /** js/relatorio_contas.js:197-202 — os históricos substituem os valores por código e o resultado é recalculado. */
    public function aplicarHistoricoBalanco(array $b, array $h): array
    {
        foreach ($h as $k => $valor) {
            if ($k === 'res_liq') {
                continue;
            }
            $b['v'][$k] = $valor;
            if (array_key_exists($k, $b['dr'])) {
                $b['dr'][$k] = $valor;
            }
        }
        $b['v']['res_liq'] = self::resultadoLiquido($b['dr']);
        $b['totais'] = self::totaisBalanco($b['v']);

        return $b;
    }

    /** @param  array<string, string>  $dr */
    public static function resultadoLiquido(array $dr): string
    {
        $g = fn ($k) => $dr[$k] ?? '0.00';
        $soma = fn (array $ks) => array_reduce($ks, fn ($s, $k) => bcadd($s, $g($k), 2), '0.00');

        return bcadd(bcsub(bcadd(bcsub($soma(['22', '23', '24', '25', '26']), $soma(['27', '28', '29', '30']), 2), $soma(['31', '32', '33']), 2), $g('35'), 2), $g('34'), 2);
    }

    /** @param  array<string, string>  $v */
    public static function totaisBalanco(array $v): array
    {
        $s = fn (array $ks) => array_reduce($ks, fn ($t, $k) => bcadd($t, $v[$k] ?? '0.00', 2), '0.00');
        $t = [];
        foreach (self::ESTRUTURA_BALANCO as $seccao => $codigos) {
            $t[$seccao] = $s($codigos);
        }
        $t['activo'] = bcadd($t['activo_nao_corrente'], $t['activo_corrente'], 2);
        $t['passivo'] = bcadd($t['passivo_nao_corrente'], $t['passivo_corrente'], 2);
        $t['capital_proprio_passivo'] = bcadd($t['capital_proprio'], $t['passivo'], 2);

        return $t;
    }

    /** @param  array<string, string>  $v */
    public static function totaisDR(array $v): array
    {
        $s = fn (array $ks) => array_reduce($ks, fn ($t, $k) => bcadd($t, $v[$k] ?? '0.00', 2), '0.00');
        $prov = $s(['22', '23', '24', '25', '26']);
        $cust = $s(['27', '28', '29', '30']);
        $op = bcsub($prov, $cust, 2);
        $rai = bcadd($op, $s(['31', '32', '33']), 2);
        $correntes = bcsub($rai, $v['35'] ?? '0.00', 2);

        return ['total_proveitos' => $prov, 'total_custos' => $cust, 'resultados_operacionais' => $op, 'resultados_antes_impostos' => $rai,
            'resultado_actividades_correntes' => $correntes, 'resultado_liquido' => bcadd($correntes, $v['34'] ?? '0.00', 2)];
    }

    /** @param  array<string, string>  $v */
    public static function totaisFluxo(array $v): array
    {
        $g = fn ($c) => $v[$c] ?? '0.00';
        $s = fn (array $cs) => array_reduce($cs, fn ($t, $c) => bcadd($t, $g($c), 2), '0.00');
        $v['11'] = $s(['111', '112', '113']);
        $v['14'] = $s(['141', '142']);
        $v['15'] = $s(['151', '152']);
        $v['21'] = $s(['211', '212', '213', '214', '215', '216']);
        $v['22'] = $s(['221', '222']);
        $v['31'] = $s(['311', '312', '313', '314']);
        $v['32'] = $s(['321', '322', '323', '324', '325', '326', '327', '328']);
        $op = bcadd(bcadd(bcadd(bcadd($v['11'], $g('12'), 2), $g('13'), 2), $v['14'], 2), $v['15'], 2);
        $inv = bcadd($v['21'], $v['22'], 2);
        $fin = bcadd($v['31'], $v['32'], 2);

        return ['v' => $v, 'operacionais' => $op, 'investimento' => $inv, 'financiamento' => $fin, 'variacao' => bcadd(bcadd($op, $inv, 2), $fin, 2)];
    }

    /** @return array<string, string> código => descrição (primeira nota com o código) */
    private function titulosNotas(): array
    {
        return NotaDemonstracao::query()->orderByDesc('id')->get(['codigo', 'descricao'])
            ->mapWithKeys(fn ($n) => [trim((string) $n->codigo) => $n->descricao])->all();
    }

    /** Mesma data no ano anterior (29/02 → 01/03, como new Date(y-1, m-1, d) do legado). */
    public static function menosUmAno(string $data): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (! $d) {
            throw new ErroNegocio("Data inválida: {$data}.", 'DATA_INVALIDA', 422);
        }

        return $d->modify('-1 year')->format('Y-m-d');
    }
}
