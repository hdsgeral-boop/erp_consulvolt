<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * «Cálculo» de cada rubrica das demonstrações financeiras (Balanço, Demonstração de resultados e Fluxos de caixa):
 * as contas que a compõem, com débitos, créditos e o valor que cada uma contribui, os ajustes de apresentação e,
 * nas rubricas de totais, as rubricas somadas/subtraídas. Pedido do utilizador: «fazer e exibir cálculos detalhados
 * além das contas totalizadoras; abrir as contas de movimento».
 *
 * Os critérios são EXACTAMENTE os de ServicoDemonstracoesFinanceiras (não se recalcula de outra forma): mesmas notas
 * (código exacto; a 14 inclui a 14.1), mesmos períodos (exercício e comparativo), mesmas exclusões (FiltroMapas —
 * classe 9 e apuramento do exercício da data final) e as mesmas regras de sinal; o `valor` devolvido é o do próprio
 * mapa, e a soma das contas + ajustes coincide com ele (testado). Com saldos históricos importados (comparativo), o
 * valor vem do histórico e não há contas a mostrar.
 */
final class ServicoComposicaoDemonstracoes
{
    /** Máximo de movimentos devolvidos no detalhe de uma conta. */
    public const MAXIMO_MOVIMENTOS = 5000;

    /** Rubricas de totais da DR: rubrica => [descrição, [[rubrica, sinal]]]. */
    private const TOTAIS_DR = [
        'total_proveitos' => ['Total proveitos operacionais', [['22', 1], ['23', 1], ['24', 1], ['25', 1], ['26', 1]]],
        'total_custos' => ['Total custos operacionais', [['27', 1], ['28', 1], ['29', 1], ['30', 1]]],
        'resultados_operacionais' => ['Resultados operacionais', [['total_proveitos', 1], ['total_custos', -1]]],
        'resultados_antes_impostos' => ['Resultados antes de impostos', [['resultados_operacionais', 1], ['31', 1], ['32', 1], ['33', 1]]],
        'resultado_actividades_correntes' => ['Resultado líquido das actividades correntes', [['resultados_antes_impostos', 1], ['35', -1]]],
        'resultado_liquido' => ['Resultado líquido do exercício', [['resultado_actividades_correntes', 1], ['34', 1]]],
    ];

    /** Subtotais e totais do Fluxo de caixa (ServicoDemonstracoesFinanceiras::totaisFluxo). */
    private const TOTAIS_FLUXO = [
        '11' => [['111', 1], ['112', 1], ['113', 1]],
        '14' => [['141', 1], ['142', 1]],
        '15' => [['151', 1], ['152', 1]],
        '21' => [['211', 1], ['212', 1], ['213', 1], ['214', 1], ['215', 1], ['216', 1]],
        '22' => [['221', 1], ['222', 1]],
        '31' => [['311', 1], ['312', 1], ['313', 1], ['314', 1]],
        '32' => [['321', 1], ['322', 1], ['323', 1], ['324', 1], ['325', 1], ['326', 1], ['327', 1], ['328', 1]],
        'operacionais' => [['11', 1], ['12', 1], ['13', 1], ['14', 1], ['15', 1]],
        'investimento' => [['21', 1], ['22', 1]],
        'financiamento' => [['31', 1], ['32', 1]],
        'variacao' => [['operacionais', 1], ['investimento', 1], ['financiamento', 1]],
        'caixa_final' => [['variacao', 1], ['caixa_inicial', 1]],
    ];

    private const ROTULOS_FLUXO = [
        'operacionais' => 'Fluxo das actividades operacionais', 'investimento' => 'Fluxo das actividades de investimento',
        'financiamento' => 'Fluxo das actividades de financiamento', 'variacao' => 'Variação de caixa e seus equivalentes',
        'caixa_inicial' => 'Caixa e equivalentes no início do período', 'caixa_final' => 'Caixa e equivalentes no fim do período',
    ];

    private const ROTULOS_BALANCO = [
        'activo_nao_corrente' => 'Subtotal activo não corrente', 'activo_corrente' => 'Subtotal activo corrente', 'capital_proprio' => 'Subtotal capital próprio',
        'passivo_nao_corrente' => 'Subtotal passivo não corrente', 'passivo_corrente' => 'Subtotal passivo corrente', 'activo' => 'Total do activo',
        'passivo' => 'Total do passivo', 'capital_proprio_passivo' => 'Total do capital e passivo',
    ];

    public function __construct(private readonly ContextoEmpresa $contexto, private readonly ServicoDemonstracoesFinanceiras $df) {}

    /**
     * @param  'balanco'|'dr'|'fluxo'  $tipo
     * @param  array<string, mixed>  $f  parâmetros do mapa (datas, filtros), `rubrica`, `periodo` (atual|anterior) e, opcional, `codigo_conta`
     * @return array<string, mixed>
     */
    public function composicao(string $tipo, array $f): array
    {
        $rubrica = trim((string) $f['rubrica']);
        $anterior = ($f['periodo'] ?? 'atual') === 'anterior';
        [$ini, $fim] = $this->periodo($tipo, $f, $anterior);
        $r = match ($tipo) {
            'balanco' => $this->balanco($rubrica, $ini, $fim, $f, $anterior),
            'dr' => $this->dr($rubrica, $ini, $fim, $f, $anterior),
            'fluxo' => $this->fluxo($rubrica, $ini, $fim, $f, $anterior),
            default => throw new ErroNegocio('Demonstração desconhecida.', 'DEMONSTRACAO_INVALIDA', 422),
        };
        $r = ['tipo' => $tipo, 'rubrica' => $rubrica, 'periodo' => ['inicio' => $ini, 'fim' => $fim, 'ambito' => $anterior ? 'anterior' : 'atual']] + $r
            + ['formula' => null, 'componentes' => [], 'contas' => [], 'ajustes' => [], 'historico' => false, 'criterio' => null];
        $r['total_contas'] = array_reduce($r['contas'], fn ($s, $c) => bcadd($s, $c['valor'], 2), '0.00');

        if (! empty($f['codigo_conta'])) {
            if (! isset($r['_consulta'])) {
                throw new ErroNegocio('Esta rubrica é um total: abra as rubricas que a compõem.', 'RUBRICA_SEM_CONTAS', 422);
            }
            $r['conta'] = ['codigo' => (string) $f['codigo_conta'], 'descricao' => $this->descricoes()[(string) $f['codigo_conta']] ?? null];
            $r['movimentos'] = $this->movimentos($r['_consulta'], (string) $f['codigo_conta'], $f);
        }
        unset($r['_consulta']);

        return $r;
    }

    // ------------------------------------------------------------------ Balanço

    private function balanco(string $c, string $ini, string $fim, array $f, bool $anterior): array
    {
        $calc = $this->df->calcularBalanco($ini, $fim, $f);
        $historico = $anterior ? $this->df->historico((int) substr($fim, 0, 4))['demo'] : [];
        if ($historico) {
            $calc = $this->df->aplicarHistoricoBalanco($calc, $historico);
        }
        $titulos = $this->titulosDemo();
        $desc = fn (string $k) => $k === 'res_liq' ? ServicoDemonstracoesFinanceiras::TITULOS['res_liq'] : ($titulos[$k] ?? ServicoDemonstracoesFinanceiras::TITULOS[$k] ?? self::ROTULOS_BALANCO[$k] ?? "Nota {$k}");
        $valor = fn (string $k) => $calc['v'][$k] ?? $calc['totais'][$k] ?? '0.00';

        // totais das secções e do balanço
        $estrutura = ServicoDemonstracoesFinanceiras::ESTRUTURA_BALANCO + [
            'activo' => ['activo_nao_corrente', 'activo_corrente'], 'passivo' => ['passivo_nao_corrente', 'passivo_corrente'],
            'capital_proprio_passivo' => ['capital_proprio', 'passivo'],
        ];
        if (isset($estrutura[$c])) {
            return ['descricao' => self::ROTULOS_BALANCO[$c], 'valor' => $calc['totais'][$c],
                'componentes' => array_map(fn ($k) => ['rubrica' => $k, 'descricao' => $desc($k), 'sinal' => 1, 'valor' => $valor($k)], $estrutura[$c])];
        }
        if ($c === 'res_liq') {
            $dr = $calc['dr'];

            return ['descricao' => $desc('res_liq'), 'valor' => $calc['v']['res_liq'],
                'formula' => '(Σ notas 22 a 26 − Σ notas 27 a 30) + 31 + 32 + 33 − 35 + 34 — lançamentos do exercício ('.$ini.' a '.$fim.')',
                'componentes' => array_map(fn ($n) => ['rubrica' => $n, 'descricao' => $desc($n), 'sinal' => in_array($n, ['27', '28', '29', '30', '35'], true) ? -1 : 1, 'valor' => $dr[$n] ?? '0.00'],
                    ['22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32', '33', '35', '34'])];
        }
        $activo = in_array($c, ServicoDemonstracoesFinanceiras::NOTAS_ACTIVO, true);
        if (! $activo && ! in_array($c, ServicoDemonstracoesFinanceiras::NOTAS_PASSIVO_CP, true) && $c !== '14') {
            throw new ErroNegocio("A rubrica {$c} não faz parte do Balanço.", 'RUBRICA_INVALIDA', 422);
        }
        $base = ['descricao' => $desc($c), 'valor' => $valor($c)];
        if ($historico && array_key_exists($c, $historico)) {
            return $base + ['historico' => true, 'criterio' => 'Valor dos saldos históricos importados para '.substr($fim, 0, 4).' (substitui o cálculo pelos lançamentos).'];
        }

        $ano = (int) substr($fim, 0, 4);
        $consultas = [];
        if ($c === '14') {
            // 14 e 14.1 (C − D, todos os lançamentos até à data final) + notas de resultados anteriores ao início do exercício
            $consultas[] = ['tabela' => 'demo', 'notas' => ['14', '14.1'], 'ate' => $fim, 'ano' => $ano, 'sinal' => 'CD'];
            $consultas[] = ['tabela' => 'demo', 'notas' => array_merge(ServicoDemonstracoesFinanceiras::NOTAS_PROVEITOS, ServicoDemonstracoesFinanceiras::NOTAS_CUSTOS),
                'ate' => $fim, 'antes' => $ini, 'ano' => $ano, 'sinal' => 'CD'];
            $criterio = 'Crédito − débito da nota 14 (e 14.1) até '.$fim.', mais os resultados (notas 22 a 35) dos lançamentos anteriores a '.$ini.'.';
        } else {
            $consultas[] = ['tabela' => 'demo', 'notas' => [$c], 'ate' => $fim, 'ano' => $ano, 'sinal' => $activo ? 'DC' : 'CD'];
            $criterio = ($activo ? 'Débito − crédito' : 'Crédito − débito').' dos lançamentos com a nota '.$c.' até '.$fim.'.';
        }
        $contas = $this->porConta($consultas, $f);
        $ajustes = [];
        $soma = array_reduce($contas, fn ($s, $x) => bcadd($s, $x['valor'], 2), '0.00');
        if (in_array($c, ['8', '9', '10'], true) && bccomp($soma, '0', 2) < 0) {
            $ajustes[] = ['descricao' => 'Saldo credor apresentado em Outros passivos correntes (nota 21)', 'valor' => bcmul($soma, '-1', 2)];
        }
        if ($c === '21') {
            foreach (['8', '9', '10'] as $n) {
                $s = array_reduce($this->porConta([['tabela' => 'demo', 'notas' => [$n], 'ate' => $fim, 'ano' => $ano, 'sinal' => 'DC']], $f), fn ($t, $x) => bcadd($t, $x['valor'], 2), '0.00');
                if (bccomp($s, '0', 2) < 0) {
                    $ajustes[] = ['descricao' => "Saldo credor da nota {$n} ({$desc($n)})", 'valor' => bcmul($s, '-1', 2)];
                }
            }
        }

        return $base + ['contas' => $contas, 'ajustes' => $ajustes, 'criterio' => $criterio, '_consulta' => $consultas];
    }

    // ------------------------------------------------------------------ Demonstração de resultados

    private function dr(string $c, string $ini, string $fim, array $f, bool $anterior): array
    {
        $calc = $this->df->calcularDR($ini, $fim, $f);
        $historico = $anterior ? $this->df->historico((int) substr($fim, 0, 4))['demo'] : [];
        if ($historico) {
            foreach ($historico as $k => $v) {
                if (array_key_exists($k, $calc['v'])) {
                    $calc['v'][$k] = $v;
                }
            }
            $calc = ['v' => $calc['v']] + ServicoDemonstracoesFinanceiras::totaisDR($calc['v']);
        }
        $titulos = $this->titulosDemo();
        $desc = fn (string $k) => self::TOTAIS_DR[$k][0] ?? $titulos[$k] ?? ServicoDemonstracoesFinanceiras::TITULOS[$k] ?? "Nota {$k}";
        $valor = fn (string $k) => $calc['v'][$k] ?? $calc[$k] ?? '0.00';
        if (isset(self::TOTAIS_DR[$c])) {
            return ['descricao' => $desc($c), 'valor' => $calc[$c],
                'componentes' => array_map(fn ($x) => ['rubrica' => $x[0], 'descricao' => $desc($x[0]), 'sinal' => $x[1], 'valor' => $valor($x[0])], self::TOTAIS_DR[$c][1])];
        }
        if (! array_key_exists($c, $calc['v'])) {
            throw new ErroNegocio("A rubrica {$c} não faz parte da Demonstração de resultados.", 'RUBRICA_INVALIDA', 422);
        }
        $base = ['descricao' => $desc($c), 'valor' => $calc['v'][$c]];
        if ($historico && array_key_exists($c, $historico)) {
            return $base + ['historico' => true, 'criterio' => 'Valor dos saldos históricos importados para '.substr($fim, 0, 4).' (substitui o cálculo pelos lançamentos).'];
        }
        $custo = in_array($c, ServicoDemonstracoesFinanceiras::NOTAS_CUSTOS, true);
        $consultas = [['tabela' => 'demo', 'notas' => [$c], 'desde' => $ini, 'ate' => $fim, 'ano' => (int) substr($fim, 0, 4), 'sinal' => $custo ? 'DC' : 'CD']];

        return $base + ['contas' => $this->porConta($consultas, $f), '_consulta' => $consultas,
            'criterio' => ($custo ? 'Débito − crédito' : 'Crédito − débito')." dos lançamentos com a nota {$c} de {$ini} a {$fim}."];
    }

    // ------------------------------------------------------------------ Fluxo de caixa

    private function fluxo(string $c, string $ini, string $fim, array $f, bool $anterior): array
    {
        $calc = $this->df->calcularFluxo($ini, $fim, $f);
        $historico = $anterior ? $this->df->historico((int) substr($fim, 0, 4))['fluxo'] : [];
        $usaHistorico = false;
        if ($historico) {
            $v = $calc['v'];
            foreach ($historico as $k => $x) {
                $k = (string) $k;
                if (! in_array($k, ['tot2', 'caixainicio'], true)) {
                    // como fluxoCaixa(): pagamentos do histórico com valor positivo passam a negativos
                    $v[$k] = in_array($k, ServicoDemonstracoesFinanceiras::FLUXO_PAGAMENTOS_HISTORICO, true) && bccomp($x, '0', 2) > 0 ? bcmul($x, '-1', 2) : $x;
                }
            }
            $hi = $historico['tot2'] ?? $historico['caixainicio'] ?? null;
            $calc = ServicoDemonstracoesFinanceiras::totaisFluxo($v) + ['caixa_inicial' => $hi !== null && bccomp($hi, '0', 2) !== 0 ? $hi : $calc['caixa_inicial']];
            $usaHistorico = true;
        }
        $titulos = NotaFluxoCaixa::query()->orderBy('id')->get(['codigo', 'descricao'])->reverse()->mapWithKeys(fn ($n) => [trim((string) $n->codigo) => $n->descricao])->all();
        $desc = fn (string $k) => self::ROTULOS_FLUXO[$k] ?? ServicoDemonstracoesFinanceiras::TITULOS_FLUXO[$k] ?? $titulos[$k] ?? $k;
        $valor = fn (string $k) => match ($k) {
            'operacionais', 'investimento', 'financiamento', 'variacao' => $calc[$k],
            'caixa_inicial' => $calc['caixa_inicial'],
            'caixa_final' => bcadd($calc['variacao'], $calc['caixa_inicial'], 2),
            default => $calc['v'][$k] ?? '0.00',
        };
        if (isset(self::TOTAIS_FLUXO[$c])) {
            return ['descricao' => $desc($c), 'valor' => $valor($c),
                'componentes' => array_map(fn ($x) => ['rubrica' => $x[0], 'descricao' => $desc($x[0]), 'sinal' => $x[1], 'valor' => $valor($x[0])], self::TOTAIS_FLUXO[$c])];
        }
        $ano = (int) substr($fim, 0, 4);
        if ($c === 'caixa_inicial' && $usaHistorico && bccomp((string) ($historico['tot2'] ?? $historico['caixainicio'] ?? '0'), '0', 2) !== 0) {
            return ['descricao' => $desc($c), 'valor' => $calc['caixa_inicial'], 'historico' => true,
                'criterio' => 'Valor dos saldos históricos importados para '.substr($fim, 0, 4).' (substitui o cálculo pelos lançamentos).'];
        }
        if ($c === 'caixa_inicial') {
            // saldo da classe 4 antes do início (todas as linhas, sem filtros — como calcularFluxo)
            $consultas = [['tabela' => null, 'notas' => [], 'antes' => $ini, 'ate' => $fim, 'ano' => null, 'sinal' => 'DC', 'prefixo' => '4']];

            return ['descricao' => $desc($c), 'valor' => $calc['caixa_inicial'], 'contas' => $this->porConta($consultas, []), '_consulta' => $consultas,
                'criterio' => 'Saldo (débito − crédito) das contas da classe 4 antes de '.$ini.' (todos os lançamentos, sem os filtros do mapa).'];
        }
        $todas = array_merge(...array_values(ServicoDemonstracoesFinanceiras::ESTRUTURA_FLUXO));
        if (! in_array($c, $todas, true)) {
            throw new ErroNegocio("A rubrica {$c} não faz parte do Fluxo de caixa.", 'RUBRICA_INVALIDA', 422);
        }
        $base = ['descricao' => $desc($c), 'valor' => $valor($c)];
        if ($usaHistorico && array_key_exists($c, $historico)) {
            return $base + ['historico' => true, 'criterio' => 'Valor dos saldos históricos importados para '.substr($fim, 0, 4).' (substitui o cálculo pelos lançamentos).'];
        }
        $consultas = [['tabela' => 'fluxo', 'notas' => [$c], 'desde' => $ini, 'ate' => $fim, 'ano' => $ano, 'sinal' => 'CAIXA']];
        $contas = $this->porConta($consultas, $f);
        $soma = array_reduce($contas, fn ($s, $x) => bcadd($s, $x['valor'], 2), '0.00');
        $ajustes = [];
        $pagamento = (bool) array_filter(ServicoDemonstracoesFinanceiras::FLUXO_PAGAMENTOS, fn ($p) => str_starts_with($c, $p));
        $final = $pagamento ? bcmul(ltrim($soma, '-'), '-1', 2) : ltrim($soma, '-');
        if (bccomp($final, $soma, 2) !== 0) {
            $ajustes[] = ['descricao' => $pagamento ? 'Convenção de sinal: pagamentos apresentam-se negativos' : 'Convenção de sinal: recebimentos apresentam-se positivos',
                'valor' => bcsub($final, $soma, 2)];
        }

        return $base + ['contas' => $contas, 'ajustes' => $ajustes, '_consulta' => $consultas,
            'criterio' => "Efeito em caixa dos lançamentos com a nota de fluxo {$c} de {$ini} a {$fim}: nas contas da classe 4 débito − crédito; nas contrapartidas crédito − débito."];
    }

    // ------------------------------------------------------------------ Auxiliares

    /** @return array{0: string, 1: string} início e fim do período (do exercício ou do comparativo), como ServicoDemonstracoesFinanceiras */
    private function periodo(string $tipo, array $f, bool $anterior): array
    {
        $fim = (string) $f['data_fim'];
        $ini = (string) ($f['data_inicio'] ?? substr($fim, 0, 4).'-01-01');
        if (! $anterior) {
            return [$ini, $fim];
        }
        if ($tipo === 'dr' && ($f['modo_comparativo'] ?? 'ano_anterior') !== 'homologo') {
            $a = (int) substr($fim, 0, 4) - 1;

            return ["{$a}-01-01", "{$a}-12-31"];
        }

        return [ServicoDemonstracoesFinanceiras::menosUmAno($ini), ServicoDemonstracoesFinanceiras::menosUmAno($fim)];
    }

    /**
     * Linhas das consultas agregadas por conta (e nota), com o valor pelo sinal da rubrica.
     *
     * @param  list<array<string, mixed>>  $consultas
     * @return list<array<string, mixed>>
     */
    private function porConta(array $consultas, array $f): array
    {
        $porConta = [];
        foreach ($consultas as $q) {
            [$from, $where, $p] = $this->sql($q, $f);
            $expr = match ($q['sinal']) {
                'DC' => "CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END",
                'CD' => "CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE -l.valor END",
                default => "CASE WHEN l.codigo_conta LIKE '4%' THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) ELSE (CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE -l.valor END) END",
            };
            $nota = $q['tabela'] ? 'trim(n.codigo)' : 'NULL::text';
            foreach (DB::select("SELECT l.codigo_conta, {$nota} AS nota, COUNT(*) AS linhas,
                    COALESCE(SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END), 0) AS debito,
                    COALESCE(SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END), 0) AS credito, COALESCE(SUM({$expr}), 0) AS valor
                {$from} WHERE {$where} GROUP BY 1, 2 ORDER BY 1, 2", $p) as $r) {
                $k = $r->codigo_conta.'|'.($r->nota ?? '');
                $a = $porConta[$k] ?? ['codigo_conta' => $r->codigo_conta, 'nota' => $r->nota, 'linhas' => 0, 'debito' => '0.00', 'credito' => '0.00', 'valor' => '0.00'];
                $porConta[$k] = ['codigo_conta' => $a['codigo_conta'], 'nota' => $a['nota'], 'linhas' => $a['linhas'] + (int) $r->linhas,
                    'debito' => bcadd($a['debito'], FiltroMapas::dinheiro($r->debito), 2), 'credito' => bcadd($a['credito'], FiltroMapas::dinheiro($r->credito), 2),
                    'valor' => bcadd($a['valor'], FiltroMapas::dinheiro($r->valor), 2)];
            }
        }
        $descricoes = $this->descricoes();
        $lista = array_values(array_filter($porConta, fn ($x) => $x['linhas'] > 0 && ! (bccomp($x['debito'], '0', 2) === 0 && bccomp($x['credito'], '0', 2) === 0)));
        usort($lista, fn ($a, $b) => strcmp($a['codigo_conta'], $b['codigo_conta']) ?: strcmp((string) $a['nota'], (string) $b['nota']));

        return array_map(fn ($x) => ['codigo_conta' => $x['codigo_conta'], 'descricao' => $descricoes[$x['codigo_conta']] ?? null] + $x, $lista);
    }

    /**
     * FROM/WHERE de uma consulta (valores sempre parametrizados; os nomes de tabela/coluna são constantes desta classe).
     *
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    private function sql(array $q, array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        $from = 'FROM lancamentos_contabeis l';
        $where = 'l.empresa_id = ? AND l.data_documento <= ?';
        $p = [$empresa, $q['ate']];
        if ($q['tabela']) {
            [$tabela, $coluna] = $q['tabela'] === 'fluxo' ? ['notas_fluxo_caixa', 'nota_fluxo_caixa_id'] : ['notas_demonstracao_resultados', 'nota_demonstracao_id'];
            $from .= " JOIN {$tabela} n ON n.id = l.{$coluna} AND n.empresa_id = l.empresa_id";
            $where .= ' AND trim(n.codigo) IN ('.implode(', ', array_fill(0, count($q['notas']), '?')).')';
            $p = array_merge($p, array_values($q['notas']));
        }
        if (! empty($q['prefixo'])) {
            $where .= ' AND l.codigo_conta LIKE ?';
            $p[] = FiltroMapas::like($q['prefixo']);
        }
        if (! empty($q['desde'])) {
            $where .= ' AND l.data_documento >= ?';
            $p[] = $q['desde'];
        }
        if (! empty($q['antes'])) {
            $where .= ' AND l.data_documento < ?';
            $p[] = $q['antes'];
        }
        if ($q['ano'] !== null) {
            [$filtro, $pf] = FiltroMapas::sql('l', $f, $q['ano']);
            $where .= $filtro;
            $p = array_merge($p, $pf);
        }

        return [$from, $where, $p];
    }

    /**
     * Movimentos de uma conta dentro das consultas da rubrica (com a ligação ao lançamento).
     *
     * @param  list<array<string, mixed>>  $consultas
     * @return list<object>
     */
    private function movimentos(array $consultas, string $conta, array $f): array
    {
        $saida = [];
        foreach ($consultas as $q) {
            [$from, $where, $p] = $this->sql($q, $f);
            $nota = $q['tabela'] ? 'trim(n.codigo)' : 'NULL::text';
            foreach (DB::select("SELECT l.id, l.data_documento, d.codigo AS diario, l.numero_lan, l.numero_documento, l.codigo_conta, l.descricao, l.tipo_dc, l.valor, {$nota} AS nota
                {$from} LEFT JOIN diarios_contabeis d ON d.id = l.diario_id WHERE {$where} AND l.codigo_conta = ?
                ORDER BY l.data_documento, l.id LIMIT ".self::MAXIMO_MOVIMENTOS, array_merge($p, [$conta])) as $m) {
                $m->valor = FiltroMapas::dinheiro($m->valor);
                $saida[] = $m;
            }
        }
        usort($saida, fn ($a, $b) => strcmp((string) $a->data_documento, (string) $b->data_documento) ?: $a->id <=> $b->id);

        return array_slice($saida, 0, self::MAXIMO_MOVIMENTOS);
    }

    /** @return array<string, string> */
    private function descricoes(): array
    {
        return DB::table('plano_contas')->where('empresa_id', $this->contexto->obrigatorio())->whereNull('eliminado_em')->pluck('descricao', 'codigo')->all();
    }

    /** @return array<string, string> */
    private function titulosDemo(): array
    {
        return NotaDemonstracao::query()->orderByDesc('id')->get(['codigo', 'descricao'])->mapWithKeys(fn ($n) => [trim((string) $n->codigo) => $n->descricao])->all();
    }
}
