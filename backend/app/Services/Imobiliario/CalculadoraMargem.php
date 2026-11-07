<?php

namespace App\Services\Imobiliario;

use App\Services\Vendas\CalculadoraDocumento;

/**
 * Margem do subarrendamento por fracção (margem do legado, SB:167-224): o que a empresa factura aos subarrendatários menos o que
 * deve pagar aos senhorios, sobre o `total_facturar` (antes de retenção) das rendas não anuladas do intervalo [de, ate] (períodos AAAA-MM).
 * Correcção (P3): cada renda é mensalizada — o seu total reparte-se pelos meses que cobre — para que uma renda tomada anual não
 * distorça a margem contra subarrendamentos mensais. Com `$mensalizar = false` reproduz o legado (soma pelo período de início, sem
 * dividir), que fica como fixture. `taxa = (recebe − paga) / paga` em % com 1 casa; sem pago, null. Função pura, em decimal exacto.
 */
final class CalculadoraMargem
{
    /**
     * @param  list<array{fracao_id: int, posicao: string, periodo: string, meses: int, total: string, contrato: string}>  $rendas  só as não anuladas
     * @return array{linhas: list<array<string, mixed>>, totais: array<string, string>, negativas: int, vazias: int}
     */
    public static function calcular(array $rendas, string $de, string $ate, bool $mensalizar = true): array
    {
        /** @var array<int, array<string, mixed>> $por */
        $por = [];
        foreach ($rendas as $r) {
            $f = (int) $r['fracao_id'];
            $por[$f] ??= ['fracao_id' => $f, 'paga' => '0', 'recebe' => '0', 'tomadas' => [], 'subs' => [], 'meses' => []];
            $lado = $r['posicao'] === 'INQUILINO' ? 'paga' : 'recebe';
            $lista = $lado === 'paga' ? 'tomadas' : 'subs';
            if (! in_array($r['contrato'], $por[$f][$lista], true)) {
                $por[$f][$lista][] = $r['contrato'];
            }
            foreach (self::partes($r, $de, $ate, $mensalizar) as $periodo => $valor) {
                $por[$f][$lado] = bcadd((string) $por[$f][$lado], $valor, 8);
                $por[$f]['meses'][$periodo][$lado] = bcadd($por[$f]['meses'][$periodo][$lado] ?? '0', $valor, 8);
            }
        }
        $linhas = [];
        $tp = $tr = '0';
        foreach ($por as $f) {
            $paga = CalculadoraDocumento::arredondar((string) $f['paga']);
            $recebe = CalculadoraDocumento::arredondar((string) $f['recebe']);
            $margem = bcsub($recebe, $paga, 2);
            ksort($f['meses']);
            $periodos = [];
            foreach ($f['meses'] as $p => $m) {
                $mp = CalculadoraDocumento::arredondar($m['paga'] ?? '0');
                $mr = CalculadoraDocumento::arredondar($m['recebe'] ?? '0');
                $periodos[] = ['periodo' => (string) $p, 'paga' => $mp, 'recebe' => $mr, 'margem' => bcsub($mr, $mp, 2)];
            }
            $linhas[] = ['fracao_id' => $f['fracao_id'], 'paga' => $paga, 'recebe' => $recebe, 'margem' => $margem,
                'taxa' => bccomp($paga, '0', 2) > 0 ? self::taxa($margem, $paga) : null, 'tomadas' => $f['tomadas'], 'subs' => $f['subs'], 'periodos' => $periodos,
                'negativa' => bccomp($paga, '0', 2) > 0 && bccomp($margem, '0', 2) < 0, 'vazia' => bccomp($paga, '0', 2) > 0 && $f['subs'] === []];
            $tp = bcadd($tp, $paga, 2);
            $tr = bcadd($tr, $recebe, 2);
        }
        usort($linhas, fn ($a, $b) => bccomp($a['margem'], $b['margem'], 2) ?: $a['fracao_id'] <=> $b['fracao_id']);   // piores primeiro

        return ['linhas' => $linhas, 'totais' => ['paga' => $tp, 'recebe' => $tr, 'margem' => bcsub($tr, $tp, 2)],
            'negativas' => count(array_filter($linhas, fn ($l) => $l['negativa'])), 'vazias' => count(array_filter($linhas, fn ($l) => $l['vazia']))];
    }

    /** Partes (por mês) do total de uma renda que caem dentro de [de, ate]. @return array<string, string> */
    private static function partes(array $r, string $de, string $ate, bool $mensalizar): array
    {
        if (! $mensalizar) {   // legado: a renda inteira, no período em que começa
            return $r['periodo'] >= $de && $r['periodo'] <= $ate ? [$r['periodo'] => (string) $r['total']] : [];
        }
        $n = max(1, (int) $r['meses']);
        $parte = bcdiv((string) $r['total'], (string) $n, 8);
        $saida = [];
        for ($i = 0; $i < $n; $i++) {
            $p = CalculadoraPeriodos::somarMeses($r['periodo'], $i);
            if ($p >= $de && $p <= $ate) {
                $saida[$p] = $parte;
            }
        }

        return $saida;
    }

    private static function taxa(string $margem, string $paga): string
    {
        return bcdiv(bcadd(bcmul(bcdiv(bcmul($margem, '100', 8), $paga, 8), '1', 8), bccomp($margem, '0', 2) >= 0 ? '0.05' : '-0.05', 8), '1', 1);
    }
}
