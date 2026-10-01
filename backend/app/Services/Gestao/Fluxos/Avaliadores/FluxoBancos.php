<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use Illuminate\Support\Facades\DB;

/**
 * Fluxo dos bancos — contas 43 (carregarBancos/avaliarBanco, js/fluxo_tesouraria.js:44-180). Um processo é uma conta 43
 * num mês (até ao mês actual). Etapas: lançamento na Tesouraria → integração na contabilidade → extracto bancário →
 * reconciliação mensal. A reconciliação usa todos os movimentos da conta no razão (Tesouraria, POS, salários, lançamentos
 * directos); conciliado = com reconciliacao_codigo (no extracto, também o estado CONCILIADO*). Um mês só fica em dia se o
 * anterior da mesma conta estiver reconciliado (aviso). Tudo agregado em SQL por conta e mês.
 */
final class FluxoBancos extends AvaliadorFluxo
{
    public function temActividade(): bool
    {
        $e = $this->empresa();

        return DB::table('documentos_tesouraria')->where('empresa_id', $e)->whereRaw("TRIM(conta_financeira) LIKE '43%'")->exists()
            || DB::table('lancamentos_contabeis')->where('empresa_id', $e)->whereRaw("TRIM(codigo_conta) LIKE '43%'")->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $mesActual = substr(self::hoje(), 0, 7);
        $nomes = DB::table('plano_contas')->where('empresa_id', $e)->whereNull('eliminado_em')->where('codigo', 'like', '43%')->pluck('descricao', 'codigo')
            ->mapWithKeys(fn ($d, $c) => [trim((string) $c) => (string) $d]);
        $docs = DB::table('documentos_tesouraria')->where('empresa_id', $e)->whereRaw("TRIM(conta_financeira) LIKE '43%'")->whereRaw("COALESCE(estado, '') !~* 'ANUL'")->whereNotNull('data_documento')
            ->groupBy(DB::raw('TRIM(conta_financeira)'), DB::raw("to_char(data_documento, 'YYYY-MM')"))
            ->selectRaw("TRIM(conta_financeira) AS conta, to_char(data_documento, 'YYYY-MM') AS ym, COUNT(*) AS n,
                COUNT(*) FILTER (WHERE tipo = 'PAGAMENTO') AS n_pag, COALESCE(SUM(valor_total) FILTER (WHERE tipo = 'PAGAMENTO'), 0) AS v_pag,
                COUNT(*) FILTER (WHERE tipo = 'RECEBIMENTO') AS n_rec, COALESCE(SUM(valor_total) FILTER (WHERE tipo = 'RECEBIMENTO'), 0) AS v_rec,
                COUNT(*) FILTER (WHERE COALESCE(estado, '') <> 'INTEGRADO') AS n_pend, COALESCE(SUM(valor_total) FILTER (WHERE COALESCE(estado, '') <> 'INTEGRADO'), 0) AS v_pend")
            ->get()->keyBy(fn ($r) => "{$r->conta}|{$r->ym}");
        $linhas = DB::table('lancamentos_contabeis')->where('empresa_id', $e)->whereRaw("TRIM(codigo_conta) LIKE '43%'")->whereNotNull('data_documento')
            ->groupBy(DB::raw('TRIM(codigo_conta)'), DB::raw("to_char(data_documento, 'YYYY-MM')"))
            ->selectRaw("TRIM(codigo_conta) AS conta, to_char(data_documento, 'YYYY-MM') AS ym, COUNT(*) AS n,
                COALESCE(SUM(valor) FILTER (WHERE tipo_dc = 'D'), 0) AS deb, COALESCE(SUM(valor) FILTER (WHERE tipo_dc = 'C'), 0) AS cred,
                COUNT(*) FILTER (WHERE reconciliacao_codigo IS NULL) AS n_pend, COALESCE(SUM(valor) FILTER (WHERE reconciliacao_codigo IS NULL), 0) AS v_pend,
                (array_agg(COALESCE(numero_documento, numero_lan, '') || ' ' || valor::text ORDER BY data_documento, id) FILTER (WHERE reconciliacao_codigo IS NULL))[1:6] AS pend,
                (array_agg(DISTINCT reconciliacao_codigo) FILTER (WHERE reconciliacao_codigo IS NOT NULL))[1:6] AS refs")
            ->get()->keyBy(fn ($r) => "{$r->conta}|{$r->ym}");
        $conc = "(reconciliacao_codigo IS NOT NULL OR COALESCE(estado, '') ~* 'CONCILI')";
        $ext = DB::table('linhas_extrato_bancario')->where('empresa_id', $e)->whereRaw("TRIM(codigo_conta) LIKE '43%'")->whereNotNull('data')
            ->groupBy(DB::raw('TRIM(codigo_conta)'), DB::raw("to_char(data, 'YYYY-MM')"))
            ->selectRaw("TRIM(codigo_conta) AS conta, to_char(data, 'YYYY-MM') AS ym, COUNT(*) AS n, MIN(data) AS de, MAX(data) AS ate,
                COUNT(*) FILTER (WHERE tipo_dc = 'D') AS n_deb, COALESCE(SUM(valor) FILTER (WHERE tipo_dc = 'D'), 0) AS v_deb,
                COUNT(*) FILTER (WHERE tipo_dc = 'C') AS n_cred, COALESCE(SUM(valor) FILTER (WHERE tipo_dc = 'C'), 0) AS v_cred,
                COUNT(*) FILTER (WHERE NOT {$conc}) AS n_pend, COALESCE(SUM(valor) FILTER (WHERE NOT {$conc}), 0) AS v_pend,
                (array_agg(COALESCE(referencia, descricao, '') || ' ' || valor::text ORDER BY data, id) FILTER (WHERE NOT {$conc}))[1:6] AS pend,
                (array_agg(DISTINCT reconciliacao_codigo) FILTER (WHERE reconciliacao_codigo IS NOT NULL))[1:6] AS refs")
            ->get()->keyBy(fn ($r) => "{$r->conta}|{$r->ym}");
        $chaves = collect(array_keys($docs->all()))->merge(array_keys($linhas->all()))->merge(array_keys($ext->all()))->unique()
            ->filter(fn ($k) => preg_match('/\|\d{4}-\d{2}$/', $k) && substr($k, -7) <= $mesActual)
            ->sort(fn ($a, $b) => strcmp(explode('|', $a)[0], explode('|', $b)[0]) ?: strcmp(substr($a, -7), substr($b, -7)))->values();
        $processos = [];
        foreach ($chaves as $i => $k) {
            [$conta, $ym] = explode('|', $k);
            $p = $this->mes($conta, $ym, $docs[$k] ?? null, $linhas[$k] ?? null, $ext[$k] ?? null, $nomes[$conta] ?? '');
            $ant = $processos[$i - 1] ?? null;
            if ($ant && $ant['conta'] === $conta && $ant['etapas']['conc']['estado'] !== 'concluida' && $p['etapas']['conc']['estado'] !== 'fazer' && $p['etapas']['conc']['resumo'] !== 'Sem movimentos') {
                $p['etapas']['conc']['problemas'][] = self::aviso("O mês anterior ({$ant['titulo']}) desta conta ainda não está reconciliado.");
            }
            $processos[] = $p;
        }
        $porIntegrar = array_sum(array_column($processos, 'por_integrar'));
        $semExt = count(array_filter($processos, fn ($p) => $p['sem_extracto']));
        $porConc = array_sum(array_map(fn ($p) => $p['pendentes_razao'] + $p['pendentes_extracto'], $processos));

        return ['processos' => $processos, 'kpis' => [
            self::kpi('contas', 'Contas 43 com movimento', count(array_unique(array_column($processos, 'conta')))),
            self::kpi('por_integrar', 'Documentos por integrar', $porIntegrar, 'num', $porIntegrar > 0),
            self::kpi('sem_extracto', 'Meses sem extracto', $semExt, 'num', $semExt > 0),
            self::kpi('por_conciliar', 'Movimentos por conciliar', $porConc, 'num', $porConc > 0),
        ]];
    }

    private function mes(string $conta, string $ym, ?object $d, ?object $l, ?object $x, string $nome): array
    {
        $E = [];
        $mesTxt = substr($ym, 5, 2).'/'.substr($ym, 0, 4);
        $nDocs = (int) ($d->n ?? 0);
        $nLin = (int) ($l->n ?? 0);
        $nExt = (int) ($x->n ?? 0);
        $E['lanc'] = $nDocs ? self::feito("{$nDocs} documento(s)", array_merge([self::f('Pagamentos', $d->n_pag.' · '.self::kz($d->v_pag)), self::f('Recebimentos', $d->n_rec.' · '.self::kz($d->v_rec))],
            $nDocs - $d->n_pag - $d->n_rec ? [self::f('Outros', $nDocs - $d->n_pag - $d->n_rec, 'num')] : []))
            : self::naoAplica($nLin ? 'Movimentos de outras origens' : 'Sem documentos');
        $deb = self::dinheiro($l->deb ?? 0);
        $cred = self::dinheiro($l->cred ?? 0);
        $razao = [self::f('Movimentos no razão', $nLin, 'num'), self::f('Débitos', $deb, 'kz'), self::f('Créditos', $cred, 'kz'), self::f('Variação do mês', bcsub($deb, $cred, 2), 'kz')];
        $porIntegrar = (int) ($d->n_pend ?? 0);
        $E['integ'] = $porIntegrar
            ? self::etapa('curso', "{$porIntegrar} por integrar", array_merge([self::f('Por integrar', $porIntegrar.' · '.self::kz($d->v_pend))], $razao), [], [self::accao('Integrar na contabilidade', 'teso_contab_integracao', true)])
            : self::feito($nDocs ? "{$nDocs} integrado(s)" : 'Nada a integrar', $razao);
        if ($nExt) {
            $E['ext'] = self::feito("{$nExt} movimento(s)", [self::f('Movimentos carregados', $nExt, 'num'), self::f('Débitos', $x->n_deb.' · '.self::kz($x->v_deb)), self::f('Créditos', $x->n_cred.' · '.self::kz($x->v_cred)),
                self::f('Período', self::data($x->de).' a '.self::data($x->ate))]);
        } elseif ($nLin || $nDocs) {
            $E['ext'] = self::etapa('curso', 'Por carregar', [], [self::aviso("Falta carregar o extracto do banco de {$mesTxt} da conta {$conta}.")], [self::accao('Carregar o extracto na conciliação', 'teso_gestao_conciliacao', true)]);
        } else {
            $E['ext'] = self::naoAplica('Sem movimentos');
        }
        $pendR = (int) ($l->n_pend ?? 0);
        $pendX = (int) ($x->n_pend ?? 0);
        $arr = fn ($v) => $v ? array_values(array_filter(str_getcsv(trim((string) $v, '{}')), fn ($s) => $s !== '' && $s !== 'NULL')) : [];
        if (! $nExt) {
            $E['conc'] = $nLin ? self::porFazer('Depois do extracto') : self::naoAplica('Sem movimentos');
        } elseif ($pendR || $pendX) {
            $prob = [];
            if ($pendR) {
                $prob[] = self::aviso("{$pendR} movimento(s) da contabilidade sem correspondência no extracto: ".self::lista($arr($l->pend)).'.');
            }
            if ($pendX) {
                $prob[] = self::aviso("{$pendX} movimento(s) do banco por conciliar (por exemplo comissões, juros ou movimentos ainda não lançados): ".self::lista($arr($x->pend)).'.');
            }
            if ($porIntegrar) {
                $prob[] = self::aviso('Há documentos da Tesouraria por integrar neste mês; integre-os antes de reconciliar.');
            }
            $E['conc'] = self::etapa('curso', ($pendR + $pendX).' por conciliar', [self::f('Contabilidade conciliados', ($nLin - $pendR)." de {$nLin}"), self::f('Extracto conciliados', ($nExt - $pendX)." de {$nExt}"),
                self::f('Contabilidade por conciliar', self::dinheiro($l->v_pend ?? 0), 'kz'), self::f('Extracto por conciliar', self::dinheiro($x->v_pend), 'kz')], $prob,
                [self::accao('Reconciliar o mês', 'teso_gestao_conciliacao', true)]);
        } else {
            $E['conc'] = self::feito('Reconciliado', [self::f('Movimentos conciliados', "{$nLin} na contabilidade · {$nExt} no extracto"),
                self::f('Referência(s)', self::lista(array_merge($arr($l->refs ?? null), $arr($x->refs))) ?: '—')]);
        }

        return [
            'chave' => "banco-{$conta}-{$ym}", 'titulo' => $mesTxt, 'subtitulo' => trim("{$conta} {$nome}"), 'data' => $ym, 'valor' => bcsub($deb, $cred, 2), 'valor_pendente' => self::dinheiro($l->v_pend ?? 0),
            'etapas' => $E, 'conta' => $conta, 'conta_nome' => $nome, 'por_integrar' => $porIntegrar, 'sem_extracto' => ! $nExt && ($nLin || $nDocs),
            'pendentes_razao' => $pendR, 'pendentes_extracto' => $pendX, 'documentos' => [['tipo' => 'conta', 'numero' => $conta, 'mes' => $ym]],
        ] + self::contagem($E);
    }
}
