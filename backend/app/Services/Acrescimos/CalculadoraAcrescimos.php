<?php

namespace App\Services\Acrescimos;

use Carbon\CarbonImmutable;

/**
 * Cálculo puro dos acréscimos e diferimentos (js/modules/acrescimos/ad_dados.js:62-244), sem acesso à base de dados:
 *   - plano de repartição (quotas) por MESES (peso 1 por mês civil tocado) ou por DIAS (dias do período em cada mês),
 *     com arredondamento acumulado: cada quota = arred(total × pesos acumulados ÷ soma) − quotas anteriores, e a última
 *     absorve a diferença — a soma das quotas é sempre o valor total (ad_dados.js:109-120);
 *   - linhas por contabilizar de um registo até ao mês-alvo (pendentesItem, ad_dados.js:212-237);
 *   - contas a débito/crédito de cada tipo de linha (contasLinha, ad_dados.js:240-244).
 * Correcções face ao legado: aritmética decimal exacta (bcmath) em vez de floats; um diferimento sem data do documento
 * não gera um lançamento inicial com período vazio (o legado comparava '' <= mês-alvo).
 */
final class CalculadoraAcrescimos
{
    public const TIPOS = ['ACRESCIMO' => 'Acréscimo', 'DIFERIMENTO' => 'Diferimento'];

    public const NATUREZAS = ['CUSTO' => 'Gasto', 'PROVEITO' => 'Rendimento'];

    public const ESTADOS = ['ACTIVO' => 'Activo', 'A_REGULARIZAR' => 'Regularização por contabilizar', 'REGULARIZADO' => 'Regularizado',
        'A_TERMINAR' => 'Término por contabilizar', 'CONCLUIDO' => 'Concluído', 'ANULADO' => 'Anulado'];

    public const TIPOS_LINHA = ['INICIAL' => 'Diferimento inicial', 'RECONHECIMENTO' => 'Reconhecimento', 'REGULARIZACAO' => 'Regularização',
        'ANULACAO' => 'Anulação', 'TERMINO' => 'Término antecipado'];

    /** Estados sem mais lançamentos. */
    public const FECHADOS = ['REGULARIZADO', 'CONCLUIDO', 'ANULADO'];

    public static function mes(?string $data): string
    {
        return substr((string) $data, 0, 7);
    }

    public static function fimMes(string $periodo): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', "{$periodo}-01")->endOfMonth()->toDateString();
    }

    public static function somarMeses(string $periodo, int $n): string
    {
        [$a, $m] = array_map('intval', explode('-', $periodo));
        $t = $a * 12 + ($m - 1) + $n;

        return sprintf('%04d-%02d', intdiv($t, 12), $t % 12 + 1);
    }

    public static function somarDias(string $data, int $n): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', substr($data, 0, 10))->addDays($n)->toDateString();
    }

    /** @return list<string> meses AAAA-MM de $ini a $fim (inclusive) */
    public static function meses(string $ini, string $fim): array
    {
        $r = [];
        for ($p = self::mes($ini); $p <= self::mes($fim); $p = self::somarMeses($p, 1)) {
            $r[] = $p;
        }

        return $r;
    }

    /** Arredondamento a 2 casas, metade para longe de zero (ADR-022). */
    public static function arred(string $v): string
    {
        return bcadd($v, str_starts_with(ltrim($v), '-') ? '-0.005' : '0.005', 2);
    }

    public static function dinheiro(mixed $v): string
    {
        if (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', trim($v))) {
            return self::arred(trim($v));
        }

        return self::arred(is_numeric($v) ? number_format((float) $v, 6, '.', '') : '0');
    }

    /**
     * Plano de repartição.
     *
     * @return list<array{periodo: string, valor: string, peso: int}>
     */
    public static function quotas(mixed $valor, ?string $ini, ?string $fim, ?string $reparticao): array
    {
        $total = self::dinheiro($valor);
        $ini = substr((string) $ini, 0, 10);
        $fim = substr((string) $fim, 0, 10);
        if ($ini === '' || $fim === '' || $fim < $ini || bccomp($total, '0', 2) === 0) {
            return [];
        }
        $meses = self::meses($ini, $fim);
        $pesos = array_map(function ($p) use ($ini, $fim, $reparticao) {
            if ($reparticao === 'MESES') {
                return 1;
            }
            $a = max($ini, "{$p}-01");
            $z = min($fim, self::fimMes($p));

            return (int) CarbonImmutable::parse($a)->diffInDays(CarbonImmutable::parse($z)) + 1;
        }, $meses);
        $soma = array_sum($pesos);
        $acumulado = 0;
        $anterior = '0.00';
        $r = [];
        foreach ($meses as $i => $p) {
            $acumulado += $pesos[$i];
            $v = $i === count($meses) - 1 ? bcsub($total, $anterior, 2)
                : bcsub(self::arred(bcdiv(bcmul($total, (string) $acumulado, 10), (string) $soma, 10)), $anterior, 2);
            $anterior = bcadd($anterior, $v, 2);
            $r[] = ['periodo' => $p, 'valor' => $v, 'peso' => (int) $pesos[$i]];
        }

        return $r;
    }

    /**
     * Débito e crédito de cada tipo de linha (R = conta de resultados, B = conta de balanço 37).
     *
     * @return array{debito: string, credito: string}
     */
    public static function contasLinha(array $it, string $tipoLinha): array
    {
        $r = (string) $it['conta_resultado'];
        $b = (string) $it['conta_balanco'];
        $recon = $it['natureza'] === 'CUSTO' ? ['debito' => $r, 'credito' => $b] : ['debito' => $b, 'credito' => $r];

        return in_array($tipoLinha, ['RECONHECIMENTO', 'TERMINO'], true) ? $recon : ['debito' => $recon['credito'], 'credito' => $recon['debito']];
    }

    /**
     * Linhas por contabilizar de um registo até ao mês-alvo (inclusive).
     *
     * @param  array<string, mixed>  $it  registo (datas em AAAA-MM-DD; regularizacao/termino como arrays)
     * @param  list<array{tipo: string, periodo: string, valor: string}>  $feitos  lançamentos CONTABILIZADOS do registo
     * @return list<array<string, mixed>>
     */
    public static function pendentes(array $it, string $mesAlvo, array $feitos): array
    {
        if (in_array($it['estado'], self::FECHADOS, true)) {
            return [];
        }
        $feito = fn (string $tipo, ?string $periodo = null) => collect($feitos)->contains(fn ($p) => $p['tipo'] === $tipo && ($periodo === null || $p['periodo'] === $periodo));
        $soma = fn (string $tipo) => array_reduce(array_filter($feitos, fn ($p) => $p['tipo'] === $tipo), fn ($s, $p) => bcadd($s, (string) $p['valor'], 2), '0.00');
        $reg = $it['regularizacao'] ?? null;
        $ter = $it['termino'] ?? null;
        $linhas = [];
        $corte = null;   // os períodos a partir deste já não se reconhecem
        if ($it['estado'] === 'A_REGULARIZAR' && $reg) {
            $corte = self::mes($reg['data']);
        }
        if ($it['estado'] === 'A_TERMINAR' && $ter) {
            $corte = self::somarMeses(self::mes($ter['data']), 1);
        }
        $emBalanco = (bool) ($it['documento_em_balanco'] ?? false);
        if ($it['tipo'] === 'DIFERIMENTO' && ! $emBalanco && ! $feito('INICIAL') && ! empty($it['data_documento']) && self::mes($it['data_documento']) <= $mesAlvo) {
            $linhas[] = ['tipo' => 'INICIAL', 'periodo' => self::mes($it['data_documento']), 'valor' => self::dinheiro($it['valor']), 'data_preferida' => $it['data_documento']];
        }
        // diferimento: só se reconhece depois (ou junto) do lançamento inicial
        $inicialOk = $it['tipo'] !== 'DIFERIMENTO' || $emBalanco || $feito('INICIAL') || $linhas !== [];
        $reconhecido = $soma('RECONHECIMENTO');
        if ($inicialOk) {
            foreach (self::quotas($it['valor'], $it['data_inicio'], $it['data_fim'], $it['reparticao']) as $q) {
                if ($q['periodo'] <= $mesAlvo && (! $corte || $q['periodo'] < $corte) && ! $feito('RECONHECIMENTO', $q['periodo'])) {
                    $linhas[] = ['tipo' => 'RECONHECIMENTO', 'periodo' => $q['periodo'], 'valor' => $q['valor'], 'data_preferida' => self::fimMes($q['periodo'])];
                    $reconhecido = bcadd($reconhecido, $q['valor'], 2);
                }
            }
        }
        if ($it['estado'] === 'A_REGULARIZAR' && $reg && self::mes($reg['data']) <= $mesAlvo && ! $feito('REGULARIZACAO') && ! $feito('ANULACAO')) {
            $anulacao = ! empty($reg['anulacao']);
            $linhas[] = ['tipo' => $anulacao ? 'ANULACAO' : 'REGULARIZACAO', 'periodo' => self::mes($reg['data']), 'valor' => $reconhecido, 'data_preferida' => $reg['data'],
                'diferenca' => $anulacao ? null : bcsub(self::dinheiro($reg['valor'] ?? 0), $reconhecido, 2), 'fecha' => true];
        }
        if ($it['estado'] === 'A_TERMINAR' && $ter && self::mes($ter['data']) <= $mesAlvo && ! $feito('TERMINO')) {
            $linhas[] = ['tipo' => 'TERMINO', 'periodo' => self::mes($ter['data']), 'valor' => bcsub(bcsub(self::dinheiro($it['valor']), $reconhecido, 2), $soma('TERMINO'), 2),
                'data_preferida' => $ter['data'], 'fecha' => true];
        }

        return array_values(array_filter($linhas, fn ($l) => bccomp($l['valor'], '0', 2) > 0 || ! empty($l['fecha'])));
    }

    /** Saldo que o registo deixa na conta de balanço (positivo = devedor). */
    public static function saldoBalanco(array $it, array $feitos): string
    {
        $s = '0.00';
        foreach ($feitos as $p) {
            $c = self::contasLinha($it, $p['tipo']);
            if ($c['debito'] === (string) $it['conta_balanco']) {
                $s = bcadd($s, (string) $p['valor'], 2);
            }
            if ($c['credito'] === (string) $it['conta_balanco']) {
                $s = bcsub($s, (string) $p['valor'], 2);
            }
        }

        return $s;
    }
}
