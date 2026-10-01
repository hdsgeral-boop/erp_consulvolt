<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Services\Gestao\Relatorios\PeriodosGestao;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finanças (relatorios_gestao.js:119-175): demonstração de resultados resumida, margens e posição financeira, a partir do Diário.
 * Regras do legado: classe 9 excluída; proveitos C − D e custos D − C das linhas com data no período, sem as de apuramento
 * (períodos 13/14); saldos de balanço (43+45, 31, 32) à data de fim, com todas as linhas.
 * Correcção: no legado `journal_lines.period_id` era partilhado com o id do período salarial (fluxo_processos.js:100), pelo
 * que um processamento salarial com id 13 ou 14 saía da DR como se fosse apuramento; agora só se excluem as linhas dos
 * períodos 13/14 que não sejam do diário de salários (SAL).
 */
final class ModuloFinancas extends ModuloGestao
{
    public function id(): string
    {
        return 'financas';
    }

    public function nome(): string
    {
        return 'Finanças';
    }

    public function descricao(): string
    {
        return 'Demonstração de resultados resumida, margens e posição financeira (a partir do Diário).';
    }

    /** Linhas do Diário da empresa, sem a classe 9. */
    public static function diario(int $empresa): Builder
    {
        return DB::table('lancamentos_contabeis as l')->leftJoin('diarios_contabeis as dd', 'dd.id', '=', 'l.diario_id')
            ->where('l.empresa_id', $empresa)->whereRaw("TRIM(l.codigo_conta) NOT LIKE '9%'")->whereRaw("TRIM(COALESCE(l.codigo_conta, '')) <> ''");
    }

    /** Condição SQL «não é linha de apuramento» (períodos 13/14 fora do diário de salários). */
    public static function semApuramento(): string
    {
        return "NOT (COALESCE(l.periodo_id, 0) IN (13, 14) AND COALESCE(dd.codigo, '') <> 'SAL')";
    }

    public function calcular(array $p): array
    {
        $e = $this->empresa();
        // D − C por prefixo de 2 dígitos, no período, sem apuramento
        $porPrefixo = self::diario($e)->whereBetween('l.data_documento', [$p['inicio'], $p['fim']])->whereRaw(self::semApuramento())
            ->groupBy(DB::raw('LEFT(TRIM(l.codigo_conta), 2)'))
            ->selectRaw("LEFT(TRIM(l.codigo_conta), 2) AS p, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS dc")
            ->pluck('dc', 'p')->map(fn ($v) => self::dinheiro($v))->all();
        $dc = function (array $prefixos) use ($porPrefixo): string {
            $s = '0.00';
            foreach ($porPrefixo as $k => $v) {
                foreach ($prefixos as $x) {
                    if (str_starts_with((string) $k, $x)) {
                        $s = bcadd($s, $v, 2);
                        break;
                    }
                }
            }

            return $s;
        };
        $P = fn (array $pre) => bcmul($dc($pre), '-1', 2);
        $C = fn (array $pre) => $dc($pre);
        $vendas = $P(['61']);
        $prest = $P(['62']);
        $outrosOp = $P(['63', '64', '65']);
        $financP = $P(['66', '67']);
        $naoOpP = $P(['68', '69']);
        $cmvmc = $C(['71']);
        $pessoal = $C(['72']);
        $amort = $C(['73']);
        $outrosC = $C(['74', '75']);
        $financC = $C(['76', '77']);
        $naoOpC = $C(['78', '79']);
        $proveitos = $P(['6']);
        $custos = $C(['7']);
        $vn = bcadd($vendas, $prest, 2);
        $ebit = bcsub(bcadd($vn, $outrosOp, 2), self::soma($cmvmc, $pessoal, $amort, $outrosC), 2);
        $ebitda = bcadd($ebit, $amort, 2);
        $resultado = bcsub($proveitos, $custos, 2);
        $saldos = self::diario($e)->where('l.data_documento', '<=', $p['fim'])
            ->selectRaw("COALESCE(SUM(CASE WHEN TRIM(l.codigo_conta) LIKE '43%' OR TRIM(l.codigo_conta) LIKE '45%' THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) ELSE 0 END), 0) AS disp,
                COALESCE(SUM(CASE WHEN TRIM(l.codigo_conta) LIKE '31%' THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) ELSE 0 END), 0) AS cli,
                COALESCE(SUM(CASE WHEN TRIM(l.codigo_conta) LIKE '32%' THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) ELSE 0 END), 0) AS forn")->first();
        $disponib = self::dinheiro($saldos->disp);
        $clientes = self::dinheiro($saldos->cli);
        $fornecedores = bcmul(self::dinheiro($saldos->forn), '-1', 2);
        $dias = PeriodosGestao::dias($p);
        $compras = $C(['71', '75']);
        if (bccomp($compras, '0', 2) === 0) {
            $compras = $cmvmc;
        }
        $kpis = [
            self::k('vn', 'Volume de negócios', $vn, 'kz', 'sobe', 'Vendas (61) + prestações de serviços (62), líquidas de devoluções.'),
            self::k('proveitos', 'Total de proveitos', $proveitos, 'kz', 'sobe', 'Classe 6 (C − D), sem apuramento de resultados.'),
            self::k('custos', 'Total de custos', $custos, 'kz', 'desce', 'Classe 7 (D − C), sem apuramento de resultados.'),
            self::k('margem_bruta', 'Margem bruta', bcsub($vn, $cmvmc, 2), 'kz', 'sobe', 'Volume de negócios − CMVMC (71).'),
            self::k('margem_bruta_pct', 'Margem bruta %', self::pct(bcsub($vn, $cmvmc, 2), $vn), 'pct', 'sobe', 'Margem bruta ÷ volume de negócios.'),
            self::k('ebitda', 'EBITDA', $ebitda, 'kz', 'sobe', 'Resultado operacional + amortizações (73).'),
            self::k('ebitda_pct', 'Margem EBITDA %', self::pct($ebitda, $vn), 'pct', 'sobe', 'EBITDA ÷ volume de negócios.'),
            self::k('ebit', 'Resultado operacional (EBIT)', $ebit, 'kz', 'sobe', '(61 a 65) − (71 a 75).'),
            self::k('fin', 'Resultados financeiros', bcsub($financP, $financC, 2), 'kz', 'sobe', '(66, 67) − (76, 77).'),
            self::k('resultado', 'Resultado líquido do período', $resultado, 'kz', 'sobe', 'Proveitos − custos (antes do apuramento).'),
            self::k('margem_liq', 'Margem líquida %', self::pct($resultado, $vn), 'pct', 'sobe', 'Resultado ÷ volume de negócios.'),
            self::k('pessoal_pct', 'Peso dos custos com pessoal', self::pct($pessoal, $vn), 'pct', 'desce', 'Custos com pessoal (72) ÷ volume de negócios.'),
            self::k('disponib', 'Disponibilidades (fim do período)', $disponib, 'kz', 'sobe', 'Saldo de bancos (43) e caixa (45) à data de fim.'),
            self::k('clientes', 'Clientes a receber (fim)', $clientes, 'kz', 'desce', 'Saldo devedor da conta 31 à data de fim.'),
            self::k('fornecedores', 'Fornecedores a pagar (fim)', $fornecedores, 'kz', 'neutro', 'Saldo credor da conta 32 à data de fim.'),
            self::k('pmr', 'Prazo médio de recebimento', ($d = self::div($clientes, $vn)) === null ? null : $d * $dias, 'dias', 'desce',
                'Clientes ÷ volume de negócios × dias do período (valores sem/ com IVA misturados: indicativo).'),
            self::k('pmp', 'Prazo médio de pagamento', ($d = self::div($fornecedores, $compras)) === null ? null : $d * $dias, 'dias', 'neutro',
                'Fornecedores ÷ (CMVMC + outros custos operacionais) × dias do período (indicativo).'),
        ];
        $linhas = [];
        foreach ([['Vendas (61)', $vendas], ['Prestações de serviços (62)', $prest], ['Outros proveitos operacionais (63-65)', $outrosOp],
            ['CMVMC (71)', bcmul($cmvmc, '-1', 2)], ['Custos com pessoal (72)', bcmul($pessoal, '-1', 2)], ['Amortizações (73)', bcmul($amort, '-1', 2)],
            ['Outros custos operacionais (74-75)', bcmul($outrosC, '-1', 2)], ['Resultado operacional', $ebit], ['Proveitos financeiros (66-67)', $financP],
            ['Custos financeiros (76-77)', bcmul($financC, '-1', 2)], ['Outros proveitos (68-69)', $naoOpP], ['Outros custos (78-79)', bcmul($naoOpC, '-1', 2)],
            ['Resultado do período', $resultado]] as [$rubrica, $valor]) {
            $linhas[] = ['rubrica' => $rubrica, 'valor' => self::dinheiro($valor), 'peso' => self::valor(self::pct($valor, $vn), 'pct')];
        }
        $meses = PeriodosGestao::meses($p);
        $mensal = self::diario($e)->whereBetween('l.data_documento', [$p['inicio'], $p['fim']])->whereRaw(self::semApuramento())
            ->whereRaw("(TRIM(l.codigo_conta) LIKE '6%' OR TRIM(l.codigo_conta) LIKE '7%')")
            ->groupBy(DB::raw("to_char(l.data_documento, 'YYYY-MM')"), DB::raw('LEFT(TRIM(l.codigo_conta), 1)'))
            ->selectRaw("to_char(l.data_documento, 'YYYY-MM') AS m, LEFT(TRIM(l.codigo_conta), 1) AS c, SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS dc")
            ->get()->groupBy('c')->map(fn ($g) => $g->pluck('dc', 'm'));

        return [
            'kpis' => $kpis,
            'tabelas' => [self::tabela('dr', 'Demonstração de resultados resumida', 'rubrica', [['rubrica', 'Rubrica'], ['valor', 'Valor', 'kz'], ['peso', '% do VN', 'pct']], $linhas)],
            'graficos' => [['id' => 'fin_mensal', 'titulo' => 'Proveitos e custos por mês', 'tipo' => 'bar', 'rotulos' => array_column($meses, 'rotulo'), 'series' => [
                ['rotulo' => 'Proveitos', 'valores' => array_map(fn ($m) => self::dinheiro(bcmul(self::dinheiro($mensal['6'][$m['chave']] ?? 0), '-1', 2)), $meses)],
                ['rotulo' => 'Custos', 'valores' => array_map(fn ($m) => self::dinheiro($mensal['7'][$m['chave']] ?? 0), $meses)],
            ]]],
            'notas' => [],
        ];
    }
}
