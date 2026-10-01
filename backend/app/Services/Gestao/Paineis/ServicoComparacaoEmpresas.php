<?php

namespace App\Services\Gestao\Paineis;

use App\Exceptions\ErroNegocio;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Comparação de empresas pelos números da contabilidade (proveitos, custos, resultado, margem, variação face ao mesmo período do
 * ano anterior, disponibilidades, clientes e fornecedores), em duas formas:
 *
 *   - grupo (holding, CONSTRUTORES.grupo, ui_painel_modulos.js:686-844): lê os lançamentos da holding (última consolidação);
 *     cada linha pertence à empresa de origem (agregação), às eliminações intragrupo, à conversão cambial ou foi lançada na
 *     própria holding (chave de origem do legado, consolChaveOrigem). Os valores de cada empresa são antes das eliminações; o
 *     consolidado inclui eliminações e conversão;
 *   - comparação livre (nova): várias empresas a que o utilizador tem acesso, cada uma com os seus próprios lançamentos.
 *
 * Segurança: só entram empresas a que o utilizador tem acesso (ServicoEmpresas::podeAceder). No grupo, as linhas de membros a
 * que não tem acesso juntam-se numa única origem "Outras empresas (sem acesso)" — o total consolidado não muda e nenhuma
 * empresa sem acesso é identificada (o legado mostrava todas).
 * Regras: classe 9 excluída (como o legado); apuramento (períodos 13/14) excluído dos proveitos e custos (ver ConsultasPaineis).
 * Tudo agregado no PostgreSQL por (origem, mês).
 */
final class ServicoComparacaoEmpresas
{
    private const AJUSTES = ['ELIM' => 'Eliminações intragrupo', 'CONV' => 'Conversão cambial', 'OUTROS' => 'Lançado na holding', 'SEM_ACESSO' => 'Outras empresas (sem acesso)'];

    public function __construct(private readonly ContextoEmpresa $contexto, private readonly ServicoEmpresas $empresas) {}

    /** @return array<string, mixed> */
    public function grupo(PeriodoPainel $p, array $f): array
    {
        $holding = $this->contexto->obrigatorio();
        $grupo = DB::table('grupos_consolidacao')->where('empresa_holding_id', $holding)->orderBy('id')->first();
        $membros = $grupo ? DB::table('membros_consolidacao as m')->join('empresas as e', 'e.id', '=', 'm.empresa_membro_id')
            ->where('m.grupo_consolidacao_id', $grupo->id)->orderBy('e.nome')->get(['e.id', 'e.nome']) : collect();
        $ultima = $grupo ? DB::table('execucoes_consolidacao')->where('grupo_consolidacao_id', $grupo->id)->where('estado', '<>', 'EM_CURSO')
            ->orderByDesc('executado_em')->orderByDesc('id')->first() : null;
        $acessiveis = array_flip($this->empresas->idsAcessiveis($this->utilizador()));
        $semAcesso = $membros->filter(fn ($m) => ! isset($acessiveis[(int) $m->id]))->pluck('id')->map(fn ($x) => (int) $x)->all();
        $origens = $membros->filter(fn ($m) => isset($acessiveis[(int) $m->id]))->map(fn ($m) => self::origem((string) $m->id, $m->nome))->values()->all();

        $semAcessoSql = $semAcesso ? ' WHEN l.empresa_origem_id IN ('.implode(',', array_map('intval', $semAcesso)).") THEN 'SEM_ACESSO'" : '';
        $chave = "CASE WHEN l.tipo_consolidacao = 'ELIMINACAO' THEN 'ELIM' WHEN l.tipo_consolidacao = 'CONVERSAO' THEN 'CONV'{$semAcessoSql}
            WHEN l.empresa_origem_id IS NOT NULL THEN l.empresa_origem_id::text ELSE 'OUTROS' END";
        [$fs, $fp] = ConsultasPaineis::filtroDimensoes('l', $f);
        $stats = $this->estatisticas($chave, "l.empresa_id = ?{$fs}", array_merge([$holding], $fp), $p);
        $conhecidas = array_column($origens, 'chave');
        foreach (array_map('strval', array_keys($stats)) as $k) {
            if (in_array($k, $conhecidas, true) || isset(self::AJUSTES[$k])) {
                continue;
            }
            if (isset($acessiveis[(int) $k])) {   // origem que já não é membro do grupo
                $origens[] = self::origem($k, DB::table('empresas')->where('id', (int) $k)->value('nome') ?? "Empresa {$k}");
            } else {
                $stats['SEM_ACESSO'] = self::juntar($stats['SEM_ACESSO'] ?? null, $stats[$k]);
                unset($stats[$k]);
            }
        }
        $ajustes = array_values(array_map(fn ($k) => ['chave' => $k, 'nome' => self::AJUSTES[$k], 'sigla' => self::AJUSTES[$k], 'empresa_id' => null],
            array_filter(array_keys(self::AJUSTES), fn ($k) => isset($stats[$k]))));

        if (! $grupo) {
            $aviso = 'Esta empresa está marcada como holding mas não tem grupo de consolidação definido.';
        } elseif (! $ultima) {
            $aviso = 'Esta holding ainda não foi consolidada. Execute a consolidação no menu Consolidação para ver a comparação das empresas.';
        } else {
            $fim = (string) $ultima->data_fim;
            $aviso = count($origens).' empresas · dados da última consolidação até '.date('d/m/Y', strtotime($fim)).($ultima->codigo_moeda ? " em {$ultima->codigo_moeda}" : '').'.'
                .' Os valores de cada empresa são antes das eliminações; o consolidado inclui eliminações e conversão.'
                .($fim !== '' && substr($fim, 0, 7) < $p->chaveMes ? ' Atenção: a consolidação não cobre todo o período escolhido.' : '')
                .($semAcesso ? ' '.count($semAcesso).' empresa(s) do grupo sem acesso aparecem agregadas.' : '');
        }
        $r = $this->montar($origens, $ajustes, $stats, $p, true);

        return ['aviso' => $aviso, 'grupo' => $grupo ? ['id' => $grupo->id, 'nome' => $grupo->nome] : null,
            'ultima_consolidacao' => $ultima ? ['id' => $ultima->id, 'data_fim' => $ultima->data_fim, 'executado_em' => $ultima->executado_em, 'moeda' => $ultima->codigo_moeda] : null] + $r
            + ['atalhos' => [['rotulo' => 'Consolidação', 'vista' => 'consolidacao'], ['rotulo' => 'Mapas Consolidados', 'vista' => 'relatorios_contabeis'], ['rotulo' => 'Relatório e Contas', 'vista' => 'relatorio_contas']]];
    }

    /**
     * Comparação livre entre empresas (cada uma pelos seus lançamentos).
     *
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    public function comparar(array $ids, PeriodoPainel $p, array $f): array
    {
        $u = $this->utilizador();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $negadas = array_values(array_filter($ids, fn ($id) => ! $this->empresas->podeAceder($u, $id)));
        if ($negadas) {
            throw new ErroNegocio('Não tem acesso a todas as empresas indicadas.', 'EMPRESA_SEM_ACESSO', 403, ['empresas' => $negadas]);
        }
        $empresas = DB::table('empresas')->whereIn('id', $ids)->orderBy('nome')->get(['id', 'nome', 'e_consolidacao']);
        $origens = $empresas->map(fn ($e) => self::origem((string) $e->id, $e->nome) + ['holding' => (bool) $e->e_consolidacao])->values()->all();
        [$fs, $fp] = ConsultasPaineis::filtroDimensoes('l', $f);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stats = $this->estatisticas('l.empresa_id::text', "l.empresa_id IN ({$marcas}){$fs}", array_merge($ids, $fp), $p);
        $r = $this->montar($origens, [], $stats, $p, false);

        return ['aviso' => in_array(true, array_column($origens, 'holding'), true) ? 'Inclui holdings: os seus valores são consolidados e repetem os das empresas do grupo.' : null] + $r;
    }

    /**
     * Somas por origem: proveitos/custos do ano e do mesmo período do ano anterior, séries mensais da janela e saldos 43/45, 31, 32.
     *
     * @return array<string, array<string, mixed>>
     */
    private function estatisticas(string $chave, string $where, array $params, PeriodoPainel $p): array
    {
        $ant = $p->anoAnterior();
        $inicio = min($p->inicioJanela, $ant->inicioAno);
        $linhas = DB::select("SELECT {$chave} AS chave, to_char(l.data_documento, 'YYYY-MM') AS mes,
                SUM(CASE WHEN l.codigo_conta LIKE '6%' THEN CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE -l.valor END ELSE 0 END) AS prov,
                SUM(CASE WHEN l.codigo_conta LIKE '7%' THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) AS custo
            FROM lancamentos_contabeis l WHERE {$where} AND l.data_documento BETWEEN ? AND ? AND (l.codigo_conta LIKE '6%' OR l.codigo_conta LIKE '7%')
              AND NOT (COALESCE(l.periodo_id, 0) IN (13, 14) AND NOT EXISTS (SELECT 1 FROM diarios_contabeis d_sal WHERE d_sal.id = l.diario_id AND d_sal.codigo = 'SAL')) GROUP BY 1, 2", array_merge($params, [$inicio, $p->fimMes]));
        $saldos = DB::select("SELECT {$chave} AS chave,
                SUM(CASE WHEN l.codigo_conta LIKE '43%' OR l.codigo_conta LIKE '45%' THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) AS disp,
                SUM(CASE WHEN l.codigo_conta LIKE '31%' THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) AS cli,
                SUM(CASE WHEN l.codigo_conta LIKE '32%' THEN CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE -l.valor END ELSE 0 END) AS forn
            FROM lancamentos_contabeis l WHERE {$where} AND l.data_documento <= ?
              AND (l.codigo_conta LIKE '43%' OR l.codigo_conta LIKE '45%' OR l.codigo_conta LIKE '31%' OR l.codigo_conta LIKE '32%') GROUP BY 1",
            array_merge($params, [$p->fimMes]));
        $vazio = fn () => ['prov' => '0.00', 'custo' => '0.00', 'prov_ant' => '0.00', 'res_ant' => '0.00', 'disp' => '0.00', 'cli' => '0.00', 'forn' => '0.00',
            'prov_mes' => [], 'res_mes' => []];
        $s = [];
        $anoIni = substr($p->inicioAno, 0, 7);
        $antIni = substr($ant->inicioAno, 0, 7);
        foreach ($linhas as $l) {
            $x = $s[$l->chave] ?? $vazio();
            $prov = Indicadores::dinheiro($l->prov);
            $res = bcsub($prov, Indicadores::dinheiro($l->custo), 2);
            if ($l->mes >= $anoIni && $l->mes <= $p->chaveMes) {
                $x['prov'] = bcadd($x['prov'], $prov, 2);
                $x['custo'] = bcadd($x['custo'], Indicadores::dinheiro($l->custo), 2);
            }
            if ($l->mes >= $antIni && $l->mes <= $ant->chaveMes) {
                $x['prov_ant'] = bcadd($x['prov_ant'], $prov, 2);
                $x['res_ant'] = bcadd($x['res_ant'], $res, 2);
            }
            if ($l->mes >= substr($p->inicioJanela, 0, 7)) {
                $x['prov_mes'][$l->mes] = $prov;
                $x['res_mes'][$l->mes] = $res;
            }
            $s[$l->chave] = $x;
        }
        foreach ($saldos as $l) {
            $s[$l->chave] = ['disp' => Indicadores::dinheiro($l->disp), 'cli' => Indicadores::dinheiro($l->cli), 'forn' => Indicadores::dinheiro($l->forn)] + ($s[$l->chave] ?? $vazio());
        }

        return $s;
    }

    /**
     * @param  list<array<string, mixed>>  $empresas  origens que são empresas
     * @param  list<array<string, mixed>>  $ajustes  eliminações, conversão, lançado na holding, sem acesso
     * @return array<string, mixed>
     */
    private function montar(array $empresas, array $ajustes, array $stats, PeriodoPainel $p, bool $holding): array
    {
        $zero = ['prov' => '0.00', 'custo' => '0.00', 'prov_ant' => '0.00', 'res_ant' => '0.00', 'disp' => '0.00', 'cli' => '0.00', 'forn' => '0.00', 'prov_mes' => [], 'res_mes' => []];
        $st = fn (array $o) => $stats[$o['chave']] ?? $zero;
        $somar = fn (array $lista) => array_reduce($lista, fn ($t, $o) => self::juntar($t, $st($o)), $zero);
        $agregado = $somar($empresas);
        $consolidado = $somar(array_merge($empresas, $ajustes));
        $res = fn (array $x) => bcsub($x['prov'], $x['custo'], 2);
        $linha = fn (array $o, array $x, string $tipo) => ['chave' => $o['chave'], 'empresa_id' => $o['empresa_id'] ?? null, 'nome' => $o['nome'], 'sigla' => $o['sigla'], 'tipo' => $tipo,
            'proveitos' => $x['prov'], 'peso' => Indicadores::pct($x['prov'], $agregado['prov']), 'variacao_proveitos' => Indicadores::variacao($x['prov'], $x['prov_ant']),
            'custos' => $x['custo'], 'resultado' => $res($x), 'margem' => Indicadores::pct($res($x), $x['prov']), 'disponibilidades' => $x['disp'], 'clientes' => $x['cli'],
            'fornecedores' => $x['forn'], 'proveitos_ano_anterior' => $x['prov_ant'], 'resultado_ano_anterior' => $x['res_ant']];
        $porProv = $empresas;
        usort($porProv, fn ($a, $b) => (float) $st($b)['prov'] <=> (float) $st($a)['prov']);
        $porRes = $empresas;
        usort($porRes, fn ($a, $b) => (float) $res($st($b)) <=> (float) $res($st($a)));
        $prejuizo = array_values(array_filter($empresas, fn ($o) => bccomp($res($st($o)), '0', 2) < 0));
        $siglas = array_column($empresas, 'sigla');
        $periodoTxt = "Janeiro a {$p->nomeMes()} {$p->ano}";
        $rc = $res($consolidado);
        $elim = $stats['ELIM'] ?? null;
        $quadro = array_merge(array_map(fn ($o) => $linha($o, $st($o), 'EMPRESA'), $porProv), $ajustes ? [$linha(['chave' => 'SOMA', 'nome' => 'Soma das empresas', 'sigla' => 'Soma'], $agregado, 'SOMA')] : [],
            array_map(fn ($o) => $linha($o, $st($o), 'AJUSTE'), $ajustes), [$linha(['chave' => 'TOTAL', 'nome' => $holding ? 'Total consolidado' : 'Total', 'sigla' => 'Total'], $consolidado, 'TOTAL')]);
        $positivas = array_values(array_filter($empresas, fn ($o) => bccomp($st($o)['prov'], '0', 2) > 0));

        $kpis = [
            Indicadores::kpi('proveitos', $holding ? 'Proveitos Consolidados' : 'Proveitos', $consolidado['prov'], 'kz', $periodoTxt.($holding ? ' · após eliminações' : '')),
            Indicadores::kpi('custos', $holding ? 'Custos Consolidados' : 'Custos', $consolidado['custo'], 'kz', $periodoTxt),
            Indicadores::kpi('resultado', $holding ? 'Resultado Consolidado' : 'Resultado', $rc, 'kz', null, null, ['variacao' => Indicadores::variacao($rc, $consolidado['res_ant'])]),
        ];
        if ($holding) {
            $kpis[] = Indicadores::kpi('eliminacoes', 'Eliminações Intragrupo', $elim ? ltrim($elim['prov'], '-') : '0.00', 'kz', 'Proveitos anulados entre empresas');
        }
        $kpis[] = Indicadores::kpi('maior_contributo', 'Maior Contributo nos Proveitos', $porProv[0]['sigla'] ?? null, 'texto',
            $porProv ? $st($porProv[0])['prov'].' Kz · '.(Indicadores::pct($st($porProv[0])['prov'], $agregado['prov']) ?? 0).' %' : null, null, ['empresa_id' => $porProv[0]['empresa_id'] ?? null]);
        $kpis[] = Indicadores::kpi('maior_resultado', 'Maior Resultado', $porRes[0]['sigla'] ?? null, 'texto', $porRes ? $res($st($porRes[0])).' Kz' : null, null, ['empresa_id' => $porRes[0]['empresa_id'] ?? null]);
        $kpis[] = Indicadores::kpi('empresas_prejuizo', 'Empresas com Prejuízo', count($prejuizo), 'num', $prejuizo ? implode(', ', array_column($prejuizo, 'sigla')) : 'Nenhuma');
        $kpis[] = Indicadores::kpi('disponibilidades', $holding ? 'Disponibilidades do Grupo' : 'Disponibilidades', $consolidado['disp'], 'kz', "Contas 43 e 45 em {$p->nomeMes()} {$p->ano}");
        $serieEmp = fn (string $k) => array_map(fn ($o) => Indicadores::serie($o['chave'], $o['sigla'], $p->serie($st($o)[$k])), $empresas);

        return [
            'empresas' => $empresas,
            'kpis' => $kpis,
            'graficos' => $empresas ? [
                Indicadores::grafico('por_empresa', "Proveitos, Custos e Resultado por Empresa ({$p->ano})", 'barras', $siglas, [
                    Indicadores::serie('proveitos', 'Proveitos', array_map(fn ($o) => $st($o)['prov'], $empresas)),
                    Indicadores::serie('custos', 'Custos', array_map(fn ($o) => $st($o)['custo'], $empresas)),
                    Indicadores::serie('resultado', 'Resultado', array_map(fn ($o) => $res($st($o)), $empresas))]),
                Indicadores::grafico('peso_proveitos', 'Peso de Cada Empresa nos Proveitos', 'circular', array_column($positivas, 'sigla'),
                    [Indicadores::serie('proveitos', 'Proveitos', array_map(fn ($o) => $st($o)['prov'], $positivas))]),
                Indicadores::grafico('proveitos_mensais', 'Proveitos Mensais por Empresa (12 meses)', 'barras', $p->rotulos(), $serieEmp('prov_mes'), true, ['empilhado' => true]),
                Indicadores::grafico('resultado_mensal', 'Resultado Mensal por Empresa (12 meses)', 'linhas', $p->rotulos(), $serieEmp('res_mes')),
                Indicadores::grafico('proveitos_homologos', "Proveitos: {$p->ano} vs ".($p->ano - 1)." (Janeiro a {$p->nomeMes()})", 'barras', $siglas, [
                    Indicadores::serie('ano_anterior', (string) ($p->ano - 1), array_map(fn ($o) => $st($o)['prov_ant'], $empresas)),
                    Indicadores::serie('ano', (string) $p->ano, array_map(fn ($o) => $st($o)['prov'], $empresas))]),
                Indicadores::grafico('saldos', "Disponibilidades, Clientes e Fornecedores ({$p->nomeMes()} {$p->ano})", 'barras', $siglas, [
                    Indicadores::serie('disponibilidades', 'Disponibilidades', array_map(fn ($o) => $st($o)['disp'], $empresas)),
                    Indicadores::serie('clientes', 'Clientes a receber', array_map(fn ($o) => $st($o)['cli'], $empresas)),
                    Indicadores::serie('fornecedores', 'Fornecedores a pagar', array_map(fn ($o) => $st($o)['forn'], $empresas))]),
            ] : [],
            'tabelas' => [
                Indicadores::tabela('quadro_comparativo', "Quadro Comparativo das Empresas ({$periodoTxt})", [['nome', 'Empresa'], ['proveitos', 'Proveitos', 'kz'], ['peso', 'Peso', 'pct'],
                    ['variacao_proveitos', 'Var. vs '.($p->ano - 1), 'pct'], ['custos', 'Custos', 'kz'], ['resultado', 'Resultado', 'kz'], ['margem', 'Margem', 'pct'],
                    ['disponibilidades', 'Disponib.', 'kz'], ['clientes', 'Clientes', 'kz'], ['fornecedores', 'Fornecedores', 'kz']], $quadro),
                Indicadores::tabela('resultado_mensal', 'Resultado Mensal por Empresa', array_merge([['nome', 'Empresa']], array_map(fn ($m) => [$m['chave'], $m['rotulo'], 'kz'], $p->meses), [['total', 'Total', 'kz']]),
                    array_map(function ($o) use ($st, $p) {
                        $serie = $p->serie($st($o)['res_mes']);

                        return ['chave' => $o['chave'], 'empresa_id' => $o['empresa_id'] ?? null, 'nome' => $o['nome']] + array_combine($p->chaves(), $serie)
                            + ['total' => array_reduce($serie, fn ($s, $v) => bcadd($s, $v, 2), '0.00')];
                    }, array_merge($empresas, $ajustes))),
            ],
        ];
    }

    /** Soma as estatísticas de duas origens. */
    private static function juntar(?array $a, array $b): array
    {
        if ($a === null) {
            return $b;
        }
        foreach (['prov', 'custo', 'prov_ant', 'res_ant', 'disp', 'cli', 'forn'] as $k) {
            $a[$k] = bcadd($a[$k], $b[$k], 2);
        }
        $a['prov_mes'] = ConsultasPaineis::somar($a['prov_mes'], $b['prov_mes']);
        $a['res_mes'] = ConsultasPaineis::somar($a['res_mes'], $b['res_mes']);

        return $a;
    }

    /** @return array{chave: string, empresa_id: ?int, nome: string, sigla: string} */
    private static function origem(string $chave, string $nome): array
    {
        $sigla = trim((string) preg_split('/\s+-\s+|,/u', $nome)[0]);

        return ['chave' => $chave, 'empresa_id' => ctype_digit($chave) ? (int) $chave : null, 'nome' => $nome, 'sigla' => mb_strlen($sigla) > 18 ? mb_substr($sigla, 0, 18).'…' : $sigla];
    }

    private function utilizador(): Utilizador
    {
        /** @var Utilizador */
        return Auth::user();
    }
}
