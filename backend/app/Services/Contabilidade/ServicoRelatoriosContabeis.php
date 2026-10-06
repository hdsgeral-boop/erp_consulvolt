<?php

namespace App\Services\Contabilidade;

use App\Models\LancamentoContabil;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Mapas contabilísticos da empresa activa, calculados no PostgreSQL (agregações sobre os índices
 * (empresa_id, codigo_conta, data_documento)). Os estornos entram nos saldos (anulam o original);
 * `excluir_estornos` retira os pares original/estorno para leitura do movimento "efectivo".
 */
final class ServicoRelatoriosContabeis
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * Balancete: saldo inicial (antes de data_inicio), débitos e créditos do período, saldo final.
     * `nivel` agrega por prefixo do código (ex.: 1 = classe, 2 = grau 2 = "Balancete do Razão" do legado,
     * js/ui_reports.js:711-860); null = conta de movimento.
     *
     * Opções do legado (js/ui_reports.js:412-710): filtros comuns (FiltroMapas — classe 9 e apuramento do exercício
     * excluídos por omissão, filtro de contas, diário, UN, CC, terceiro), `totalizadoras` (linhas Σ das contas T por
     * prefixo, sem pontos), `por_terceiro` (quebra por terceiro), `sem_saldo_inicial`, `so_movimento`, `sem_saldo_zero`.
     * Correcções: o filtro de diário aplica-se também ao saldo inicial (o legado só o aplicava ao movimento, misturando
     * diários no saldo final) e os totais de saldos já não somam as linhas totalizadoras (o legado contava-as duas vezes,
     * js/ui_reports.js:528).
     *
     * @param  array<string, mixed>  $f
     * @return array{linhas: list<array<string, mixed>>, totais: array<string, string>}
     */
    public function balancete(array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        $nivel = isset($f['nivel']) ? (int) $f['nivel'] : null;
        $conta = $nivel ? 'left(l.codigo_conta, ?)' : 'l.codigo_conta';
        $params = $nivel ? [$nivel] : [];
        [$filtro, $pf] = FiltroMapas::sql('l', $f, (int) substr($f['data_fim'], 0, 4));
        $where = 'WHERE l.empresa_id = ? AND l.data_documento <= ?'
            .(! empty($f['prefixo']) ? ' AND l.codigo_conta LIKE ?' : '')
            .(! empty($f['excluir_estornos']) ? ' AND l.estorno_de_id IS NULL AND l.estornado_por_id IS NULL' : '')
            .$filtro;
        $pw = array_merge([$empresa, $f['data_fim']], ! empty($f['prefixo']) ? [FiltroMapas::like($f['prefixo'])] : [], $pf);
        $colunas = "SUM(CASE WHEN l.data_documento < ? THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) AS saldo_inicial,
                    SUM(CASE WHEN l.data_documento >= ? AND l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS debito,
                    SUM(CASE WHEN l.data_documento >= ? AND l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS credito";
        $pc = [$f['data_inicio'], $f['data_inicio'], $f['data_inicio']];

        $sql = "SELECT {$conta} AS codigo, {$colunas} FROM lancamentos_contabeis l {$where} GROUP BY 1 ORDER BY 1";
        $descricoes = DB::table('plano_contas')->where('empresa_id', $empresa)->whereNull('eliminado_em')->pluck('descricao', 'codigo');

        $terceiros = [];
        if (! empty($f['por_terceiro']) && ! $nivel) {
            foreach (DB::select("SELECT l.codigo_conta AS codigo, l.terceiro_id, t.nif, t.nome, {$colunas}
                    FROM lancamentos_contabeis l LEFT JOIN terceiros t ON t.id = l.terceiro_id {$where} AND l.terceiro_id IS NOT NULL
                    GROUP BY 1, 2, 3, 4 ORDER BY 1, 4", array_merge($pc, $pw)) as $t) {
                $terceiros[$t->codigo][] = $this->linhaBalancete($t, $f) + ['terceiro_id' => (int) $t->terceiro_id, 'nif' => $t->nif, 'nome' => $t->nome];
            }
        }

        $totais = ['saldo_inicial' => '0.00', 'debito' => '0.00', 'credito' => '0.00', 'saldo_final' => '0.00', 'saldo_devedor' => '0.00', 'saldo_credor' => '0.00'];
        $linhas = [];
        $movimento = [];
        foreach (DB::select($sql, array_merge($params, $pc, $pw)) as $r) {
            $linha = $this->linhaBalancete($r, $f);
            $movimento[$r->codigo] = $linha;
            if ($this->ocultar($linha, $f)) {
                // decisão 22: com «sem saldo zero» os totais continuam a ser os de todas as contas (como o legado,
                // que somava todos os movimentos); só a linha deixa de se ver
                if (! empty($f['sem_saldo_zero']) && ! $this->ocultar($linha, ['sem_saldo_zero' => false] + $f)) {
                    foreach (array_keys($totais) as $k) {
                        $totais[$k] = bcadd($totais[$k], $linha[$k], 2);
                    }
                }

                continue;
            }
            $linha = ['codigo_conta' => $r->codigo, 'descricao' => $descricoes[$r->codigo] ?? null] + $linha;
            if ($terceiros) {
                $linha['terceiros'] = array_values(array_filter($terceiros[$r->codigo] ?? [], fn ($t) => ! $this->ocultar($t, $f)));
            }
            foreach (array_keys($totais) as $k) {
                $totais[$k] = bcadd($totais[$k], $linha[$k], 2);
            }
            $linhas[] = $linha;
        }

        if (! empty($f['totalizadoras']) && ! $nivel && empty($f['so_movimento'])) {
            $linhas = array_merge($linhas, $this->linhasTotalizadoras($empresa, $movimento, $f));
            usort($linhas, fn ($a, $b) => strcmp((string) $a['codigo_conta'], (string) $b['codigo_conta']) ?: (($b['totalizadora'] ?? false) <=> ($a['totalizadora'] ?? false)));
        }

        return ['linhas' => $linhas, 'totais' => $totais];
    }

    /** @return array<string, string> saldos de uma linha do balancete (com as colunas devedor/credor do legado) */
    private function linhaBalancete(object $r, array $f): array
    {
        $si = ! empty($f['sem_saldo_inicial']) ? '0.00' : $this->fmt($r->saldo_inicial);
        $d = $this->fmt($r->debito);
        $c = $this->fmt($r->credito);
        $final = bcsub(bcadd($si, $d, 2), $c, 2);
        $pos = fn ($x) => bccomp($x, '0', 2) > 0 ? $x : '0.00';
        $neg = fn ($x) => bccomp($x, '0', 2) < 0 ? ltrim($x, '-') : '0.00';

        return ['saldo_inicial' => $si, 'saldo_inicial_devedor' => $pos($si), 'saldo_inicial_credor' => $neg($si), 'debito' => $d, 'credito' => $c,
            'saldo_final' => $final, 'saldo_devedor' => $pos($final), 'saldo_credor' => $neg($final)];
    }

    private function ocultar(array $l, array $f): bool
    {
        $zero = fn ($k) => bccomp($l[$k], '0', 2) === 0;
        if ($zero('saldo_inicial') && $zero('debito') && $zero('credito')) {
            return true;   // js/ui_reports.js:558
        }
        if (! empty($f['so_movimento']) && $zero('debito') && $zero('credito')) {
            return true;
        }
        if (! empty($f['sem_saldo_zero']) && $zero('saldo_final')) {
            return true;
        }

        return ! empty($f['so_com_saldo']) && $zero('saldo_final') && $zero('debito') && $zero('credito');
    }

    /**
     * Linhas Σ das contas totalizadoras (tipo T): somam as contas de movimento cujo código sem pontos começa pelo da
     * totalizadora e é mais comprido (js/ui_reports.js:486-508). Não entram nos totais.
     *
     * @param  array<string, array<string, string>>  $movimento
     */
    private function linhasTotalizadoras(int $empresa, array $movimento, array $f): array
    {
        $out = [];
        foreach (DB::table('plano_contas')->where('empresa_id', $empresa)->whereNull('eliminado_em')->where('tipo', 'T')->orderBy('codigo')->get(['codigo', 'descricao']) as $t) {
            $pfx = str_replace('.', '', trim((string) $t->codigo));
            if ($pfx === '' || isset($movimento[$t->codigo])) {
                continue;
            }
            $si = $d = $c = '0.00';
            foreach ($movimento as $codigo => $m) {
                $norm = str_replace('.', '', (string) $codigo);
                if (str_starts_with($norm, $pfx) && strlen($norm) > strlen($pfx)) {
                    $si = bcadd($si, $m['saldo_inicial'], 2);
                    $d = bcadd($d, $m['debito'], 2);
                    $c = bcadd($c, $m['credito'], 2);
                }
            }
            $linha = $this->linhaBalancete((object) ['saldo_inicial' => $si, 'debito' => $d, 'credito' => $c], ['sem_saldo_inicial' => false]);
            if ($this->ocultar($linha, ['sem_saldo_zero' => $f['sem_saldo_zero'] ?? false])) {
                continue;
            }
            $out[] = ['codigo_conta' => $t->codigo, 'descricao' => $t->descricao, 'totalizadora' => true] + $linha;
        }

        return $out;
    }

    /**
     * Razão / extracto de uma conta: saldo inicial + movimentos com saldo acumulado.
     *
     * @param  array{codigo_conta: string, data_inicio: string, data_fim: string, terceiro_id?: ?int}  $f
     * @return array<string, mixed>
     */
    public function razao(array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        $filtroTerceiro = isset($f['terceiro_id']) ? ' AND terceiro_id = ?' : '';
        $filtroTerceiroL = isset($f['terceiro_id']) ? ' AND l.terceiro_id = ?' : '';
        $base = [$empresa, $f['codigo_conta']];
        $terceiro = isset($f['terceiro_id']) ? [(int) $f['terceiro_id']] : [];

        $inicial = (string) (DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS s
            FROM lancamentos_contabeis WHERE empresa_id = ? AND codigo_conta = ? AND data_documento < ?{$filtroTerceiro}",
            array_merge($base, [$f['data_inicio']], $terceiro))->s);

        $movimentos = DB::select("SELECT l.id, l.data_documento, l.numero_lan, l.numero_documento, l.descricao, l.tipo_dc, l.valor, l.terceiro_id,
                t.nome AS terceiro_nome, t.nif AS terceiro_nif, d.codigo AS diario, l.estorno_de_id, l.estornado_por_id,
                ?::numeric + SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) OVER (ORDER BY l.data_documento, l.id) AS saldo
            FROM lancamentos_contabeis l JOIN diarios_contabeis d ON d.id = l.diario_id
            LEFT JOIN terceiros t ON t.id = l.terceiro_id
            WHERE l.empresa_id = ? AND l.codigo_conta = ? AND l.data_documento BETWEEN ? AND ?{$filtroTerceiroL}
            ORDER BY l.data_documento, l.id", array_merge([$inicial], $base, [$f['data_inicio'], $f['data_fim']], $terceiro));

        $debito = $credito = '0.00';
        foreach ($movimentos as $m) {
            $m->tipo_dc === 'D' ? $debito = bcadd($debito, (string) $m->valor, 2) : $credito = bcadd($credito, (string) $m->valor, 2);
            $m->valor = $this->fmt($m->valor);
            $m->saldo = $this->fmt($m->saldo);
            // nome do terceiro na própria consulta (sem N+1); terceiro eliminado (soft delete) continua a aparecer
            $m->terceiro = $m->terceiro_id !== null ? ['id' => (int) $m->terceiro_id, 'nome' => $m->terceiro_nome, 'nif' => $m->terceiro_nif] : null;
            unset($m->terceiro_nome, $m->terceiro_nif);
        }

        return ['codigo_conta' => $f['codigo_conta'], 'saldo_inicial' => $this->fmt($inicial), 'debito' => $debito, 'credito' => $credito,
            'saldo_final' => bcsub(bcadd($this->fmt($inicial), $debito, 2), $credito, 2), 'movimentos' => $movimentos];
    }

    /**
     * Lançamentos desequilibrados (Σ D ≠ Σ C) — decisão 2026-09-29: os do legado foram importados como estão
     * e são analisados aqui. Agrupa por diário + chave do lançamento (LancamentoContabil::chaveSql).
     *
     * @return array{resumo: array<string, mixed>, lancamentos: list<object>}
     */
    public function desequilibrios(?string $dataInicio = null, ?string $dataFim = null): array
    {
        $empresa = $this->contexto->obrigatorio();
        $periodo = ($dataInicio ? ' AND l.data_documento >= ?' : '').($dataFim ? ' AND l.data_documento <= ?' : '');
        $params = array_values(array_filter([$empresa, $dataInicio, $dataFim]));

        $chave = LancamentoContabil::chaveSql('l');
        $lancamentos = DB::select("SELECT d.codigo AS diario, l.diario_id, {$chave} AS lancamento,
                BOOL_AND(l.numero_lan IS NULL OR l.numero_lan = '') AS sem_numero_lan, MIN(l.data_documento) AS data_documento, COUNT(*) AS linhas,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS debito,
                SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS credito,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS diferenca,
                MIN(l.id) AS primeira_linha_id
            FROM lancamentos_contabeis l JOIN diarios_contabeis d ON d.id = l.diario_id
            WHERE l.empresa_id = ?{$periodo}
            GROUP BY d.codigo, l.diario_id, {$chave}
            HAVING SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) <> 0
            ORDER BY ABS(SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END)) DESC, 1, 3", $params);

        $total = DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE 0 END), 0) AS debito,
                COALESCE(SUM(CASE WHEN tipo_dc = 'C' THEN valor ELSE 0 END), 0) AS credito
            FROM lancamentos_contabeis l WHERE l.empresa_id = ?{$periodo}", $params);

        foreach ($lancamentos as $l) {
            foreach (['debito', 'credito', 'diferenca'] as $k) {
                $l->{$k} = $this->fmt($l->{$k});
            }
        }

        return [
            'resumo' => [
                'debito' => $this->fmt($total->debito), 'credito' => $this->fmt($total->credito),
                'diferenca' => bcsub($this->fmt($total->debito), $this->fmt($total->credito), 2),
                'lancamentos_desequilibrados' => count($lancamentos),
                'soma_das_diferencas' => array_reduce($lancamentos, fn ($a, $l) => bcadd($a, $l->diferenca, 2), '0.00'),
            ],
            'lancamentos' => $lancamentos,
        ];
    }

    /**
     * Extracto de conta corrente (gerarExtratoContaCorrente, js/ui_reports.js:1862-2298): várias contas (filtro "21, 24-26,
     * *x, x*") e/ou terceiro, saldo inicial, movimentos com saldo acumulado, contrapartidas, moeda e compensação.
     * `tipo`: todos | aberto (sem compensação e cujo n.º de documento não está saldado — heurística do legado) | compensado.
     * Correcções: as contrapartidas vêm de TODAS as linhas do lançamento (diário + chave, ADR-025) — o legado calculava-as
     * sobre as linhas já filtradas pela conta, mostrando quase sempre a própria conta; exige-se conta ou terceiro (o legado,
     * sem filtros, mostrava só o último lançamento da empresa).
     *
     * @param  array<string, mixed>  $f  data_inicio?, data_fim?, filtro_contas?, terceiro_id?, tipo?, incluir_apuramento?
     */
    public function extrato(array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        $ini = $f['data_inicio'] ?? null;
        $fim = $f['data_fim'] ?? '2099-12-31';
        $tipo = $f['tipo'] ?? 'todos';
        // js/ui_reports.js:1937-1944: exclui-se o apuramento do ano da data final (sem data final, o do ano corrente)
        [$filtro, $pf] = FiltroMapas::sql('l', $f, isset($f['data_fim']) ? (int) substr($f['data_fim'], 0, 4) : (int) date('Y'));
        $chave = LancamentoContabil::chaveSql('l');
        $chaveO = LancamentoContabil::chaveSql('o');
        // "Apenas em aberto": fora as compensadas e os n.º de documento com saldo nulo (calculado sobre as linhas filtradas)
        $sql = "WITH base AS (
                SELECT l.*, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END)
                    OVER (PARTITION BY COALESCE(NULLIF(l.numero_documento, ''), 'manual_' || l.id)) AS saldo_documento
                FROM lancamentos_contabeis l
                WHERE l.empresa_id = ? AND l.data_documento <= ?{$filtro}
            ), sel AS (
                SELECT * FROM base l WHERE TRUE".($tipo === 'aberto' ? ' AND l.reconciliacao_codigo IS NULL AND ABS(l.saldo_documento) > 0.005' : '').'
            )';
        $params = array_merge([$empresa, $fim], $pf);
        $inicial = $ini ? DB::selectOne("{$sql} SELECT COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS s,
                COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN 1 ELSE -1 END * COALESCE(valor_moeda, 0)), 0) AS sm,
                COUNT(DISTINCT COALESCE(NULLIF(codigo_moeda, ''), 'AOA')) AS moedas, MAX(COALESCE(NULLIF(codigo_moeda, ''), 'AOA')) AS moeda
            FROM sel WHERE data_documento < ?", array_merge($params, [$ini])) : (object) ['s' => 0, 'sm' => 0, 'moedas' => 0, 'moeda' => null];

        $filtroTipo = $tipo === 'compensado' ? ' AND l.reconciliacao_codigo IS NOT NULL' : '';
        $movimentos = DB::select("{$sql}
            SELECT l.id, l.data_documento, l.codigo_conta, pc.descricao AS descricao_conta, l.terceiro_id, t.nome AS terceiro, t.nif AS nif_terceiro,
                d.codigo AS diario, l.numero_lan, l.numero_documento, l.referencia, l.descricao, l.tipo_dc, l.valor, l.reconciliacao_codigo,
                l.codigo_moeda, l.valor_moeda, l.taxa_cambio, l.estorno_de_id, l.estornado_por_id,
                (SELECT string_agg(DISTINCT o.codigo_conta, ' / ' ORDER BY o.codigo_conta) FROM lancamentos_contabeis o
                  WHERE o.empresa_id = l.empresa_id AND o.diario_id = l.diario_id AND {$chaveO} = {$chave} AND o.id <> l.id) AS contrapartidas
            FROM sel l
            LEFT JOIN plano_contas pc ON pc.empresa_id = l.empresa_id AND pc.codigo = l.codigo_conta AND pc.eliminado_em IS NULL
            LEFT JOIN terceiros t ON t.id = l.terceiro_id
            LEFT JOIN diarios_contabeis d ON d.id = l.diario_id
            WHERE TRUE".($ini ? ' AND l.data_documento >= ?' : '')."{$filtroTipo}
            ORDER BY l.data_documento, l.id", array_merge($params, $ini ? [$ini] : []));

        $saldo = $this->fmt($inicial->s);
        $debito = $credito = '0.00';
        $moedas = [];
        foreach ($movimentos as $m) {
            $m->valor = $this->fmt($m->valor);
            $m->valor_moeda = $m->valor_moeda !== null ? $this->fmt($m->valor_moeda) : null;
            $saldo = $m->tipo_dc === 'D' ? bcadd($saldo, $m->valor, 2) : bcsub($saldo, $m->valor, 2);
            $m->tipo_dc === 'D' ? $debito = bcadd($debito, $m->valor, 2) : $credito = bcadd($credito, $m->valor, 2);
            $m->saldo = $saldo;
            if ($m->codigo_moeda && $m->codigo_moeda !== 'AOA') {
                $moedas[$m->codigo_moeda] = true;
            }
        }
        // Saldo na moeda só quando todos os movimentos (também os anteriores) estão na mesma moeda estrangeira (js/ui_reports.js:1999-2009)
        $moedaUnica = null;
        if (count($moedas) === 1) {
            $mo = array_key_first($moedas);
            $todas = collect($movimentos)->every(fn ($m) => ($m->codigo_moeda ?: 'AOA') === $mo);
            if ($todas && ((int) $inicial->moedas === 0 || ((int) $inicial->moedas === 1 && $inicial->moeda === $mo))) {
                $moedaUnica = $mo;
                $sm = $this->fmt($inicial->sm);
                foreach ($movimentos as $m) {
                    $sm = $m->tipo_dc === 'D' ? bcadd($sm, $m->valor_moeda ?? '0', 2) : bcsub($sm, $m->valor_moeda ?? '0', 2);
                    $m->saldo_moeda = $sm;
                }
            }
        }

        return [
            'data_inicio' => $ini, 'data_fim' => $f['data_fim'] ?? null, 'tipo' => $tipo,
            'saldo_inicial' => $this->fmt($inicial->s), 'debito' => $debito, 'credito' => $credito, 'saldo_final' => $saldo,
            'moedas_estrangeiras' => array_keys($moedas), 'moeda_saldo' => $moedaUnica, 'movimentos' => $movimentos,
        ];
    }

    /**
     * Balancete de evolução mensal (renderEvolucaoMensal, js/ui_reports.js:2865-3072): saldo (D−C) do movimento de cada mês
     * do ano, por conta (ou por razão — `nivel` 2), com quebra opcional por terceiro.
     * Correcção: exclui o apuramento (períodos 13/14) do ano, como os restantes mapas (o legado incluía-o só aqui, o que
     * anulava as classes 6 e 7 em Dezembro); `incluir_apuramento` repõe-no.
     */
    public function evolucao(array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        $ano = (int) $f['ano'];
        $nivel = isset($f['nivel']) ? (int) $f['nivel'] : null;
        [$filtro, $pf] = FiltroMapas::sql('l', $f, $ano);
        $conta = $nivel ? 'left(l.codigo_conta, '.$nivel.')' : 'l.codigo_conta';
        $porTerceiro = ! empty($f['por_terceiro']) && ! $nivel;
        $linhas = DB::select("SELECT {$conta} AS codigo, ".($porTerceiro ? 'l.terceiro_id' : 'NULL::bigint AS terceiro_id').",
                EXTRACT(MONTH FROM l.data_documento)::int AS mes, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS saldo
            FROM lancamentos_contabeis l
            WHERE l.empresa_id = ? AND l.data_documento BETWEEN ? AND ?{$filtro}
            GROUP BY 1, 2, 3 ORDER BY 1, 2, 3", array_merge([$empresa, "{$ano}-01-01", "{$ano}-12-31"], $pf));

        $descricoes = DB::table('plano_contas')->where('empresa_id', $empresa)->whereNull('eliminado_em')->pluck('descricao', 'codigo');
        $nomes = $porTerceiro ? DB::table('terceiros')->where('empresa_id', $empresa)->pluck('nome', 'id') : collect();
        $contas = [];
        $mesesAtivos = [];
        $totais = [];
        foreach ($linhas as $r) {
            $v = $this->fmt($r->saldo);
            $c = &$contas[$r->codigo];
            $c ??= ['codigo_conta' => $r->codigo, 'descricao' => $descricoes[$r->codigo] ?? null, 'meses' => [], 'saldo' => '0.00', 'terceiros' => []];
            $c['meses'][$r->mes] = bcadd($c['meses'][$r->mes] ?? '0.00', $v, 2);
            $c['saldo'] = bcadd($c['saldo'], $v, 2);
            if ($r->terceiro_id) {
                $t = &$c['terceiros'][$r->terceiro_id];
                $t ??= ['terceiro_id' => (int) $r->terceiro_id, 'nome' => $nomes[$r->terceiro_id] ?? null, 'meses' => [], 'saldo' => '0.00'];
                $t['meses'][$r->mes] = bcadd($t['meses'][$r->mes] ?? '0.00', $v, 2);
                $t['saldo'] = bcadd($t['saldo'], $v, 2);
                unset($t);
            }
            if (bccomp($v, '0', 2) !== 0) {
                $mesesAtivos[$r->mes] = true;
            }
            $totais[$r->mes] = bcadd($totais[$r->mes] ?? '0.00', $v, 2);
            unset($c);
        }
        // js/ui_reports.js:2940 — esconde as contas sem movimento (Σ|saldo mensal| = 0)
        $contas = array_values(array_filter($contas, fn ($c) => array_filter($c['meses'], fn ($v) => bccomp($v, '0', 2) !== 0)));
        foreach ($contas as &$c) {
            $c['terceiros'] = array_values($c['terceiros']);
        }
        unset($c);
        ksort($mesesAtivos);
        ksort($totais);

        return ['ano' => $ano, 'meses_ativos' => array_keys($mesesAtivos), 'contas' => $contas, 'totais' => $totais,
            'saldo_total' => array_reduce($totais, fn ($s, $v) => bcadd($s, $v, 2), '0.00')];
    }

    /**
     * Mapa de reconciliação de IVA (renderMapaIVA, js/ui_reports.js:6119-6500): linhas das contas 345 até data_fim (e desde
     * data_inicio, se indicada), com o total do documento proporcional ao peso da linha de IVA no IVA do documento
     * (contrapartidas não-IVA: max(Σ crédito, Σ débito)) e a base = max(0, total − IVA). Documento = diário + n.º + data.
     */
    public function mapaIva(array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        [$filtro, $pf] = FiltroMapas::sql('l', array_diff_key($f, ['filtro_contas' => 1]), (int) substr($f['data_fim'], 0, 4));
        $ini = $f['data_inicio'] ?? null;
        // Desempenho (Fase 6): com data_inicio, o CTE já não lê o histórico anterior — um documento é diário + n.º + data e só
        // interessam os de data ≥ data_inicio (filtro final), por isso o resultado é o mesmo.
        $desde = $ini ? ' AND l.data_documento >= ?' : '';
        $linhas = DB::select("WITH linhas AS (
                SELECT l.* FROM lancamentos_contabeis l WHERE l.empresa_id = ? AND l.data_documento <= ?{$filtro}{$desde}
            ), doc AS (
                SELECT diario_id, numero_documento, data_documento,
                    SUM(CASE WHEN replace(codigo_conta, ' ', '') LIKE '345%' THEN valor ELSE 0 END) AS iva,
                    SUM(CASE WHEN NOT (replace(codigo_conta, ' ', '') LIKE '345%') AND tipo_dc = 'C' THEN valor ELSE 0 END) AS contra_c,
                    SUM(CASE WHEN NOT (replace(codigo_conta, ' ', '') LIKE '345%') AND tipo_dc = 'D' THEN valor ELSE 0 END) AS contra_d,
                    MIN(terceiro_id) FILTER (WHERE terceiro_id IS NOT NULL) AS terceiro_doc
                FROM linhas GROUP BY 1, 2, 3
            )
            SELECT l.id, l.data_documento, d.codigo AS diario, l.numero_documento, l.codigo_conta, pc.descricao AS descricao_conta, l.descricao,
                l.tipo_dc, l.valor, COALESCE(l.terceiro_id, doc.terceiro_doc) AS terceiro_id, t.nif, t.nome,
                ROUND(GREATEST(doc.contra_c, doc.contra_d) * CASE WHEN doc.iva > 0 THEN l.valor / doc.iva ELSE 1 END, 2) AS total_documento
            FROM linhas l
            JOIN doc ON doc.diario_id IS NOT DISTINCT FROM l.diario_id AND doc.numero_documento IS NOT DISTINCT FROM l.numero_documento AND doc.data_documento = l.data_documento
            LEFT JOIN terceiros t ON t.id = COALESCE(l.terceiro_id, doc.terceiro_doc)
            LEFT JOIN diarios_contabeis d ON d.id = l.diario_id
            LEFT JOIN plano_contas pc ON pc.empresa_id = l.empresa_id AND pc.codigo = l.codigo_conta AND pc.eliminado_em IS NULL
            WHERE replace(l.codigo_conta, ' ', '') LIKE '345%'".($ini ? ' AND l.data_documento >= ?' : '').'
            ORDER BY l.data_documento, l.numero_documento, l.id', array_merge([$empresa, $f['data_fim']], $pf, $ini ? [$ini, $ini] : []));

        $t = ['total_documento' => '0.00', 'base' => '0.00', 'iva_debito' => '0.00', 'iva_credito' => '0.00'];
        foreach ($linhas as $l) {
            $l->valor = $this->fmt($l->valor);
            $l->total_documento = $this->fmt($l->total_documento);
            $base = bcsub($l->total_documento, $l->valor, 2);
            $l->base = bccomp($base, '0', 2) > 0 ? $base : '0.00';
            $l->iva_debito = $l->tipo_dc === 'D' ? $l->valor : '0.00';
            $l->iva_credito = $l->tipo_dc === 'C' ? $l->valor : '0.00';
            foreach ($t as $k => $s) {
                $t[$k] = bcadd($s, $l->{$k}, 2);
            }
        }

        return ['data_inicio' => $ini, 'data_fim' => $f['data_fim'], 'contas' => '345', 'totais' => $t + ['linhas' => count($linhas)], 'linhas' => $linhas];
    }

    private function fmt(mixed $v): string
    {
        return FiltroMapas::dinheiro($v);
    }
}
