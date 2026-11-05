<?php

namespace App\Services\Projetos;

/**
 * Fórmulas do separador «Financeiro» (PMBOK, valor acrescentado — EVM). Funções puras, em float; cada indicador sem
 * base de cálculo fiável devolve null (o ecrã esconde-o com explicação em vez de mostrar um valor inventado).
 *
 *   BAC = orçamento no termo (linhas de orçamento do projecto)        PV = BAC × % planeada até hoje (curva S prevista)
 *   EV  = BAC × % de execução (progresso ponderado das tarefas)       AC = custo real acumulado
 *   CV = EV − AC · SV = EV − PV · CPI = EV ÷ AC · SPI = EV ÷ PV
 *   EAC = BAC ÷ CPI · ETC = EAC − AC · VAC = BAC − EAC · TCPI = (BAC − EV) ÷ (BAC − AC)
 *   Receita reconhecida = venda global × % de execução · Sobre/sub-facturação = facturado − reconhecida
 *   Margem bruta = facturado − custo real · Retenção = valor facturado × % de retenção
 */
final class FormulasFinanceirasProjeto
{
    /**
     * @return array{bac: float, pv: ?float, ev: ?float, ac: float, cv: ?float, sv: ?float, cpi: ?float, spi: ?float, eac: ?float, etc: ?float, vac: ?float, tcpi: ?float}
     */
    public static function evm(float $bac, ?float $pctPlaneada, ?float $pctExecucao, float $ac): array
    {
        $pv = $bac > 0 && $pctPlaneada !== null ? $bac * $pctPlaneada / 100 : null;
        $ev = $bac > 0 && $pctExecucao !== null ? $bac * $pctExecucao / 100 : null;
        $cpi = $ev !== null && $ac > 0 ? $ev / $ac : null;
        $spi = $ev !== null && $pv !== null && $pv > 0 ? $ev / $pv : null;
        $eac = $cpi !== null && $cpi > 0 ? $bac / $cpi : null;

        return [
            'bac' => self::r($bac), 'pv' => self::n($pv), 'ev' => self::n($ev), 'ac' => self::r($ac),
            'cv' => $ev !== null ? self::r($ev - $ac) : null, 'sv' => $ev !== null && $pv !== null ? self::r($ev - $pv) : null,
            'cpi' => self::n($cpi, 3), 'spi' => self::n($spi, 3),
            'eac' => self::n($eac), 'etc' => $eac !== null ? self::r($eac - $ac) : null, 'vac' => $eac !== null ? self::r($bac - $eac) : null,
            'tcpi' => $ev !== null && $bac - $ac > 0 ? self::n(($bac - $ev) / ($bac - $ac), 3) : null,
        ];
    }

    /** % do orçamento planeado até ao mês de hoje, a partir da curva S acumulada (último ponto ≤ mês actual). */
    public static function pctPlaneada(array $meses, array $previstoAcumulado, string $hoje): ?float
    {
        $total = end($previstoAcumulado);
        if (! $meses || ! $total || (float) $total <= 0) {
            return null;
        }
        $i = null;
        foreach ($meses as $k => $m) {
            if ($m <= substr($hoje, 0, 7)) {
                $i = $k;
            }
        }

        return $i === null ? 0.0 : round((float) $previstoAcumulado[$i] / (float) $total * 100, 4);
    }

    /** @return array{reconhecida: float, sobre_facturacao: float} sobre-facturação > 0: facturou-se mais do que o executado. */
    public static function receita(float $vendaGlobal, ?float $pctExecucao, float $facturado): array
    {
        $rec = $pctExecucao !== null ? $vendaGlobal * $pctExecucao / 100 : 0.0;

        return ['reconhecida' => self::r($rec), 'sobre_facturacao' => self::r($facturado - $rec)];
    }

    public static function retencao(float $valor, float $pct): float
    {
        return self::r($valor * max(0, min(100, $pct)) / 100);
    }

    /** @return list<array{codigo: string, nivel: string, texto: string}> */
    public static function alertas(array $e, float $consumoPct): array
    {
        $a = [];
        if ($consumoPct > 100) {
            $a[] = ['codigo' => 'ORCAMENTO_EXCEDIDO', 'nivel' => 'erro', 'texto' => 'O custo real já ultrapassa o orçamento ('.round($consumoPct, 1).' %).'];
        }
        if ($e['cpi'] !== null && $e['cpi'] < 0.9) {
            $a[] = ['codigo' => 'CPI_BAIXO', 'nivel' => $e['cpi'] < 0.8 ? 'erro' : 'aviso', 'texto' => 'Derrapagem de custo: CPI de '.$e['cpi'].' (cada Kz gasto rende menos de 0,90 Kz de trabalho).'];
        }
        if ($e['spi'] !== null && $e['spi'] < 0.9) {
            $a[] = ['codigo' => 'SPI_BAIXO', 'nivel' => $e['spi'] < 0.8 ? 'erro' : 'aviso', 'texto' => 'Atraso face ao plano: SPI de '.$e['spi'].'.'];
        }
        if ($e['vac'] !== null && $e['vac'] < 0) {
            $a[] = ['codigo' => 'EAC_ACIMA_BAC', 'nivel' => 'aviso', 'texto' => 'A estimativa no termo (EAC) excede o orçamento em '.number_format(abs($e['vac']), 2, ',', ' ').' Kz.'];
        }

        return $a;
    }

    private static function r(float $v): float
    {
        return round($v, 2);
    }

    private static function n(?float $v, int $casas = 2): ?float
    {
        return $v === null ? null : round($v, $casas);
    }
}
