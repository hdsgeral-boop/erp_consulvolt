<?php

namespace App\Services\Gestao\Paineis\Cubo;

use App\Exceptions\ErroNegocio;
use App\Models\Utilizador;
use App\Services\Projetos\ServicoAnaliticoProjetos;
use App\Services\Sistema\ServicoEmpresas;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Análise Dinâmica (cubo, ui_cubo.js:350-570) e motor do BI contabilístico: tabela dinâmica calculada no PostgreSQL.
 *
 * O legado carregava até 150 000 linhas para o navegador e fazia o pivot com o PivotTable.js. Aqui o servidor agrega:
 *   SELECT dimensões, agregações FROM (fonte filtrada) GROUP BY GROUPING SETS ((linhas, colunas), (linhas), (colunas), ())
 * e devolve as células, os totais por linha e por coluna e o total geral, prontos a desenhar. As dimensões, as medidas e as
 * agregações vêm da lista branca (CatalogoCubo); os valores dos filtros vão por parâmetros.
 *
 * Limites: até 4 dimensões nas linhas e 3 nas colunas, 6 medidas, 20 000 células e 500 colunas — acima disso o pedido é
 * recusado (CUBO_DEMASIADAS_CELULAS) e deve reduzir-se o período ou as dimensões (o legado recusava acima de 150 000 linhas).
 *
 * Holding (ui_cubo.js:47-55, 105-118 e 493-503): a Contabilidade lê os lançamentos consolidados da holding, com as dimensões
 * Empresa (origem), Tipo de linha e Empresa da eliminação; os restantes conjuntos lêem cada empresa do grupo, com a dimensão
 * Empresa. Só entram as empresas do grupo a que o utilizador tem acesso; as linhas de origem sem acesso aparecem como
 * "Outras empresas (sem acesso)".
 * As visões guardadas continuam no navegador (localStorage), como no legado.
 */
final class ServicoCubo
{
    public const MAX_LINHAS = 4;

    public const MAX_COLUNAS = 3;

    public const MAX_MEDIDAS = 6;

    public const MAX_CELULAS = 20000;

    public const MAX_CHAVES_COLUNA = 500;

    private const SEM_ACESSO = 'Outras empresas (sem acesso)';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoAnaliticoProjetos $projetos,
    ) {}

    /** @return list<array<string, mixed>> conjuntos a que o utilizador tem acesso, com dimensões e medidas */
    public function conjuntos(): array
    {
        $saida = [];
        foreach (array_keys(CatalogoCubo::conjuntos()) as $id) {
            $def = $this->definicao($id, false);
            if ($def !== null) {
                $saida[] = $this->descrever($id, $def);
            }
        }

        return $saida;
    }

    /** @return array<string, mixed> */
    public function descrever(string $id, array $def): array
    {
        return ['id' => $id, 'nome' => $def['nome'], 'icone' => $def['icone'] ?? null,
            'dimensoes' => array_map(fn ($k, $d) => ['id' => $k, 'rotulo' => $d[0]], array_keys($def['dimensoes']), $def['dimensoes']),
            'medidas' => array_map(fn ($k, $m) => ['id' => $k, 'rotulo' => $m[0], 'formato' => $m[2]], array_keys($def['medidas']), $def['medidas']),
            'agregacoes' => array_keys(CatalogoCubo::AGREGACOES), 'padrao' => $def['padrao'], 'opcoes' => $def['opcoes'] ?? [],
            'holding' => $this->holding()];
    }

    /**
     * Definição de um conjunto para o utilizador e a empresa activa (null se não tiver acesso).
     *
     * @param  bool  $exigir  lança 403 em vez de devolver null
     * @return array<string, mixed>|null
     */
    public function definicao(string $id, bool $exigir = true): ?array
    {
        $def = $id === 'bi' ? CatalogoCubo::bi() : (CatalogoCubo::conjuntos()[$id] ?? null);
        if ($def === null) {
            throw new ErroNegocio('Conjunto de dados desconhecido.', 'CUBO_CONJUNTO_INVALIDO', 422);
        }
        if (! $this->podeVer($def['vistas'])) {
            if ($exigir) {
                throw new ErroNegocio('Sem acesso a este conjunto de dados.', 'SEM_PERMISSAO', 403);
            }

            return null;
        }
        $holding = $this->holding();
        $contab = in_array($id, ['contabilidade', 'bi'], true);
        $def['dimensoes'] = array_filter($def['dimensoes'], fn ($d) => empty($d['holding']) || ($holding && $contab));
        if ($holding && ! $contab) {
            $def['dimensoes'] = ['empresa' => ['Empresa', "COALESCE(emp.nome, '-')", ['emp']]] + $def['dimensoes'];
            $def['juncoes']['emp'] = "LEFT JOIN empresas emp ON emp.id = {$def['alias']}.empresa_id";
            $def['padrao']['colunas'] = ['empresa'];
        }
        if ($holding && $contab) {
            $acessiveis = implode(',', array_map('intval', $this->empresas->idsAcessiveis($this->utilizador())) ?: [0]);
            $origem = "CASE WHEN l.tipo_consolidacao = 'ELIMINACAO' THEN 'Eliminação intragrupo' WHEN l.tipo_consolidacao = 'CONVERSAO' THEN 'Conversão cambial'
                WHEN l.empresa_origem_id IS NULL THEN 'Lançado na holding' WHEN l.empresa_origem_id NOT IN ({$acessiveis}) THEN '".self::SEM_ACESSO."' ELSE COALESCE(eo.nome, '-') END";
            $nome = "CASE WHEN l.empresa_origem_id IS NULL THEN '-' WHEN l.empresa_origem_id NOT IN ({$acessiveis}) THEN '".self::SEM_ACESSO."' ELSE COALESCE(eo.nome, '-') END";
            foreach ($def['dimensoes'] as $k => $d) {
                $def['dimensoes'][$k][1] = str_replace(['{empresa_origem}', '{empresa_origem_nome}'], [$origem, $nome], $d[1]);
            }
            if ($id === 'contabilidade') {
                $def['padrao']['colunas'] = ['empresa'];
            }
        }

        return $def;
    }

    /**
     * Valores distintos de uma dimensão no período (para os filtros do frontend), com o n.º de linhas.
     *
     * @return list<array{valor: string, linhas: int}>
     */
    public function valores(string $conjunto, string $dimensao, array $f): array
    {
        $def = $this->definicao($conjunto);
        if (! isset($def['dimensoes'][$dimensao])) {
            throw new ErroNegocio('Dimensão desconhecida.', 'CUBO_DIMENSAO_INVALIDA', 422, ['dimensao' => $dimensao]);
        }
        [$from, $where, $params] = $this->fonte($conjunto, $def, $f);
        $expr = $def['dimensoes'][$dimensao][1];
        $juncoes = $this->juncoes($def, [$dimensao], []);
        $pesquisa = '';
        if (! empty($f['pesquisa'])) {
            $pesquisa = " AND ({$expr})::text ILIKE ?";
            $params[] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['pesquisa']).'%';
        }
        $linhas = DB::select("SELECT ({$expr})::text AS valor, COUNT(*) AS n FROM {$from} {$juncoes} WHERE {$where}{$pesquisa} GROUP BY 1 ORDER BY ({$expr})::text COLLATE \"C\" LIMIT 1000", $params);

        return array_map(fn ($l) => ['valor' => $l->valor, 'linhas' => (int) $l->n], $linhas);
    }

    /**
     * Tabela dinâmica.
     *
     * @param  array<string, mixed>  $p  data_inicio, data_fim, linhas[], colunas[], medidas[{medida, agregacao}], filtros{dim: [valores]},
     *                                   exclusoes{dim: [valores]}, incluir_apuramento
     * @return array<string, mixed>
     */
    public function consultar(string $conjunto, array $p): array
    {
        $inicio = microtime(true);
        $def = $this->definicao($conjunto);
        $linhas = array_values($p['linhas'] ?? []);
        $colunas = array_values($p['colunas'] ?? []);
        $medidas = array_values($p['medidas'] ?? []) ?: $def['padrao']['medidas'];
        $this->validar($def, $linhas, $colunas, $medidas, $p);
        [$from, $where, $params] = $this->fonte($conjunto, $def, $p);
        foreach (['filtros' => 'IN', 'exclusoes' => 'NOT IN'] as $chave => $op) {
            foreach ($p[$chave] ?? [] as $dim => $valores) {
                $valores = array_values(array_map('strval', (array) $valores));
                if (! $valores) {
                    continue;
                }
                $where .= " AND ({$def['dimensoes'][$dim][1]})::text {$op} (".implode(', ', array_fill(0, count($valores), '?')).')';
                array_push($params, ...$valores);
            }
        }
        $dims = array_merge($linhas, $colunas);
        $ids = array_values(array_unique(array_filter(array_column($medidas, 'medida'))));
        $filtrosDims = array_merge(array_keys($p['filtros'] ?? []), array_keys($p['exclusoes'] ?? []));
        $juncoes = $this->juncoes($def, array_merge($dims, $filtrosDims), $ids);
        $sel = [];
        foreach ($dims as $i => $d) {
            $sel[] = "({$def['dimensoes'][$d][1]})::text AS d{$i}";
        }
        foreach ($ids as $i => $m) {
            $sel[] = "({$def['medidas'][$m][1]})::numeric AS m{$i}";
        }
        $sel = $sel ?: ['1 AS um'];
        $interior = 'SELECT '.implode(', ', $sel)." FROM {$from} {$juncoes} WHERE {$where}";
        $aliasL = array_map(fn ($i) => "d{$i}", array_keys($linhas));
        $aliasC = array_map(fn ($i) => 'd'.(count($linhas) + $i), array_keys($colunas));
        $todos = array_merge($aliasL, $aliasC);

        if ($todos) {
            $distintasC = $aliasC ? 'COUNT(DISTINCT concat_ws(chr(31), '.implode(', ', $aliasC).'))' : '0';
            $contagem = DB::selectOne("SELECT COUNT(*) AS n, {$distintasC} AS c FROM (SELECT ".implode(', ', $todos)." FROM ({$interior}) s GROUP BY ".implode(', ', $todos).') z', $params);
            if ((int) $contagem->n > self::MAX_CELULAS || ($aliasC && (int) $contagem->c > self::MAX_CHAVES_COLUNA)) {
                throw new ErroNegocio('A tabela tem demasiadas células. Reduza o período, as dimensões ou aplique filtros.', 'CUBO_DEMASIADAS_CELULAS', 422,
                    ['celulas' => (int) $contagem->n, 'colunas' => (int) $contagem->c, 'limite_celulas' => self::MAX_CELULAS, 'limite_colunas' => self::MAX_CHAVES_COLUNA]);
            }
        }
        $aggs = [];
        foreach ($medidas as $j => $m) {
            $fn = CatalogoCubo::AGREGACOES[$m['agregacao']];
            $casas = $m['agregacao'] === 'contagem' ? 0 : (($def['medidas'][$m['medida']][2] ?? 'kz') === 'num' ? 3 : 2);
            $aggs[] = $fn === 'COUNT' ? "COUNT(*) AS a{$j}" : "ROUND({$fn}(m".array_search($m['medida'], $ids, true)."), {$casas}) AS a{$j}";
        }
        $grupos = match (true) {
            $aliasL && $aliasC => '('.implode(', ', $todos).'), ('.implode(', ', $aliasL).'), ('.implode(', ', $aliasC).'), ()',
            (bool) $aliasL => '('.implode(', ', $aliasL).'), ()',
            (bool) $aliasC => '('.implode(', ', $aliasC).'), ()',
            default => null,
        };
        $marcas = array_merge($aliasL ? ["GROUPING({$aliasL[0]}) AS gl"] : [], $aliasC ? ["GROUPING({$aliasC[0]}) AS gc"] : []);
        $sql = 'SELECT '.implode(', ', array_merge($todos, $marcas, $aggs))." FROM ({$interior}) s".($grupos ? " GROUP BY GROUPING SETS ({$grupos})" : '');
        $res = DB::select($sql, $params);

        return $this->montar($conjunto, $def, $linhas, $colunas, $medidas, $res, $p) + ['duracao_ms' => (int) round((microtime(true) - $inicio) * 1000)];
    }

    /** @param  list<string>  $linhas */
    private function validar(array $def, array $linhas, array $colunas, array $medidas, array $p): void
    {
        $erros = [];
        if (count($linhas) > self::MAX_LINHAS || count($colunas) > self::MAX_COLUNAS) {
            $erros['dimensoes'] = 'No máximo '.self::MAX_LINHAS.' dimensões nas linhas e '.self::MAX_COLUNAS.' nas colunas.';
        }
        $dims = array_merge($linhas, $colunas);
        if (count($dims) !== count(array_unique($dims))) {
            $erros['dimensoes'] = 'Uma dimensão só pode aparecer uma vez.';
        }
        foreach ($dims as $d) {
            if (! is_string($d) || ! isset($def['dimensoes'][$d])) {
                $erros['dimensoes'] = 'Dimensão desconhecida: '.(is_string($d) ? $d : '?').'.';
            }
        }
        if (count($medidas) > self::MAX_MEDIDAS) {
            $erros['medidas'] = 'No máximo '.self::MAX_MEDIDAS.' medidas.';
        }
        foreach ($medidas as $m) {
            $ag = $m['agregacao'] ?? null;
            if (! is_string($ag) || ! isset(CatalogoCubo::AGREGACOES[$ag])) {
                $erros['medidas'] = 'Agregação desconhecida.';
            } elseif (($ag !== 'contagem' || ($m['medida'] ?? '') !== '') && (! is_string($m['medida'] ?? null) || ! isset($def['medidas'][$m['medida']]))) {   // também na contagem, se indicada (Fase 6: evitava um erro 500)
                $erros['medidas'] = 'Medida desconhecida: '.(is_string($m['medida'] ?? null) ? $m['medida'] : '?').'.';
            }
        }
        foreach (['filtros', 'exclusoes'] as $chave) {
            foreach ($p[$chave] ?? [] as $dim => $valores) {
                if (! isset($def['dimensoes'][$dim])) {
                    $erros[$chave] = "Dimensão desconhecida no filtro: {$dim}.";
                } elseif (count((array) $valores) > 500) {
                    $erros[$chave] = 'No máximo 500 valores por filtro.';
                }
            }
        }
        if ($erros) {
            throw new ErroNegocio('Pedido de análise inválido.', 'CUBO_PEDIDO_INVALIDO', 422, $erros);
        }
    }

    /**
     * FROM e WHERE do conjunto: empresas do âmbito e período (fonte com os filtros embutidos na tesouraria; linhas construídas
     * pelo razão analítico nos projectos).
     *
     * @param  list<int>|null  $empresas  empresas lidas (por omissão o âmbito do utilizador e da empresa activa)
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    private function fonte(string $conjunto, array $def, array $f, ?array $empresas = null): array
    {
        $ini = (string) $f['data_inicio'];
        $fim = (string) $f['data_fim'];
        $empresas ??= $this->ambito($conjunto);
        $marcas = implode(', ', array_fill(0, count($empresas), '?'));
        if (! empty($def['json'])) {
            return ['jsonb_to_recordset(?::jsonb) AS x(empresa_id bigint, data text, projeto text, estado_projeto text, tarefa text, tipo text, rubrica text, origem text, colaborador text, valor numeric, horas numeric)',
                'TRUE', [json_encode($this->linhasProjetos($empresas, $ini, $fim), JSON_UNESCAPED_UNICODE)]];
        }
        if (! empty($def['fonte_filtrada'])) {
            $params = [];
            for ($i = 0; $i < $def['fonte_filtrada']; $i++) {
                array_push($params, ...$empresas);
                array_push($params, $ini, $fim);
            }

            return [str_replace('{empresas}', $marcas, $def['tabela']), 'TRUE', $params];
        }
        $data = $def['data'];
        $where = "{$def['alias']}.empresa_id IN ({$marcas})";
        $params = $empresas;
        if (! empty($def['data_hora'])) {
            $where .= " AND {$data} >= ? AND {$data} < ?";
            array_push($params, $ini, date('Y-m-d', strtotime("{$fim} +1 day")));
        } elseif (! empty($def['data_mes'])) {
            $where .= " AND {$data} BETWEEN date_trunc('month', ?::date) AND ?::date";
            array_push($params, $ini, $fim);
        } else {
            $where .= " AND {$data} BETWEEN ? AND ?";
            array_push($params, $ini, $fim);
        }
        if (! empty($def['condicao'])) {
            $where .= " AND {$def['condicao']}";
        }
        if (in_array('incluir_apuramento', $def['opcoes'] ?? [], true) && empty($f['incluir_apuramento'])) {
            $where .= " AND NOT (COALESCE({$def['alias']}.periodo_id, 0) IN (13, 14) AND NOT EXISTS (SELECT 1 FROM diarios_contabeis d_sal WHERE d_sal.id = {$def['alias']}.diario_id AND d_sal.codigo = 'SAL'))";
        }

        return [$def['tabela'], $where, $params];
    }

    /**
     * Feed OData de leitura para o Power BI (ServicoFeedBI, decisão 25): FROM e WHERE de um conjunto da lista branca para UMA
     * empresa (a do token), sem utilizador nem expansão de holding. Mesmas regras de cada conjunto (anulados, apuramento…).
     *
     * @param  array{data_inicio: string, data_fim: string, incluir_apuramento?: bool}  $f
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    public function fonteFeed(string $conjunto, array $def, array $f, int $empresa): array
    {
        return $this->fonte($conjunto, $def, $f, [$empresa]);
    }

    /** @return list<int> empresas lidas: a activa; na holding, as do grupo acessíveis (excepto Contabilidade/BI, que lêem a holding) */
    private function ambito(string $conjunto): array
    {
        $empresa = $this->contexto->obrigatorio();
        if (in_array($conjunto, ['contabilidade', 'bi'], true) || ! $this->holding()) {
            return [$empresa];
        }
        $acessiveis = array_flip($this->empresas->idsAcessiveis($this->utilizador()));
        $membros = DB::table('grupos_consolidacao as g')->join('membros_consolidacao as m', 'm.grupo_consolidacao_id', '=', 'g.id')
            ->where('g.empresa_holding_id', $empresa)->pluck('m.empresa_membro_id')->map(fn ($x) => (int) $x)->filter(fn ($x) => isset($acessiveis[$x]))->unique()->values()->all();

        return $membros ?: [0];
    }

    /** Junções exigidas pelas dimensões e medidas usadas (só essas, para não pagar junções desnecessárias). */
    private function juncoes(array $def, array $dims, array $medidas): string
    {
        $chaves = [];
        foreach ($dims as $d) {
            array_push($chaves, ...($def['dimensoes'][$d][2] ?? []));
        }
        foreach ($medidas as $m) {
            array_push($chaves, ...($def['medidas'][$m][3] ?? []));
        }

        return implode(' ', array_map(fn ($k) => $def['juncoes'][$k], array_values(array_unique($chaves))));
    }

    /**
     * Linhas do conjunto Projectos (regra única do razão analítico, ADR-052): orçamento e aditamentos aprovados (sem data),
     * custos, proveitos e compromissos do período e horas registadas no período.
     *
     * @param  list<int>  $empresas
     * @return list<array<string, mixed>>
     */
    private function linhasProjetos(array $empresas, string $ini, string $fim): array
    {
        $tipos = ['CUSTO' => 'Custo realizado', 'PROVEITO' => 'Proveito', 'COMPROMISSO' => 'Compromisso'];
        $linhas = [];
        foreach ($empresas as $empresa) {
            if ($empresa <= 0) {
                continue;
            }
            $this->contexto->executarComo($empresa, function () use ($empresa, $ini, $fim, $tipos, &$linhas) {
                $projetos = DB::table('projetos')->where('empresa_id', $empresa)->whereNull('eliminado_em')->get(['id', 'codigo', 'nome', 'estado'])->keyBy('id');
                $tarefas = DB::table('tarefas_projeto')->where('empresa_id', $empresa)->pluck('nome', 'id');
                $nome = fn ($id) => isset($projetos[$id]) ? trim("{$projetos[$id]->codigo} - {$projetos[$id]->nome}") : "Projecto {$id}";
                $base = fn ($pid, $tid) => ['empresa_id' => $empresa, 'projeto' => $nome($pid), 'estado_projeto' => $projetos[$pid]->estado ?? null, 'tarefa' => $tid ? ($tarefas[$tid] ?? null) : null];
                foreach (DB::table('linhas_orcamento_projeto')->where('empresa_id', $empresa)->get(['projeto_id', 'tarefa_projeto_id', 'rubrica', 'montante']) as $o) {
                    $linhas[] = $base($o->projeto_id, $o->tarefa_projeto_id) + ['data' => null, 'tipo' => 'Orçamento', 'rubrica' => $o->rubrica, 'origem' => 'Orçamento', 'colaborador' => null,
                        'valor' => (string) $o->montante, 'horas' => 0];
                }
                foreach ($this->projetos->movimentos(null, $ini, $fim) as $m) {
                    $linhas[] = $base($m['projeto_id'], $m['tarefa_projeto_id'] ?? null) + ['data' => $m['data'], 'tipo' => $tipos[$m['natureza']] ?? 'Outro', 'rubrica' => $m['rubrica'],
                        'origem' => $m['origem'] ?? $m['modulo'] ?? null, 'colaborador' => null, 'valor' => $m['valor'], 'horas' => 0];
                }
                foreach (DB::table('aditamentos_alteracoes_projeto')->where('empresa_id', $empresa)->where('estado', 'APROVADO')->get(['projeto_id', 'montante']) as $a) {
                    $linhas[] = $base($a->projeto_id, null) + ['data' => null, 'tipo' => 'Aditamento aprovado', 'rubrica' => null, 'origem' => 'Aditamentos', 'colaborador' => null,
                        'valor' => (string) $a->montante, 'horas' => 0];
                }
                foreach (DB::table('folhas_horas_projeto as h')->leftJoin('colaboradores as c', 'c.id', '=', 'h.colaborador_id')->where('h.empresa_id', $empresa)
                    ->whereBetween('h.data', [$ini, $fim])->get(['h.projeto_id', 'h.tarefa_projeto_id', 'h.data', 'h.horas', 'c.nome_completo']) as $h) {
                    $linhas[] = $base($h->projeto_id, $h->tarefa_projeto_id) + ['data' => (string) $h->data, 'tipo' => 'Horas registadas', 'rubrica' => null, 'origem' => 'Folhas de horas',
                        'colaborador' => $h->nome_completo, 'valor' => 0, 'horas' => (string) $h->horas];
                }
            });
        }

        return $linhas;
    }

    /**
     * @param  list<object>  $res
     * @return array<string, mixed>
     */
    private function montar(string $conjunto, array $def, array $linhas, array $colunas, array $medidas, array $res, array $p): array
    {
        $nl = count($linhas);
        $nc = count($colunas);
        $chave = fn (object $r, int $de, int $n) => array_map(fn ($i) => $r->{'d'.($de + $i)}, range(0, $n - 1));
        $valores = function (object $r) use ($medidas) {
            $v = [];
            foreach ($medidas as $j => $m) {
                $x = $r->{"a{$j}"};
                $v[] = $x === null ? null : ($m['agregacao'] === 'contagem' ? (int) $x : (string) $x);
            }

            return $v;
        };
        $total = null;
        $colChaves = [];
        $totaisCol = [];
        $porLinha = [];
        foreach ($res as $r) {
            $gl = $nl ? (int) $r->gl : 1;
            $gc = $nc ? (int) $r->gc : 1;
            if ($gl === 1 && $gc === 1) {
                $total = $valores($r);
            } elseif ($gl === 1) {
                $k = $chave($r, $nl, $nc);
                $colChaves[json_encode($k)] = $k;
                $totaisCol[json_encode($k)] = $valores($r);
            } else {
                $k = $chave($r, 0, $nl);
                $j = json_encode($k);
                $porLinha[$j] ??= ['chave' => $k, 'celulas' => [], 'total' => null];
                if ($gc === 1) {
                    $porLinha[$j]['total'] = $valores($r);
                } else {
                    $porLinha[$j]['celulas'][json_encode($chave($r, $nl, $nc))] = $valores($r);
                }
            }
        }
        $ordenar = fn (array $a, array $b) => array_reduce(array_keys($a), fn ($c, $i) => $c ?: strnatcasecmp((string) $a[$i], (string) $b[$i]), 0);
        uasort($colChaves, $ordenar);
        uasort($porLinha, fn ($a, $b) => $ordenar($a['chave'], $b['chave']));
        $ordemCol = array_keys($colChaves);

        return [
            'conjunto' => ['id' => $conjunto, 'nome' => $def['nome']],
            'periodo' => ['data_inicio' => $p['data_inicio'], 'data_fim' => $p['data_fim']],
            'linhas' => array_map(fn ($d) => ['id' => $d, 'rotulo' => $def['dimensoes'][$d][0]], $linhas),
            'colunas' => array_map(fn ($d) => ['id' => $d, 'rotulo' => $def['dimensoes'][$d][0]], $colunas),
            'medidas' => array_map(fn ($m) => ['medida' => $m['medida'] ?? null, 'agregacao' => $m['agregacao'],
                'rotulo' => ($m['agregacao'] === 'contagem' ? 'Contagem' : $def['medidas'][$m['medida']][0]).($m['agregacao'] !== 'contagem' && $m['agregacao'] !== 'soma' ? ' ('.$m['agregacao'].')' : ''),
                'formato' => $m['agregacao'] === 'contagem' ? 'num' : $def['medidas'][$m['medida']][2]], $medidas),
            'chaves_colunas' => array_values($colChaves),
            'resultado' => array_values(array_map(fn ($l) => ['chave' => $l['chave'], 'valores' => array_map(fn ($k) => $l['celulas'][$k] ?? null, $ordemCol), 'total' => $l['total']], $porLinha)),
            'totais_colunas' => array_map(fn ($k) => $totaisCol[$k], $ordemCol),
            'total_geral' => $total,
            'filtros' => (object) ($p['filtros'] ?? []), 'exclusoes' => (object) ($p['exclusoes'] ?? []),
        ];
    }

    private function holding(): bool
    {
        return (bool) DB::table('empresas')->where('id', $this->contexto->obrigatorio())->value('e_consolidacao');
    }

    private function podeVer(array $vistas): bool
    {
        foreach ($vistas as $v) {
            if ($this->permissoes->podeVer($this->utilizador(), $v, $this->contexto->id())) {
                return true;
            }
        }

        return false;
    }

    private function utilizador(): Utilizador
    {
        /** @var Utilizador */
        return Auth::user();
    }
}
