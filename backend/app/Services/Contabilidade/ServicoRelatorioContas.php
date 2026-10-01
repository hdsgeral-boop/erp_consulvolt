<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\AmortizacaoAtivo;
use App\Models\AtivoImobilizado;
use App\Models\Empresa;
use App\Models\RelatorioAnualContas;
use App\Services\Ativos\ServicoRelatoriosAtivos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Relatório e Contas (js/relatorio_contas.js; ecrã relatorio_contas, :927): números do exercício N e do anterior
 * (Balanço, DR e Fluxo pelas notas — ServicoDemonstracoesFinanceiras, com saldos históricos), indicadores (ROE = RL/CP,
 * ROA = RL/Activo, ROS = RL/Vendas e prestações, margem operacional = EBIT/Vendas; liquidez, autonomia, solvabilidade,
 * endividamento), composição por conta de cada nota, mapas de movimentos (notas 4, 5, 12, 13, 14), CMVMC pelas
 * existências (nota 27), multas (78.06) para o apuramento do imposto (nota 35), validações, anexos (balancetes do razão
 * e geral, antes e depois do apuramento; mapa de amortizações) e Nota 4 por categoria de activo (ServicoRelatoriosAtivos,
 * como a agregação por categoria de js/ui_reports.js:4100-4128).
 * Registo anual (relatorios_anuais_contas): configuração, textos e notas a incluir; RASCUNHO → APROVADO ("concluir", só com
 * o exercício encerrado, guarda a fotografia dos números) → reabrir.
 *
 * Decisões: os textos automáticos (geradores de HTML do legado, js/relatorio_contas.js:384-502) são apresentação e ficam no
 * cliente; o servidor fornece todos os números que eles usam e guarda os textos editados (auto = false). A fotografia da
 * conclusão vai na coluna `fotografia` (com `concluido_em`/`concluido_por`, ADR-055). A versão antiga do relatório
 * em js/ui_reports.js:4165-4800 (com valores "oficiais" fixos de 2023/2024 de uma empresa) é código morto: é substituída
 * por js/relatorio_contas.js, carregado depois.
 */
final class ServicoRelatorioContas
{
    /** Notas com tabela (js/relatorio_contas.js:32-68): sinal 1 = devedora; mov = mapa de movimentos. */
    public const NOTAS = [
        '4' => ['grupo' => 'balanco', 'sinal' => 1, 'mov' => true], '5' => ['grupo' => 'balanco', 'sinal' => 1, 'mov' => true],
        '6' => ['grupo' => 'balanco', 'sinal' => 1], '7' => ['grupo' => 'balanco', 'sinal' => 1], '8' => ['grupo' => 'balanco', 'sinal' => 1],
        '9' => ['grupo' => 'balanco', 'sinal' => 1], '10' => ['grupo' => 'balanco', 'sinal' => 1], '11' => ['grupo' => 'balanco', 'sinal' => 1],
        '12' => ['grupo' => 'balanco', 'sinal' => -1, 'mov' => true], '13' => ['grupo' => 'balanco', 'sinal' => -1, 'mov' => true],
        '14' => ['grupo' => 'balanco', 'sinal' => -1, 'mov' => true], '15' => ['grupo' => 'balanco', 'sinal' => -1], '16' => ['grupo' => 'balanco', 'sinal' => -1],
        '17' => ['grupo' => 'balanco', 'sinal' => -1], '18' => ['grupo' => 'balanco', 'sinal' => -1], '19' => ['grupo' => 'balanco', 'sinal' => -1],
        '20' => ['grupo' => 'balanco', 'sinal' => -1], '21' => ['grupo' => 'balanco', 'sinal' => -1],
        '22' => ['grupo' => 'dr', 'sinal' => -1], '23' => ['grupo' => 'dr', 'sinal' => -1], '24' => ['grupo' => 'dr', 'sinal' => -1], '25' => ['grupo' => 'dr', 'sinal' => -1],
        '26' => ['grupo' => 'dr', 'sinal' => -1], '27' => ['grupo' => 'dr', 'sinal' => 1], '28' => ['grupo' => 'dr', 'sinal' => 1], '29' => ['grupo' => 'dr', 'sinal' => 1],
        '30' => ['grupo' => 'dr', 'sinal' => 1], '31' => ['grupo' => 'dr', 'sinal' => -1], '32' => ['grupo' => 'dr', 'sinal' => -1], '33' => ['grupo' => 'dr', 'sinal' => -1],
        '34' => ['grupo' => 'dr', 'sinal' => -1], '35' => ['grupo' => 'dr', 'sinal' => 1],
    ];

    /** Chaves de configuração copiadas do relatório do ano anterior (js/relatorio_contas.js:894). */
    private const CONFIG_COPIADA = ['nome', 'nif', 'sede', 'objecto', 'forma', 'capital', 'sector', 'local', 'director', 'contabilista', 'taxa_imposto',
        'pct_reservas', 'pct_transitados', 'pct_dividendos', 'anexo_razao', 'anexo_geral', 'anexo_amortizacoes', 'graficos'];

    /** Textos livres copiados do ano anterior (js/relatorio_contas.js:503). */
    public const TEXTOS_LIVRES = ['economia', 'enquadramento', 'finais', 'nota_2', 'nota_3'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoDemonstracoesFinanceiras $df,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoRelatoriosAtivos $ativos,
    ) {}

    // ------------------------------------------------------------------ Registo

    /** Relatório do exercício: o registo (ou um novo, não gravado, com a configuração do ano anterior) e os números. */
    public function obter(int $ano): array
    {
        $reg = RelatorioAnualContas::query()->where('ano_relatorio', $ano)->first();
        if ($reg && $reg->estado === 'APROVADO' && $reg->fotografia) {
            $dados = $reg->fotografia;
        } else {
            $dados = $this->calcular($ano);
        }
        if (! $reg) {
            $reg = $this->novo($ano, $dados);
        }
        $config = $reg->configuracao ?? [];

        return ['registo' => ['id' => $reg->id, 'ano' => $ano, 'estado' => $reg->estado ?? 'RASCUNHO', 'configuracao' => $config, 'textos' => $reg->textos ?? (object) [],
            'notas_incluir' => $reg->notas_incluir ?? (object) [], 'atualizado_por' => $reg->atualizado_por, 'atualizado_em' => $reg->atualizado_em?->toIso8601String()],
            'dados' => $dados];
    }

    /** @param  array{configuracao?: array, textos?: array, notas_incluir?: array}  $d */
    public function gravar(int $ano, array $d): RelatorioAnualContas
    {
        return DB::transaction(function () use ($ano, $d) {
            $reg = RelatorioAnualContas::query()->where('ano_relatorio', $ano)->lockForUpdate()->first();
            if ($reg && $reg->estado === 'APROVADO') {
                throw new ErroNegocio("O Relatório e Contas de {$ano} está concluído: reabra-o para alterar.", 'RELATORIO_CONCLUIDO', 422);
            }
            $reg ??= $this->novo($ano, null);
            $config = array_merge($reg->configuracao ?? [], $d['configuracao'] ?? []);
            $reg->fill(['estado' => 'RASCUNHO', 'estado_original' => 'DRAFT', 'configuracao' => $config,
                'textos' => array_key_exists('textos', $d) ? $d['textos'] : ($reg->textos ?? []),
                'notas_incluir' => array_key_exists('notas_incluir', $d) ? $d['notas_incluir'] : ($reg->notas_incluir ?? []),
                'atualizado_por' => Auth::user()?->nome_utilizador])->save();

            return $reg;
        });
    }

    /** rcConcluir (js/relatorio_contas.js:1170-1184): só com o exercício encerrado; os erros exigem `forcar`. */
    public function concluir(int $ano, bool $forcar): array
    {
        if (! $this->exercicios->encerrado($this->contexto->obrigatorio(), $ano)) {
            throw new ErroNegocio("O exercício de {$ano} ainda não está encerrado: só é possível concluir o Relatório e Contas de exercícios encerrados.", 'EXERCICIO_ABERTO', 422);
        }
        $dados = $this->calcular($ano);
        $erros = array_values(array_filter($dados['alertas'], fn ($a) => $a['tipo'] === 'erro'));
        if ($erros && ! $forcar) {
            throw new ErroNegocio('O relatório tem erros (ver alertas): confirme para concluir mesmo assim.', 'RELATORIO_COM_ERROS', 422, ['alertas' => $erros]);
        }

        return DB::transaction(function () use ($ano, $dados) {
            $reg = RelatorioAnualContas::query()->where('ano_relatorio', $ano)->lockForUpdate()->first() ?? $this->novo($ano, $dados);
            $reg->fill(['estado' => 'APROVADO', 'estado_original' => 'COMPLETED', 'fotografia' => $dados, 'concluido_em' => now(),
                'concluido_por' => Auth::user()?->nome_utilizador, 'atualizado_por' => Auth::user()?->nome_utilizador])->save();

            return $this->obter($ano);
        });
    }

    public function reabrir(int $ano): array
    {
        DB::transaction(function () use ($ano) {
            $reg = RelatorioAnualContas::query()->where('ano_relatorio', $ano)->lockForUpdate()->firstOrFail();
            $reg->fill(['estado' => 'RASCUNHO', 'estado_original' => 'DRAFT', 'fotografia' => null, 'concluido_em' => null, 'concluido_por' => null,
                'atualizado_por' => Auth::user()?->nome_utilizador])->save();
        });

        return $this->obter($ano);
    }

    /** novoRegisto (js/relatorio_contas.js:889-899): configuração por omissão + a do ano anterior + textos livres copiados. */
    private function novo(int $ano, ?array $dados): RelatorioAnualContas
    {
        $empresa = Empresa::query()->find($this->contexto->obrigatorio());
        $cfg = ['nome' => $empresa?->nome ?? '', 'nif' => $empresa?->nif ?? '', 'sede' => $empresa?->endereco ?? '',
            'objecto' => 'prestação de serviços, comércio a grosso e a retalho', 'forma' => 'sociedade por quotas', 'capital' => 0, 'sector' => '',
            'local' => 'Luanda', 'data' => now()->toDateString(), 'director' => '', 'contabilista' => '', 'taxa_imposto' => 25, 'prejuizos_fiscais' => 0,
            'pct_reservas' => 0, 'pct_transitados' => 100, 'pct_dividendos' => 0, 'anexo_razao' => true, 'anexo_geral' => false, 'anexo_amortizacoes' => true, 'graficos' => true];
        $textos = [];
        if ($anterior = RelatorioAnualContas::query()->where('ano_relatorio', $ano - 1)->first()) {
            foreach (self::CONFIG_COPIADA as $k) {
                if (isset($anterior->configuracao[$k]) && $anterior->configuracao[$k] !== '') {
                    $cfg[$k] = $anterior->configuracao[$k];
                }
            }
            foreach (self::TEXTOS_LIVRES as $k) {
                if (! empty($anterior->textos[$k]['html'])) {
                    $textos[$k] = ['html' => $anterior->textos[$k]['html'], 'auto' => false, 'copiado' => $ano - 1];
                }
            }
        }

        return new RelatorioAnualContas(['ano_relatorio' => $ano, 'estado' => 'RASCUNHO', 'estado_original' => 'DRAFT', 'configuracao' => $cfg, 'textos' => $textos, 'notas_incluir' => []]);
    }

    // ------------------------------------------------------------------ Números (rcCalcular, js/relatorio_contas.js:211-370)

    public function calcular(int $ano): array
    {
        $empresa = $this->contexto->obrigatorio();
        $N = $this->exercicio($ano);
        $P = $this->exercicio($ano - 1);
        $hist = $this->df->historico($ano - 1);
        $usouHistorico = (bool) $hist['demo'];
        if ($usouHistorico) {
            $P['bal'] = $this->df->aplicarHistoricoBalanco($P['bal'], $hist['demo']);
            $v = $P['dr']['v'];
            foreach ($hist['demo'] as $k => $valor) {
                if (array_key_exists($k, $v)) {
                    $v[$k] = $valor;
                }
            }
            $P['dr'] = ['v' => $v] + ServicoDemonstracoesFinanceiras::totaisDR($v);
            if ($hist['fluxo']) {
                $fv = $P['fluxo']['v'];
                foreach ($hist['fluxo'] as $k => $valor) {
                    $k = (string) $k;
                    if (! in_array($k, ['tot2', 'caixainicio'], true)) {
                        $fv[$k] = in_array($k, ServicoDemonstracoesFinanceiras::FLUXO_PAGAMENTOS, true) && bccomp($valor, '0', 2) > 0 ? bcmul($valor, '-1', 2) : $valor;
                    }
                }
                $caixa = $hist['fluxo']['tot2'] ?? $hist['fluxo']['caixainicio'] ?? $P['fluxo']['caixa_inicial'];
                $P['fluxo'] = ServicoDemonstracoesFinanceiras::totaisFluxo($fv) + ['caixa_inicial' => $caixa];
            }
        }
        $nInd = $this->indicadores($N['bal'], $N['dr']);
        $pInd = $this->indicadores($P['bal'], $P['dr']);
        $encerrado = $this->exercicios->encerrado($empresa, $ano);

        $alertas = [];
        if (! $encerrado) {
            $alertas[] = ['tipo' => 'info', 'texto' => "O exercício de {$ano} ainda não está encerrado: o relatório é emitido como PROVISÓRIO. A conclusão fica disponível após o encerramento do exercício."];
        }
        $dif = bcsub($N['bal']['totais']['activo'], $N['bal']['totais']['capital_proprio_passivo'], 2);
        if (bccomp(ltrim($dif, '-'), '0.01', 2) >= 0) {
            $alertas[] = ['tipo' => 'erro', 'texto' => "O Balanço de {$ano} não está equilibrado: diferença de Kz {$dif} entre o Activo e o Capital Próprio + Passivo."];
        }
        if ($N['bal']['sem_nota']['linhas']) {
            $s = $N['bal']['sem_nota'];
            $alertas[] = ['tipo' => 'aviso', 'texto' => "Existem {$s['linhas']} linhas até {$ano} sem Nota DEMO (Débitos Kz {$s['debito']} · Créditos Kz {$s['credito']}). Associe-as às notas para que entrem nas demonstrações."];
        }
        $difRL = bcsub($nInd['rl'], $N['rl_contas'], 2);
        if (bccomp(ltrim($difRL, '-'), '1', 2) >= 0) {
            $alertas[] = ['tipo' => 'aviso', 'texto' => "O resultado líquido pelas notas (Kz {$nInd['rl']}) difere do resultado pelas contas das classes 6, 7 e 87 (Kz {$N['rl_contas']}) em Kz {$difRL}. Verifique o mapeamento das notas DEMO."];
        }
        if ($usouHistorico) {
            $alertas[] = ['tipo' => 'info', 'texto' => 'Os valores comparativos de '.($ano - 1).' vêm dos saldos históricos introduzidos.'];
        }

        $emp = Empresa::query()->find($empresa);

        return [
            'versao' => 1, 'gerado_em' => now()->toIso8601String(), 'ano' => $ano, 'ano_anterior' => $ano - 1, 'encerrado' => $encerrado, 'moeda' => 'AKZ',
            'empresa' => ['nome' => $emp?->nome, 'nif' => $emp?->nif, 'endereco' => $emp?->endereco, 'e_consolidacao' => (bool) $emp?->e_consolidacao],
            'n' => ['balanco' => $N['bal'], 'dr' => $N['dr'], 'fluxo' => $N['fluxo'], 'indicadores' => $nInd, 'cmvmc' => $N['cmvmc'], 'multas' => $N['multas']],
            'n1' => ['balanco' => $P['bal'], 'dr' => $P['dr'], 'fluxo' => $P['fluxo'], 'indicadores' => $pInd, 'cmvmc' => $P['cmvmc'], 'multas' => $P['multas']],
            'composicao' => $this->composicao($ano), 'movimentos' => $this->movimentos($ano), 'alertas' => $alertas, 'historico_anterior' => $usouHistorico,
            'colaboradores' => (int) DB::table('colaboradores')->where('empresa_id', $empresa)->whereNull('eliminado_em')
                ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'INACTIVO'))->count(),
            'tem_moeda_estrangeira' => DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->whereBetween('data_documento', ["{$ano}-01-01", "{$ano}-12-31"])
                ->whereNotNull('codigo_moeda')->where('codigo_moeda', '<>', '')->where('codigo_moeda', '<>', 'AOA')->exists(),
            'nota_4_por_categoria' => $this->ativos->resumoPorCategoria(),
            'anexos' => ['razao_dezembro' => $this->balanceteAnexo($ano, false, 2), 'razao_apuramento' => $this->balanceteAnexo($ano, true, 2),
                'geral_dezembro' => $this->balanceteAnexo($ano, false, 0), 'geral_apuramento' => $this->balanceteAnexo($ano, true, 0),
                'amortizacoes' => $this->mapaAmortizacoes($ano)],
        ];
    }

    /** porAno (js/relatorio_contas.js:227-236). */
    private function exercicio(int $ano): array
    {
        $ini = "{$ano}-01-01";
        $fim = "{$ano}-12-31";
        $bal = $this->df->calcularBalanco($ini, $fim);
        $dr = $this->df->calcularDR($ini, $fim);
        $fluxo = $this->df->calcularFluxo($ini, $fim, ['incluir_classe_9' => false]);
        $empresa = $this->contexto->obrigatorio();
        $semPontos = "replace(replace(l.codigo_conta, '.', ''), ' ', '')";
        $base = "FROM lancamentos_contabeis l WHERE l.empresa_id = ? AND l.codigo_conta NOT LIKE '9%'
            AND NOT (COALESCE(l.periodo_id, 0) IN (13, 14) AND NOT EXISTS (SELECT 1 FROM diarios_contabeis d_sal WHERE d_sal.id = l.diario_id AND d_sal.codigo = 'SAL') AND EXTRACT(YEAR FROM l.data_documento) = ?)";
        $r = DB::selectOne("SELECT
                COALESCE(SUM(CASE WHEN {$semPontos} ~ '^2[2-7]' AND l.data_documento < ? THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) END), 0) AS ei,
                COALESCE(SUM(CASE WHEN {$semPontos} ~ '^2[2-7]' THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) END), 0) AS ef,
                COALESCE(SUM(CASE WHEN {$semPontos} LIKE '21%' AND l.tipo_dc = 'D' AND l.data_documento >= ? THEN l.valor END), 0) AS compras,
                COALESCE(SUM(CASE WHEN {$semPontos} LIKE '7806%' AND l.data_documento >= ? THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) END), 0) AS multas,
                COALESCE(SUM(CASE WHEN ({$semPontos} ~ '^[67]' OR {$semPontos} LIKE '87%') AND l.data_documento >= ? THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) END), 0) AS rl
            {$base} AND l.data_documento <= ?", [$ini, $ini, $ini, $ini, $empresa, $ano, $fim]);
        $ei = FiltroMapas::dinheiro($r->ei);
        $ef = FiltroMapas::dinheiro($r->ef);
        $compras = FiltroMapas::dinheiro($r->compras);

        return ['bal' => $bal, 'dr' => $dr, 'fluxo' => $fluxo, 'cmvmc' => ['ei' => $ei, 'compras' => $compras, 'ef' => $ef, 'custo' => bcsub(bcadd($ei, $compras, 2), $ef, 2)],
            'multas' => FiltroMapas::dinheiro($r->multas), 'rl_contas' => bcmul(FiltroMapas::dinheiro($r->rl), '-1', 2)];
    }

    /** indicadores (js/relatorio_contas.js:173-195). Rácios em decimal (null quando o denominador é ~0). */
    public function indicadores(array $bal, array $dr): array
    {
        $g = fn ($k) => $dr['v'][$k] ?? '0.00';
        $b = fn ($k) => $bal['v'][$k] ?? '0.00';
        $i = [];
        $i['vendas'] = $g('22');
        $i['prestacoes'] = $g('23');
        $i['vendas_prestacoes'] = bcadd($i['vendas'], $i['prestacoes'], 2);
        $i['outros_proveitos_op'] = bcadd(bcadd($g('24'), $g('25'), 2), $g('26'), 2);
        $i['ganhos_op'] = bcadd($i['vendas_prestacoes'], $i['outros_proveitos_op'], 2);
        $i['cmvmc'] = $g('27');
        $i['margem_bruta'] = bcsub($i['ganhos_op'], $i['cmvmc'], 2);
        $i['pessoal'] = $g('28');
        $i['outros_custos'] = $g('30');
        $i['gastos_op'] = bcadd($i['pessoal'], $i['outros_custos'], 2);
        $i['ebitda'] = bcsub($i['margem_bruta'], $i['gastos_op'], 2);
        $i['nao_operacionais'] = $g('33');
        $i['extraordinarios'] = $g('34');
        $i['ebitda_ajustado'] = bcadd(bcadd($i['ebitda'], $i['nao_operacionais'], 2), $i['extraordinarios'], 2);
        $i['amortizacoes'] = $g('29');
        $i['ebit'] = bcsub($i['ebitda_ajustado'], $i['amortizacoes'], 2);
        $i['financeiros'] = bcadd($g('31'), $g('32'), 2);
        $i['rai'] = bcadd($i['ebit'], $i['financeiros'], 2);
        $i['imposto'] = $g('35');
        $i['rl'] = bcsub($i['rai'], $i['imposto'], 2);
        $t = $bal['totais'];
        $i['imobilizado'] = bcadd($b('4'), $b('5'), 2);
        $i['activo_nao_corrente'] = $t['activo_nao_corrente'];
        $i['existencias'] = $b('8');
        $i['contas_receber'] = $b('9');
        $i['disponibilidades'] = $b('10');
        $i['outros_activos_correntes'] = $b('11');
        $i['activo_corrente'] = $t['activo_corrente'];
        $i['activo'] = $t['activo'];
        $i['capital'] = $b('12');
        $i['reservas'] = $b('13');
        $i['transitados'] = $b('14');
        $i['rl_balanco'] = $b('res_liq');
        $i['capital_proprio'] = $t['capital_proprio'];
        $i['passivo_nao_corrente'] = $t['passivo_nao_corrente'];
        $i['contas_pagar'] = $b('19');
        $i['emprestimos_curto_prazo'] = bcadd($b('20'), $b('15'), 2);
        $i['outros_passivos_correntes'] = $b('21');
        $i['passivo_corrente'] = $t['passivo_corrente'];
        $i['passivo'] = $t['passivo'];
        $div = fn ($a, $d) => abs((float) $d) < 0.005 ? null : round((float) $a / (float) $d, 6);
        $i['liquidez_geral'] = $div($i['activo_corrente'], $i['passivo_corrente']);
        $i['liquidez_reduzida'] = $div(bcsub($i['activo_corrente'], $i['existencias'], 2), $i['passivo_corrente']);
        $i['liquidez_imediata'] = $div($i['disponibilidades'], $i['passivo_corrente']);
        $i['autonomia_financeira'] = $div($i['capital_proprio'], $i['activo']);
        $i['solvabilidade'] = $div($i['capital_proprio'], $i['passivo']);
        $i['endividamento'] = $div($i['passivo'], $i['capital_proprio']);
        $i['endividamento_total'] = $div($i['passivo'], $i['activo']);
        $i['estrutura_endividamento'] = $div($i['passivo_corrente'], $i['passivo']);
        $i['roe'] = $div($i['rl'], $i['capital_proprio']);
        $i['roa'] = $div($i['rl'], $i['activo']);
        $i['ros'] = $div($i['rl'], $i['vendas_prestacoes']);
        $i['margem_operacional'] = $div($i['ebit'], $i['vendas_prestacoes']);

        return $i;
    }

    /** compNota (js/relatorio_contas.js:251-262): por conta, N e N-1; notas de balanço = saldo acumulado, de resultados = exercício. */
    private function composicao(int $ano): array
    {
        $out = [];
        foreach ([$ano => 'n', $ano - 1 => 'n1'] as $a => $col) {
            foreach ($this->somasNotaConta($a) as $r) {
                $codigo = $r->nota === '14.1' ? '14' : $r->nota;
                $cfg = self::NOTAS[$codigo] ?? null;
                if (! $cfg) {
                    continue;
                }
                $saldo = $cfg['grupo'] === 'balanco' ? $r->total : $r->exercicio;
                $valor = bcmul(FiltroMapas::dinheiro($saldo), (string) $cfg['sinal'], 2);
                $linha = &$out[$codigo][$r->codigo_conta];
                $linha ??= ['conta' => $r->codigo_conta, 'descricao' => $r->descricao, 'n' => '0.00', 'n1' => '0.00'];
                $linha[$col] = bcadd($linha[$col], $valor, 2);
                unset($linha);
            }
        }
        foreach ($out as $codigo => $linhas) {
            $linhas = array_filter($linhas, fn ($l) => bccomp($l['n'], '0', 2) !== 0 || bccomp($l['n1'], '0', 2) !== 0);
            uksort($linhas, 'strnatcmp');
            $out[$codigo] = array_values($linhas);
        }

        return $out;
    }

    /** movNota (js/relatorio_contas.js:263-276): saldo inicial, aumentos, diminuições e saldo final por conta (exercício N). */
    private function movimentos(int $ano): array
    {
        $out = [];
        foreach ($this->somasNotaConta($ano) as $r) {
            $codigo = $r->nota === '14.1' ? '14' : $r->nota;
            if (empty(self::NOTAS[$codigo]['mov'])) {
                continue;
            }
            $devedora = self::NOTAS[$codigo]['sinal'] === 1;
            $inicial = bcmul(FiltroMapas::dinheiro(bcsub((string) $r->total, (string) $r->exercicio, 2)), $devedora ? '1' : '-1', 2);
            $aum = FiltroMapas::dinheiro($devedora ? $r->debito_exercicio : $r->credito_exercicio);
            $dim = FiltroMapas::dinheiro($devedora ? $r->credito_exercicio : $r->debito_exercicio);
            $l = &$out[$codigo][$r->codigo_conta];
            $l ??= ['conta' => $r->codigo_conta, 'descricao' => $r->descricao, 'inicial' => '0.00', 'aumentos' => '0.00', 'diminuicoes' => '0.00'];
            $l['inicial'] = bcadd($l['inicial'], $inicial, 2);
            $l['aumentos'] = bcadd($l['aumentos'], $aum, 2);
            $l['diminuicoes'] = bcadd($l['diminuicoes'], $dim, 2);
            unset($l);
        }
        foreach ($out as $codigo => $linhas) {
            uksort($linhas, 'strnatcmp');
            $out[$codigo] = array_values(array_map(fn ($l) => $l + ['final' => bcsub(bcadd($l['inicial'], $l['aumentos'], 2), $l['diminuicoes'], 2)], $linhas));
        }

        return $out;
    }

    /** @return list<object> (nota, conta): saldo D−C acumulado até 31/12, do exercício, e débitos/créditos do exercício */
    private function somasNotaConta(int $ano): array
    {
        return DB::select("SELECT trim(n.codigo) AS nota, l.codigo_conta, MIN(pc.descricao) AS descricao,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS total,
                SUM(CASE WHEN l.data_documento >= ? THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) ELSE 0 END) AS exercicio,
                SUM(CASE WHEN l.data_documento >= ? AND l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS debito_exercicio,
                SUM(CASE WHEN l.data_documento >= ? AND l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS credito_exercicio
            FROM lancamentos_contabeis l
            JOIN notas_demonstracao_resultados n ON n.id = l.nota_demonstracao_id AND n.empresa_id = l.empresa_id
            LEFT JOIN plano_contas pc ON pc.empresa_id = l.empresa_id AND pc.codigo = l.codigo_conta AND pc.eliminado_em IS NULL
            WHERE l.empresa_id = ? AND l.data_documento <= ? AND l.codigo_conta NOT LIKE '9%'
              AND NOT (COALESCE(l.periodo_id, 0) IN (13, 14) AND NOT EXISTS (SELECT 1 FROM diarios_contabeis d_sal WHERE d_sal.id = l.diario_id AND d_sal.codigo = 'SAL') AND EXTRACT(YEAR FROM l.data_documento) = ?)
            GROUP BY 1, 2", ["{$ano}-01-01", "{$ano}-01-01", "{$ano}-01-01", $this->contexto->obrigatorio(), "{$ano}-12-31", $ano]);
    }

    /**
     * Anexos: balancete (js/relatorio_contas.js:310-324) até 31/12, sem (ou com) o apuramento do ano; as contas de resultados
     * (6, 7, 82–89) só contam o exercício. nivel 2 = razão (2 primeiros dígitos sem pontos); 0 = conta.
     */
    private function balanceteAnexo(int $ano, bool $comApuramento, int $nivel): array
    {
        $semPontos = "replace(replace(l.codigo_conta, '.', ''), ' ', '')";
        $conta = $nivel ? "left({$semPontos}, {$nivel})" : 'l.codigo_conta';
        $linhas = DB::select("SELECT {$conta} AS conta, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS deb, SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS cred
            FROM lancamentos_contabeis l
            WHERE l.empresa_id = ? AND l.data_documento <= ? AND l.codigo_conta NOT LIKE '9%'
              AND NOT ({$semPontos} ~ '^(6|7|8[2-9])' AND l.data_documento < ?)".($comApuramento ? '' : '
              AND NOT (COALESCE(l.periodo_id, 0) IN (13, 14) AND NOT EXISTS (SELECT 1 FROM diarios_contabeis d_sal WHERE d_sal.id = l.diario_id AND d_sal.codigo = \'SAL\') AND EXTRACT(YEAR FROM l.data_documento) = ?)').'
            GROUP BY 1 ORDER BY 1', array_merge([$this->contexto->obrigatorio(), "{$ano}-12-31", "{$ano}-01-01"], $comApuramento ? [] : [$ano]));
        $desc = DB::table('plano_contas')->where('empresa_id', $this->contexto->obrigatorio())->whereNull('eliminado_em')->get(['codigo', 'descricao'])
            ->flatMap(fn ($c) => [$c->codigo => $c->descricao, str_replace(['.', ' '], '', $c->codigo) => $c->descricao])->all();
        $out = [];
        foreach ($linhas as $l) {
            $d = FiltroMapas::dinheiro($l->deb);
            $c = FiltroMapas::dinheiro($l->cred);
            $s = bcsub($d, $c, 2);
            $out[] = ['conta' => $l->conta, 'descricao' => $desc[$l->conta] ?? null, 'debito' => $d, 'credito' => $c,
                'saldo_devedor' => bccomp($s, '0', 2) > 0 ? $s : '0.00', 'saldo_credor' => bccomp($s, '0', 2) < 0 ? ltrim($s, '-') : '0.00'];
        }
        usort($out, fn ($a, $b) => strnatcmp($a['conta'], $b['conta']));

        return $out;
    }

    /** Mapa de amortizações (js/relatorio_contas.js:325-333): activos ACTIVO ou com amortização integrada no ano. */
    private function mapaAmortizacoes(int $ano): array
    {
        $doAno = AmortizacaoAtivo::query()->where('contabilizado', true)->where('periodo_codigo', 'like', "%-{$ano}")
            ->selectRaw('ativo_imobilizado_id, SUM(valor) AS total')->groupBy('ativo_imobilizado_id')->pluck('total', 'ativo_imobilizado_id');
        $out = [];
        foreach (AtivoImobilizado::query()->orderBy('codigo')->get() as $a) {
            if ($a->estado !== AtivoImobilizado::ESTADO_ATIVO && ! isset($doAno[$a->id])) {
                continue;
            }
            $vida = (int) $a->vida_util;
            $valor = FiltroMapas::dinheiro($a->valor_aquisicao);
            $acum = FiltroMapas::dinheiro($a->amortizacao_acumulada);
            $out[] = ['codigo' => $a->codigo, 'descricao' => $a->descricao, 'aquisicao' => $a->data_aquisicao?->toDateString(), 'valor' => $valor,
                'vida_anos' => $vida ? round($vida / 12, 2) : 0, 'taxa' => $vida ? round(12 / $vida, 6) : null,
                'exercicio' => FiltroMapas::dinheiro($doAno[$a->id] ?? 0), 'acumulada' => $acum, 'valor_liquido' => bcsub($valor, $acum, 2)];
        }
        usort($out, fn ($a, $b) => strnatcmp((string) $a['codigo'], (string) $b['codigo']));

        return $out;
    }
}
