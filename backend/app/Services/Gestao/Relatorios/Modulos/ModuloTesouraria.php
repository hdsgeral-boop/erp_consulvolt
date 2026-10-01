<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Services\Gestao\Relatorios\PeriodosGestao;
use Illuminate\Support\Facades\DB;

/**
 * Tesouraria (relatorios_gestao.js:276-323): fluxos de caixa, disponibilidades e cobertura.
 * Regras do legado: entradas = débitos e saídas = créditos nas contas 43/45 com data no período (sem apuramento, incluindo
 * transferências internas); saldos 43+45 no dia anterior ao início e na data de fim; cobertura = saldo final ÷ saídas médias
 * mensais (meses = dias ÷ 30,4375, mínimo 1); documentos da tesouraria do período ainda PENDENTES; sessões da folha de caixa
 * abertas no período e desvio = saldo contado − saldo do sistema das sessões com contagem.
 */
final class ModuloTesouraria extends ModuloGestao
{
    public function id(): string
    {
        return 'tesouraria';
    }

    public function nome(): string
    {
        return 'Tesouraria';
    }

    public function descricao(): string
    {
        return 'Fluxos de caixa, disponibilidades e cobertura.';
    }

    public function calcular(array $p): array
    {
        $e = $this->empresa();
        $fin = "(TRIM(l.codigo_conta) LIKE '43%' OR TRIM(l.codigo_conta) LIKE '45%')";
        $mov = fn () => ModuloFinancas::diario($e)->whereRaw($fin)->whereBetween('l.data_documento', [$p['inicio'], $p['fim']])->whereRaw(ModuloFinancas::semApuramento());
        $t = $mov()->selectRaw("COALESCE(SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END), 0) AS ent, COALESCE(SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END), 0) AS sai")->first();
        $entradas = self::dinheiro($t->ent);
        $saidas = self::dinheiro($t->sai);
        $saldoAte = fn (string $data, string $cond) => self::dinheiro(ModuloFinancas::diario($e)->whereRaw($cond)->where('l.data_documento', '<=', $data)
            ->selectRaw("COALESCE(SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END), 0) AS s")->value('s'));
        $saldoIni = $saldoAte(PeriodosGestao::diaAnterior($p['inicio']), $fin);
        $saldoFim = $saldoAte($p['fim'], $fin);
        $bancos = $saldoAte($p['fim'], "TRIM(l.codigo_conta) LIKE '43%'");
        $caixa = $saldoAte($p['fim'], "TRIM(l.codigo_conta) LIKE '45%'");
        $meses = max(1, PeriodosGestao::dias($p) / 30.4375);
        $pend = DB::table('documentos_tesouraria')->where('empresa_id', $e)->whereRaw(self::sqlValido('estado'))->whereBetween('data_documento', [$p['inicio'], $p['fim']])
            ->where('estado', 'ilike', '%PENDENTE%')->count();
        $sessoes = DB::table('sessoes_caixa')->where('empresa_id', $e)->whereBetween('data_abertura', [$p['inicio'], $p['fim']])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(CASE WHEN saldo_fisico IS NOT NULL THEN saldo_fisico - COALESCE(saldo_fecho, 0) ELSE 0 END), 0) AS desvio')->first();
        $nomes = DB::table('plano_contas')->where('empresa_id', $e)->whereNull('eliminado_em')
            ->where(fn ($q) => $q->where('codigo', 'like', '43%')->orWhere('codigo', 'like', '45%'))->pluck('descricao', 'codigo')->mapWithKeys(fn ($d, $c) => [trim((string) $c) => $d]);
        $porConta = ModuloFinancas::diario($e)->whereRaw($fin)->where('l.data_documento', '<=', $p['fim'])
            ->groupBy(DB::raw('TRIM(l.codigo_conta)'))
            ->selectRaw('TRIM(l.codigo_conta) AS conta,
                SUM(CASE WHEN l.data_documento >= ? AND '.ModuloFinancas::semApuramento()." AND l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS entradas,
                SUM(CASE WHEN l.data_documento >= ? AND ".ModuloFinancas::semApuramento()." AND l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS saidas,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS saldo", [$p['inicio'], $p['inicio']])
            ->orderByRaw('TRIM(l.codigo_conta) COLLATE "C"')->get()
            ->map(fn ($r) => ['conta' => $r->conta, 'nome' => (string) ($nomes[$r->conta] ?? ''), 'entradas' => self::dinheiro($r->entradas), 'saidas' => self::dinheiro($r->saidas), 'saldo' => self::dinheiro($r->saldo)])->all();
        $mensal = $mov()->groupBy(DB::raw("to_char(l.data_documento, 'YYYY-MM')"))
            ->selectRaw("to_char(l.data_documento, 'YYYY-MM') AS m, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS ent, SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS sai")
            ->get()->keyBy('m');
        $lista = PeriodosGestao::meses($p);
        $mediaSaidas = (float) $saidas / $meses;

        return [
            'kpis' => [
                self::k('entradas', 'Entradas de disponibilidades', $entradas, 'kz', 'sobe', 'Movimentos a débito em bancos (43) e caixa (45).'),
                self::k('saidas', 'Saídas de disponibilidades', $saidas, 'kz', 'desce', 'Movimentos a crédito em 43 e 45 (inclui transferências internas).'),
                self::k('fluxo', 'Fluxo líquido', bcsub($entradas, $saidas, 2), 'kz', 'sobe', 'Entradas − saídas.'),
                self::k('saldo_ini', 'Disponibilidades no início', $saldoIni, 'kz', 'neutro', 'Saldo 43+45 no dia anterior ao início.'),
                self::k('saldo_fim', 'Disponibilidades no fim', $saldoFim, 'kz', 'sobe', 'Saldo 43+45 na data de fim.'),
                self::k('bancos', 'Saldo em bancos', $bancos, 'kz', 'neutro', 'Conta 43 na data de fim.'),
                self::k('caixa', 'Saldo em caixa', $caixa, 'kz', 'neutro', 'Conta 45 na data de fim. Boa prática: manter numerário baixo.'),
                self::k('cobertura', 'Meses de cobertura', self::div($saldoFim, $mediaSaidas), 'meses', 'sobe', 'Disponibilidades no fim ÷ saídas médias mensais.', ['casas' => 1]),
                self::k('docs_pend', 'Documentos por integrar', $pend, 'num', 'desce', 'Pagamentos/recebimentos do período ainda PENDENTES na contabilidade.'),
                self::k('sessoes', 'Sessões de caixa', (int) $sessoes->n, 'num', 'neutro', 'Folhas de caixa abertas no período.'),
                self::k('desvio_caixa', 'Desvios de caixa (físico − teórico)', $sessoes->desvio, 'kz', 'neutro', 'Soma das diferenças de contagem nas sessões fechadas.'),
            ],
            'tabelas' => [self::tabela('contas', 'Contas financeiras', 'conta', [['conta', 'Conta'], ['nome', 'Descrição'], ['entradas', 'Entradas', 'kz'], ['saidas', 'Saídas', 'kz'], ['saldo', 'Saldo final', 'kz']], $porConta)],
            'graficos' => [['id' => 'teso_mensal', 'titulo' => 'Entradas e saídas por mês', 'tipo' => 'bar', 'rotulos' => array_column($lista, 'rotulo'), 'series' => [
                ['rotulo' => 'Entradas', 'valores' => array_map(fn ($m) => self::dinheiro($mensal[$m['chave']]->ent ?? 0), $lista)],
                ['rotulo' => 'Saídas', 'valores' => array_map(fn ($m) => self::dinheiro($mensal[$m['chave']]->sai ?? 0), $lista)],
            ]]],
            'notas' => [],
        ];
    }
}
