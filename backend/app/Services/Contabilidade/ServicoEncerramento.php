<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Services\Ativos\ServicoAmortizacoes;
use App\Services\Logistica\ServicoArmazens;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Encerramento do exercício (js/ui_closing.js): cinco passos de apuramento no período 13, validações finais,
 * cadeado do exercício, reabertura e cancelamento do apuramento.
 *
 * Passos (paridade, ui_closing.js:145-1333): cada passo lê os saldos do ano (excluindo a classe 9 e o próprio diário do
 * passo), transfere cada conta para a agrupadora ".9" do seu grau 2, desta para a conta da classe 8 indicada, centraliza na
 * agregadora (8219, 839, 849, 859, 869), transfere para a conta de resultado (881, 882, 883, 884, 886) e integra na 889.
 * Um lançamento por passo no diário AP-O/AP-F/AP-FIL/AP-NO/AP-EX, datado de 31-12, documento AP-OP/AP-FIN/AP-FIL/AP-NO/AP-EX-AAAA,
 * linhas com periodo_id = 13 (o legado filtra o apuramento por period_id 13, ui_closing.js:1740 e 1863).
 * O passo 4 usa a agrupadora "<grau 2>19" (6819/7819), como o legado (ui_closing.js:971-972) e os planos reais.
 *
 * Validações (runClosingValidactions, ui_closing.js:1335-1611): sequência dos exercícios (anos ≥ 2024 com dados têm de estar
 * encerrados), Σ D = Σ C do ano, classes 6 e 7 a zero, amortizações por integrar, armazém vs contas 22+26 (tolerância 1,00) e
 * balanço histórico (saldos iniciais). Só sem divergências o exercício é encerrado.
 *
 * Correcções face ao legado:
 *   - repetir um passo ESTORNA o apuramento anterior desse passo (ADR-016); o legado apagava as linhas do diário (ui_closing.js:158-167);
 *   - cancelar o apuramento estorna os lançamentos do período 13 do ano; o legado apagava-os (ui_closing.js:1862-1869);
 *   - as contas do apuramento têm de existir e ser de movimento: o legado gravava linhas em contas inexistentes ou totalizadoras
 *     (ex.: 769 é totalizadora nos planos reais); agora a pré-visualização lista-as e a execução recusa, ou cria as em falta
 *     quando pedido (as totalizadoras nunca: corrigem-se no plano);
 *   - valores em decimal exacto ao cêntimo (o legado somava floats e tolerava 0,001);
 *   - amortizações: usa ServicoAmortizacoes::porIntegrarNoAno; o legado nunca detectava nada (estado 'ACTIVE' e períodos
 *     'AAAA-MM', ui_closing.js:1438-1447; ADR-051);
 *   - inventário: o stock dos artigos que movimentam stock é valorizado ao custo médio (ServicoArmazens::stock) e comparado com o
 *     SALDO acumulado das contas 22+26 a 31-12; o legado valorizava ao preço de venda do artigo e comparava só com o movimento do
 *     ano (ui_closing.js:1466-1484);
 *   - balanço histórico: só os saldos de tipo DEMONSTRACAO_RESULTADOS; o legado misturava os códigos dos fluxos de caixa (ui_closing.js:1497-1500);
 *   - encerrar, reabrir e cancelar ficam serializados por empresa (lock) e registados na auditoria.
 * Não há lançamento de abertura do exercício seguinte: o legado não o tinha — os mapas lêem os saldos de balanço acumulados.
 */
final class ServicoEncerramento
{
    public const PERIODO_APURAMENTO = 13;

    public const TIPO_ORIGEM = 'ENCERRAMENTO';

    /** Primeiro ano sujeito à verificação da sequência (ui_closing.js:1381). */
    public const ANO_INICIO_SEQUENCIA = 2024;

    public const CONTA_RESULTADO_LIQUIDO = '889';

    /** Tolerância da comparação armazém × contabilidade (ui_closing.js:1486). */
    private const TOLERANCIA_INVENTARIO = '1.00';

    /**
     * [prefixo, sufixo da agrupadora, conta da classe 8] por passo, agregadora, conta de resultado e textos (ui_closing.js).
     *
     * @var array<int, array<string, mixed>>
     */
    public const PASSOS = [
        1 => ['titulo' => 'Apuramento do Resultado Operacional', 'diario' => 'AP-O', 'nome_diario' => 'Apuramento Operacional', 'documento' => 'AP-OP',
            'transferencias' => [['61', '9', '821'], ['62', '9', '822'], ['63', '9', '823'], ['64', '9', '824'], ['65', '9', '825'],
                ['71', '9', '826'], ['72', '9', '827'], ['73', '9', '828'], ['75', '9', '829']],
            'agregadora' => '8219', 'resultado' => '881', 'texto_resultado' => 'Resultado Operacional do Exercício', 'texto_integracao' => 'Integração do Resultado Operacional'],
        2 => ['titulo' => 'Apuramento do Resultado Financeiro', 'diario' => 'AP-F', 'nome_diario' => 'Apuramento Financeiro', 'documento' => 'AP-FIN',
            'transferencias' => [['66', '9', '831'], ['76', '9', '832']],
            'agregadora' => '839', 'resultado' => '882', 'texto_resultado' => 'Resultado Financeiro do Exercício', 'texto_integracao' => 'Integração do Resultado Financeiro'],
        3 => ['titulo' => 'Apuramento do Resultado em Filiais e Associadas', 'diario' => 'AP-FIL', 'nome_diario' => 'Apuramento em Filiais e Associadas', 'documento' => 'AP-FIL',
            'transferencias' => [['67', '9', '841'], ['77', '9', '842']],
            'agregadora' => '849', 'resultado' => '883', 'texto_resultado' => 'Resultado em Filiais e Associadas', 'texto_integracao' => 'Integração do Resultado de Filiais'],
        4 => ['titulo' => 'Apuramento do Resultado Não Operacional', 'diario' => 'AP-NO', 'nome_diario' => 'Apuramento Não Operacional', 'documento' => 'AP-NO',
            'transferencias' => [['68', '19', '851'], ['78', '19', '852']],
            'agregadora' => '859', 'resultado' => '884', 'texto_resultado' => 'Resultado Não Operacional', 'texto_integracao' => 'Integração do Resultado Não Operacional'],
        5 => ['titulo' => 'Apuramento do Resultado Extraordinário', 'diario' => 'AP-EX', 'nome_diario' => 'Apuramento Extraordinário', 'documento' => 'AP-EX',
            'transferencias' => [['69', '9', '861'], ['79', '9', '862']],
            'agregadora' => '869', 'resultado' => '886', 'texto_resultado' => 'Resultado Extraordinário', 'texto_integracao' => 'Integração do Resultado Extraordinário'],
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoLancamentos $lancamentos,
        private readonly ServicoPlanoContas $planoContas,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * Anos com dados (lançamentos ou saldos históricos) e o seu estado.
     *
     * @return list<array{ano: int, encerrado: bool, linhas: int, apuramento: bool}>
     */
    public function exercicios(): array
    {
        $empresa = $this->contexto->obrigatorio();
        $porAno = DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->whereNotNull('data_documento')
            ->selectRaw("extract(year from data_documento)::int AS ano, COUNT(*) AS linhas, BOOL_OR(periodo_id = ? AND estorno_de_id IS NULL AND estornado_por_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM diarios_contabeis d_sal WHERE d_sal.id = lancamentos_contabeis.diario_id AND d_sal.codigo = 'SAL')) AS apuramento", [self::PERIODO_APURAMENTO])
            ->groupByRaw('1')->get()->keyBy('ano');
        $anos = $porAno->keys()->merge(DB::table('saldos_historicos')->where('empresa_id', $empresa)->whereNotNull('ano')->distinct()->pluck('ano'))
            ->merge($this->exercicios->anosEncerrados($empresa))->map(fn ($a) => (int) $a)->unique()->sort()->values();

        return $anos->map(fn (int $a) => ['ano' => $a, 'encerrado' => $this->exercicios->encerrado($empresa, $a),
            'linhas' => (int) ($porAno[$a]->linhas ?? 0), 'apuramento' => (bool) ($porAno[$a]->apuramento ?? false)])->all();
    }

    /** Estado do exercício: cadeado, lançamento activo de cada passo e resumo da classe 8 (loadClosedYearInfo, ui_closing.js:1613-1733). */
    public function estado(int $ano): array
    {
        $empresa = $this->contexto->obrigatorio();
        $passos = [];
        foreach (self::PASSOS as $n => $p) {
            $linhas = $this->apuramentoActivo($ano, $n);
            $integracao = $linhas->first(fn ($l) => $l->codigo_conta === self::CONTA_RESULTADO_LIQUIDO);
            $passos[] = ['passo' => $n, 'titulo' => $p['titulo'], 'diario' => $p['diario'], 'documento' => $this->documento($n, $ano),
                'numero_lan' => $linhas->first()?->numero_lan, 'linhas' => $linhas->count(),
                'resultado' => $integracao ? ($integracao->tipo_dc === 'D' ? $this->d($integracao->valor) : bcmul($this->d($integracao->valor), '-1', 2)) : null];
        }
        $descricoes = DB::table('plano_contas')->where('empresa_id', $empresa)->whereNull('eliminado_em')->pluck('descricao', 'codigo');
        $resumo = DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->whereBetween('data_documento', $this->limites($ano))
            ->where('codigo_conta', 'like', '8%')->selectRaw("codigo_conta, SUM(CASE WHEN tipo_dc = 'C' THEN valor ELSE -valor END) AS saldo")
            ->groupBy('codigo_conta')->orderBy('codigo_conta')->get()
            ->map(fn ($r) => ['codigo_conta' => $r->codigo_conta, 'descricao' => $descricoes[$r->codigo_conta] ?? null, 'saldo_credor' => $this->d($r->saldo)])->all();

        return ['ano' => $ano, 'encerrado' => $this->exercicios->encerrado($empresa, $ano), 'passos' => $passos, 'resumo_classe_8' => $resumo];
    }

    /** Linhas que o passo geraria agora (sem gravar), com as contas em falta ou totalizadoras. */
    public function previsualizar(int $ano, int $passo): array
    {
        $calculo = $this->calcular($ano, $passo);

        return $this->resumoCalculo($ano, $passo, $calculo) + ['contas_em_falta' => $calculo['em_falta'], 'contas_totalizadoras' => $calculo['totalizadoras'],
            'numero_lan_anterior' => $this->apuramentoActivo($ano, $passo)->first()?->numero_lan];
    }

    /**
     * Executa um passo: estorna o apuramento anterior do passo (se houver) e grava o novo lançamento no período 13.
     * `criarContas`: cria como contas de movimento as contas do apuramento que não existam no plano.
     */
    public function executarPasso(int $ano, int $passo, bool $criarContas = false): array
    {
        $empresa = $this->contexto->obrigatorio();
        $this->exercicios->exigirAberto($empresa, "{$ano}-12-31");

        return DB::transaction(function () use ($empresa, $ano, $passo, $criarContas) {
            $this->bloquearEmpresa($empresa);
            $calculo = $this->calcular($ano, $passo, true);
            if ($calculo['totalizadoras']) {
                throw new ErroNegocio('Há contas do apuramento que são totalizadoras: corrija o plano de contas antes de apurar.', 'CONTAS_APURAMENTO_TOTALIZADORAS', 422,
                    ['contas' => $calculo['totalizadoras']]);
            }
            $criadas = [];
            if ($calculo['em_falta']) {
                if (! $criarContas) {
                    throw new ErroNegocio('Há contas do apuramento que não existem no plano de contas: crie-as ou peça a criação automática.', 'CONTAS_APURAMENTO_EM_FALTA', 422,
                        ['contas' => $calculo['em_falta']]);
                }
                foreach ($calculo['em_falta'] as $codigo) {
                    $this->planoContas->criar(['codigo' => $codigo, 'descricao' => "Apuramento de resultados — conta {$codigo}", 'tipo' => PlanoConta::TIPO_MOVIMENTO]);
                    $criadas[] = $codigo;
                }
            }

            $estornados = $this->estornarApuramentos($this->apuramentoActivo($ano, $passo), "Novo apuramento do passo {$passo} de {$ano}");
            $numeroLan = null;
            if ($calculo['linhas']) {
                $p = self::PASSOS[$passo];
                $documento = $this->documento($passo, $ano);
                $criadasLinhas = $this->lancamentos->criar([
                    'diario_id' => $calculo['diario_id'], 'data_documento' => "{$ano}-12-31", 'numero_documento' => $documento, 'referencia' => $documento,
                    'descricao' => "{$p['titulo']} ({$ano})", 'tipo_origem' => self::TIPO_ORIGEM, 'linhas' => $calculo['linhas'],
                ]);
                LancamentoContabil::query()->whereIn('id', $criadasLinhas->pluck('id'))->update(['periodo_id' => self::PERIODO_APURAMENTO]);
                $numeroLan = $criadasLinhas->first()->numero_lan;
            }
            $this->auditoria->registar('Contabilidade', 'Apurou resultados', "Passo {$passo} de {$ano}: ".($numeroLan ?? 'sem saldos a apurar')
                .($estornados ? '; estornado o apuramento anterior '.implode(', ', $estornados) : ''), 'lancamentos_contabeis');

            return $this->resumoCalculo($ano, $passo, $calculo) + ['numero_lan' => $numeroLan, 'estornados' => $estornados, 'contas_criadas' => $criadas];
        });
    }

    /**
     * Validações finais (runClosingValidactions). Não grava nada.
     *
     * Divergências bloqueiam o encerramento; avisos (inventário — decisão do utilizador, 2026-10-01) só se reportam.
     *
     * @return array{ano: int, encerrado: bool, pode_encerrar: bool, verificacoes: list<array<string, mixed>>, divergencias: list<array<string, mixed>>, avisos: list<array<string, mixed>>}
     */
    public function validar(int $ano): array
    {
        $empresa = $this->contexto->obrigatorio();
        [$inicio, $fim] = $this->limites($ano);
        $v = [];

        // 0. Sequência: anos anteriores (≥ 2024) com lançamentos ou saldos históricos têm de estar encerrados
        $comDados = DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->whereNotNull('data_documento')
            ->selectRaw('DISTINCT extract(year from data_documento)::int AS ano')->pluck('ano')
            ->merge(DB::table('saldos_historicos')->where('empresa_id', $empresa)->whereNotNull('ano')->distinct()->pluck('ano'))
            ->map(fn ($a) => (int) $a)->unique()->filter(fn ($a) => $a >= self::ANO_INICIO_SEQUENCIA && $a < $ano)->sort()->values();
        $abertos = $comDados->reject(fn ($a) => $this->exercicios->encerrado($empresa, $a))->values()->all();
        $v[] = $this->verificacao('SEQUENCIA', ! $abertos, $abertos
            ? 'Há exercícios anteriores com dados ainda abertos ('.implode(', ', $abertos).'): encerre-os por ordem cronológica.'
            : 'Sequência de exercícios válida.', null, ['anos_abertos' => $abertos]);

        // 1. Débitos = Créditos e classes 6 e 7 a zero
        $t = DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->whereBetween('data_documento', [$inicio, $fim])
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE 0 END), 0) AS d, COALESCE(SUM(CASE WHEN tipo_dc = 'C' THEN valor ELSE 0 END), 0) AS c,
                COALESCE(SUM(CASE WHEN codigo_conta LIKE '6%' THEN CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END ELSE 0 END), 0) AS s6,
                COALESCE(SUM(CASE WHEN codigo_conta LIKE '7%' THEN CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END ELSE 0 END), 0) AS s7")->first();
        [$d, $c, $s6, $s7] = [$this->d($t->d), $this->d($t->c), $this->d($t->s6), $this->d($t->s7)];
        $dif = $this->abs(bcsub($d, $c, 2));
        $v[] = $this->verificacao('BALANCO', bccomp($dif, '0', 2) === 0, bccomp($dif, '0', 2) === 0
            ? 'O total de débitos é igual ao total de créditos.' : 'Os totais de débito e crédito do ano não conferem.', $dif, ['debito' => $d, 'credito' => $c]);
        $soma67 = bcadd($this->abs($s6), $this->abs($s7), 2);
        $v[] = $this->verificacao('APURAMENTO', bccomp($soma67, '0', 2) === 0, bccomp($soma67, '0', 2) === 0
            ? 'As classes 6 e 7 estão a zero (apuramento concluído).' : 'As classes 6 e 7 não estão a zero: execute os passos de apuramento.', $soma67,
            ['classe_6' => $s6, 'classe_7' => $s7]);

        // 2. Amortizações por integrar no ano
        $pendentes = app(ServicoAmortizacoes::class)->porIntegrarNoAno($ano);
        $v[] = $this->verificacao('IMOBILIZADO', ! $pendentes, $pendentes
            ? 'Existem '.count($pendentes).' bens com amortizações do ano por calcular ou por integrar.' : 'Todas as amortizações do ano estão integradas.', null,
            ['ativos' => $pendentes]);

        // 3. Armazém (stock ao custo médio) × saldo acumulado das contas 22 + 26 a 31-12
        // só artigos que movimentam stock (o legado filtrava is_inventory, ui_closing.js:1472), valorizados ao custo médio
        $stock = collect(app(ServicoArmazens::class)->stock());
        $inventariaveis = Produto::query()->whereIn('id', $stock->pluck('produto_id')->unique()->all())->where('movimenta_stock', true)->pluck('id')->flip();
        $armazem = $stock->filter(fn ($x) => isset($inventariaveis[$x['produto_id']]))->reduce(fn ($t, $x) => bcadd($t, (string) $x['valor'], 2), '0.00');
        $contab = $this->d(DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->where('data_documento', '<=', $fim)
            ->where(fn ($q) => $q->where('codigo_conta', 'like', '22%')->orWhere('codigo_conta', 'like', '26%'))
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS s")->value('s'));
        $difInv = $this->abs(bcsub($armazem, $contab, 2));
        $okInv = bccomp($difInv, self::TOLERANCIA_INVENTARIO, 2) <= 0;
        $v[] = $this->verificacao('INVENTARIO', $okInv, $okInv ? 'O saldo contabilístico das existências confere com o armazém.'
            : "Diferença entre o armazém ({$armazem}) e a contabilidade (contas 22+26: {$contab}): regularize o inventário (aviso, não impede o encerramento).",
            $difInv, ['armazem' => $armazem, 'contabilidade' => $contab], false);

        // 4. Balanço histórico (saldos iniciais) do ano
        $historico = DB::table('saldos_historicos')->where('empresa_id', $empresa)->where('ano', $ano)->where('tipo', 'DEMONSTRACAO_RESULTADOS')->pluck('valor', 'codigo')
            ->map(fn ($x) => $this->d($x))->all();
        if ($historico) {
            [$activo, $passivo] = $this->balancoHistorico($historico);
            $difH = $this->abs(bcsub($activo, $passivo, 2));
            $v[] = $this->verificacao('HISTORICO', bccomp($difH, '0', 2) === 0, bccomp($difH, '0', 2) === 0 ? 'O balanço histórico está equilibrado.'
                : 'O balanço histórico (saldos iniciais) está desequilibrado.', $difH, ['activo' => $activo, 'passivo_capital' => $passivo]);
        } else {
            $v[] = $this->verificacao('HISTORICO', true, 'Balanço histórico sem registos para este ano.');
        }

        $divergencias = array_values(array_filter($v, fn ($x) => ! $x['ok'] && $x['bloqueia']));
        $avisos = array_values(array_filter($v, fn ($x) => ! $x['ok'] && ! $x['bloqueia']));

        return ['ano' => $ano, 'encerrado' => $this->exercicios->encerrado($empresa, $ano), 'pode_encerrar' => ! $divergencias, 'verificacoes' => $v,
            'divergencias' => $divergencias, 'avisos' => $avisos];
    }

    /** Valida e tranca o exercício. Com divergências recusa e devolve o mapa de divergências em `erros.divergencias`. */
    public function encerrar(int $ano): array
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($empresa, $ano) {
            $this->bloquearEmpresa($empresa);
            if ($this->exercicios->encerrado($empresa, $ano)) {
                throw new ErroNegocio("O exercício de {$ano} já se encontra encerrado.", 'EXERCICIO_JA_ENCERRADO', 422, ['ano' => $ano]);
            }
            $resultado = $this->validar($ano);
            if (! $resultado['pode_encerrar']) {
                throw new ErroNegocio("Não é possível encerrar o exercício de {$ano}: existem divergências ou tarefas pendentes.", 'ENCERRAMENTO_COM_DIVERGENCIAS', 422,
                    ['divergencias' => $resultado['divergencias']]);
            }
            $this->exercicios->encerrar($empresa, $ano);
            $this->auditoria->registar('Contabilidade', 'Encerrou o exercício', "Exercício de {$ano} validado e encerrado.", 'configuracoes_sistema');

            return ['encerrado' => true] + $resultado;
        });
    }

    /** Reabre um exercício encerrado (abrirExercicio, ui_closing.js:1810-1836): só se nenhum ano seguinte estiver encerrado. */
    public function reabrir(int $ano, string $motivo): array
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($empresa, $ano, $motivo) {
            $this->bloquearEmpresa($empresa);
            if (! $this->exercicios->encerrado($empresa, $ano)) {
                throw new ErroNegocio("O exercício de {$ano} não está encerrado.", 'EXERCICIO_NAO_ENCERRADO', 422, ['ano' => $ano]);
            }
            $this->exigirSeguintesAbertos($empresa, $ano);
            $this->exercicios->reabrir($empresa, $ano);
            $this->auditoria->registar('Contabilidade', 'Reabriu o exercício', "Exercício de {$ano} reaberto. Motivo: {$motivo}", 'configuracoes_sistema');

            return ['ano' => $ano, 'encerrado' => false];
        });
    }

    /**
     * Cancela o apuramento (cancelarApuramento, ui_closing.js:1838-1873): reabre o exercício e estorna todos os lançamentos
     * activos do período 13 do ano. Só se nenhum ano seguinte estiver encerrado.
     */
    public function cancelarApuramento(int $ano, string $motivo): array
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($empresa, $ano, $motivo) {
            $this->bloquearEmpresa($empresa);
            $this->exigirSeguintesAbertos($empresa, $ano);
            $estavaEncerrado = $this->exercicios->encerrado($empresa, $ano);
            if ($estavaEncerrado) {
                $this->exercicios->reabrir($empresa, $ano);
            }
            $linhas = LancamentoContabil::query()->where('periodo_id', self::PERIODO_APURAMENTO)->whereBetween('data_documento', $this->limites($ano))
                ->whereNotIn('diario_id', $this->diariosSalarios())->whereNull('estorno_de_id')->whereNull('estornado_por_id')->orderBy('id')->get();
            $estornados = $this->estornarApuramentos($linhas, "Cancelamento do apuramento de {$ano}: {$motivo}");
            $this->auditoria->registar('Contabilidade', 'Cancelou o apuramento', "Apuramento de {$ano} cancelado ({$motivo}). "
                .($estavaEncerrado ? 'Exercício reaberto. ' : '').'Lançamentos estornados: '.($estornados ? implode(', ', $estornados) : 'nenhum'), 'lancamentos_contabeis');

            return ['ano' => $ano, 'encerrado' => false, 'reaberto' => $estavaEncerrado, 'estornados' => $estornados];
        });
    }

    /** Mapa de apuramento (gerarMapaApuramento, ui_closing.js:1735-1808): todas as linhas do período 13 do ano. */
    public function mapa(int $ano): array
    {
        $linhas = DB::table('lancamentos_contabeis as l')->leftJoin('diarios_contabeis as d', 'd.id', '=', 'l.diario_id')
            ->where('l.empresa_id', $this->contexto->obrigatorio())
            ->where('l.periodo_id', self::PERIODO_APURAMENTO)->whereBetween('l.data_documento', $this->limites($ano))->whereRaw("COALESCE(d.codigo, '') <> 'SAL'")
            ->orderBy('l.numero_documento')->orderByRaw("CASE WHEN l.tipo_dc = 'D' THEN 0 ELSE 1 END")->orderBy('l.codigo_conta')->orderBy('l.id')
            ->get(['l.id', 'l.data_documento', 'd.codigo as diario', 'l.numero_lan', 'l.numero_documento', 'l.codigo_conta', 'l.descricao', 'l.tipo_dc', 'l.valor',
                'l.estorno_de_id', 'l.estornado_por_id']);
        $debito = $credito = '0.00';
        $saida = [];
        foreach ($linhas as $l) {
            $valor = $this->d($l->valor);
            $l->tipo_dc === 'D' ? $debito = bcadd($debito, $valor, 2) : $credito = bcadd($credito, $valor, 2);
            $saida[] = ['id' => $l->id, 'data_documento' => $l->data_documento, 'diario' => $l->diario, 'numero_lan' => $l->numero_lan,
                'numero_documento' => $l->numero_documento, 'codigo_conta' => $l->codigo_conta, 'descricao' => $l->descricao,
                'debito' => $l->tipo_dc === 'D' ? $valor : null, 'credito' => $l->tipo_dc === 'C' ? $valor : null,
                'estornado' => $l->estornado_por_id !== null, 'estorno' => $l->estorno_de_id !== null];
        }

        return ['ano' => $ano, 'linhas' => $saida, 'total_debito' => $debito, 'total_credito' => $credito];
    }

    // ───────────── Cálculo ─────────────

    /**
     * @return array{diario_id: int, linhas: list<array<string, string>>, movimentos: list<array<string, string>>, resultado: string,
     *               em_falta: list<string>, totalizadoras: list<string>}
     */
    private function calcular(int $ano, int $passo, bool $criarDiario = false): array
    {
        $p = self::PASSOS[$passo] ?? throw new ErroNegocio("Passo de apuramento inválido: {$passo}.", 'PASSO_INVALIDO', 422);
        $empresa = $this->contexto->obrigatorio();
        // a pré-visualização não cria o diário (nenhuma leitura altera dados, ADR-015)
        $diarioId = $criarDiario ? $this->localizador->diario($p['diario'], $p['nome_diario'])->id : $this->diarioId($p['diario']);
        $prefixos = array_map(fn ($t) => $t[0], $p['transferencias']);
        $saldos = DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->whereBetween('data_documento', $this->limites($ano))
            ->when($diarioId, fn ($q) => $q->where(fn ($x) => $x->whereNull('diario_id')->orWhere('diario_id', '<>', $diarioId)))
            ->where('codigo_conta', 'not like', '9%')->whereRaw('left(codigo_conta, 2) IN ('.implode(',', array_fill(0, count($prefixos), '?')).')', $prefixos)
            ->selectRaw("codigo_conta, SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END) AS saldo")->groupBy('codigo_conta')
            ->havingRaw("SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END) <> 0")->orderBy('codigo_conta')
            ->pluck('saldo', 'codigo_conta')->map(fn ($s) => $this->d($s))->all();

        $linhas = $movimentos = [];
        $par = function (string $origem, string $destino, string $saldo, string $textoOrigem, string $textoDestino) use (&$linhas, &$movimentos) {
            $valor = $this->abs($saldo);
            $devedor = bccomp($saldo, '0', 2) > 0;
            $linhas[] = ['codigo_conta' => $origem, 'tipo_dc' => $devedor ? 'C' : 'D', 'valor' => $valor, 'descricao' => $textoOrigem];
            $linhas[] = ['codigo_conta' => $destino, 'tipo_dc' => $devedor ? 'D' : 'C', 'valor' => $valor, 'descricao' => $textoDestino];
            $movimentos[] = ['descricao' => $textoOrigem, 'conta_debito' => $devedor ? $destino : $origem, 'conta_credito' => $devedor ? $origem : $destino, 'valor' => $valor];
        };

        $totais = [];
        foreach ($p['transferencias'] as [$prefixo, $sufixo, $alvo]) {
            $agrupadora = $prefixo.$sufixo;
            $total = '0.00';
            foreach ($saldos as $conta => $saldo) {
                $conta = (string) $conta;
                if (str_starts_with($conta, $prefixo) && ! str_starts_with($conta, $agrupadora)) {
                    $par($conta, $agrupadora, $saldo, "Apuramento para a conta {$agrupadora}", "Transf. de saldos da conta {$conta}");
                    $total = bcadd($total, $saldo, 2);
                }
            }
            if (bccomp($total, '0', 2) !== 0) {
                $par($agrupadora, $alvo, $total, "Apuramento para a conta {$alvo}", "Transf. de saldos da conta {$agrupadora}");
                $totais[$alvo] = $total;
            }
        }
        $soma = '0.00';
        foreach ($totais as $alvo => $total) {
            $par((string) $alvo, $p['agregadora'], $total, "Apuramento para a conta {$p['agregadora']}", "Transf. de saldos da conta {$alvo}");
            $soma = bcadd($soma, $total, 2);
        }
        if (bccomp($soma, '0', 2) !== 0) {
            $par($p['agregadora'], $p['resultado'], $soma, "Apuramento para a conta {$p['resultado']}", $p['texto_resultado']);
            $par($p['resultado'], self::CONTA_RESULTADO_LIQUIDO, $soma, 'Transferência para a conta '.self::CONTA_RESULTADO_LIQUIDO, $p['texto_integracao']);
        }

        $plano = $this->planoContas->todas();
        $contas = array_values(array_unique(array_column($linhas, 'codigo_conta')));
        sort($contas, SORT_STRING);

        return ['diario_id' => $diarioId, 'linhas' => $linhas, 'movimentos' => $movimentos, 'resultado' => $soma,
            'em_falta' => array_values(array_filter($contas, fn ($c) => ! isset($plano[$c]))),
            'totalizadoras' => array_values(array_filter($contas, fn ($c) => isset($plano[$c]) && $plano[$c]['tipo'] === PlanoConta::TIPO_TOTALIZADORA))];
    }

    private function resumoCalculo(int $ano, int $passo, array $calculo): array
    {
        $p = self::PASSOS[$passo];

        return ['ano' => $ano, 'passo' => $passo, 'titulo' => $p['titulo'], 'diario' => $p['diario'], 'documento' => $this->documento($passo, $ano),
            'data_documento' => "{$ano}-12-31", 'periodo' => self::PERIODO_APURAMENTO, 'linhas' => count($calculo['linhas']),
            'movimentos' => $calculo['movimentos'], 'conta_resultado' => $p['resultado'], 'resultado' => $calculo['resultado']];
    }

    /** Linhas activas (não estornadas) do apuramento do passo no ano. */
    private function apuramentoActivo(int $ano, int $passo): Collection
    {
        $diario = $this->diarioId(self::PASSOS[$passo]['diario']);
        if (! $diario) {
            return collect();
        }

        return LancamentoContabil::query()->where('diario_id', $diario)->whereBetween('data_documento', $this->limites($ano))
            ->whereNull('estorno_de_id')->whereNull('estornado_por_id')->orderBy('id')->get();
    }

    /**
     * Estorna os lançamentos a que as linhas pertencem (um estorno por lançamento) e marca o estorno com o período 13,
     * para que os mapas que escondem o apuramento escondam também o seu estorno.
     *
     * @return list<string> números dos lançamentos estornados
     */
    private function estornarApuramentos(Collection $linhas, string $motivo): array
    {
        $feitos = [];
        foreach ($linhas->unique(fn ($l) => $l->diario_id.'|'.$l->chave()) as $linha) {
            $estornos = $this->lancamentos->estornar($linha, $motivo);
            LancamentoContabil::query()->whereIn('id', $estornos->pluck('id'))->update(['periodo_id' => self::PERIODO_APURAMENTO]);
            $feitos[] = (string) $linha->chave();
        }

        return $feitos;
    }

    /** @param array<string, string> $v saldos históricos por código (ui_closing.js:1499-1516) @return array{0: string, 1: string} [activo, passivo + capital] */
    private function balancoHistorico(array $v): array
    {
        $s = function (array $codigos) use ($v) {
            $t = '0.00';
            foreach ($codigos as $c) {
                $t = bcadd($t, $v[$c] ?? '0.00', 2);
            }

            return $t;
        };
        $proveitos = $s(['22', '23', '24', '25', '26']);
        $custos = $s(['27', '28', '29', '30']);
        $financeiros = $s(['31', '32', '33', '34']);
        $v['res_liq'] = bcsub(bcadd(bcsub($proveitos, $custos, 2), $financeiros, 2), $v['35'] ?? '0.00', 2);
        $activo = bcadd($s(['4', '5', '7']), $s(['8', '9', '10', '11']), 2);
        $passivo = bcadd(bcadd($s(['17', '18']), $s(['19', '15', '21']), 2), bcadd($s(['12', '13', '14']), $v['res_liq'], 2), 2);

        return [$activo, $passivo];
    }

    private function exigirSeguintesAbertos(int $empresa, int $ano): void
    {
        $seguintes = array_values(array_filter($this->exercicios->anosEncerrados($empresa), fn ($a) => $a > $ano));
        if ($seguintes) {
            throw new ErroNegocio('Existem exercícios seguintes encerrados (ex.: '.max($seguintes).'): reabra-os primeiro, do mais recente para o mais antigo.',
                'EXERCICIOS_SEGUINTES_ENCERRADOS', 422, ['anos' => $seguintes]);
        }
    }

    private function verificacao(string $tipo, bool $ok, string $descricao, ?string $diferenca = null, array $detalhes = [], bool $bloqueia = true): array
    {
        return ['tipo' => $tipo, 'ok' => $ok, 'bloqueia' => $bloqueia, 'descricao' => $descricao, 'diferenca' => $diferenca, 'detalhes' => $detalhes];
    }

    /**
     * No legado o period_id das linhas do diário SAL é o id do processamento salarial (fluxo_processos.js:100):
     * um processamento com id 13/14 não é apuramento. As consultas do apuramento excluem sempre o diário SAL.
     *
     * @return list<int>
     */
    private function diariosSalarios(): array
    {
        return DB::table('diarios_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->where('codigo', 'SAL')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function diarioId(string $codigo): ?int
    {
        $id = DB::table('diarios_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->where('codigo', $codigo)->whereNull('eliminado_em')->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function bloquearEmpresa(int $empresa): void
    {
        DB::table('empresas')->where('id', $empresa)->lockForUpdate()->first(['id']);
    }

    private function documento(int $passo, int $ano): string
    {
        return self::PASSOS[$passo]['documento']."-{$ano}";
    }

    /** @return array{0: string, 1: string} */
    private function limites(int $ano): array
    {
        return ["{$ano}-01-01", "{$ano}-12-31"];
    }

    /** Valor monetário em decimal exacto (as somas do PostgreSQL já vêm com 2 casas). */
    private function d(mixed $v): string
    {
        return bcadd((string) ($v ?? '0'), '0', 2);
    }

    private function abs(string $v): string
    {
        return ltrim($v, '-');
    }
}
