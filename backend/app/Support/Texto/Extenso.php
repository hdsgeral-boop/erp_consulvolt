<?php

namespace App\Support\Texto;

/**
 * Valores e datas por extenso em português (declarações e documentos do RH).
 * Ex.: 1 234 567,50 → «um milhão duzentos e trinta e quatro mil quinhentos e sessenta e sete kwanzas e cinquenta cêntimos».
 */
final class Extenso
{
    private const UNIDADES = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'catorze', 'quinze',
        'dezasseis', 'dezassete', 'dezoito', 'dezanove'];

    private const DEZENAS = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];

    private const CENTENAS = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

    private const ESCALAS = [[1, 'mil', 'mil'], [2, 'milhão', 'milhões'], [3, 'mil milhões', 'mil milhões'], [4, 'bilião', 'biliões']];

    private const MESES = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public static function kwanzas(string|float|int $valor): string
    {
        $v = number_format(abs((float) $valor), 2, '.', '');
        [$inteiro, $centimos] = explode('.', $v);
        $inteiro = (int) $inteiro;
        $centimos = (int) $centimos;
        $partes = [];
        if ($inteiro > 0) {
            $txt = self::numero($inteiro);
            // «um milhão de kwanzas», «dois milhões de kwanzas» (quando termina em milhões exactos)
            $de = $inteiro >= 1000000 && $inteiro % 1000000 === 0 ? ' de' : '';
            $partes[] = $txt.$de.($inteiro === 1 ? ' kwanza' : ' kwanzas');
        }
        if ($centimos > 0) {
            $partes[] = self::numero($centimos).($centimos === 1 ? ' cêntimo' : ' cêntimos');
        }

        return $partes ? implode(' e ', $partes) : 'zero kwanzas';
    }

    public static function numero(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $grupos = [];   // [escala => valor 1..999]
        for ($i = 0; $n > 0; $i++, $n = intdiv($n, 1000)) {
            if ($n % 1000) {
                $grupos[$i] = $n % 1000;
            }
        }
        krsort($grupos);
        $saida = '';
        $ultimaEscala = min(array_keys($grupos));
        foreach ($grupos as $i => $g) {
            $t = match (true) {
                $i === 0 => self::centenas($g),
                $i === 1 => $g === 1 ? 'mil' : self::centenas($g).' mil',
                default => self::centenas($g).' '.($g === 1 ? self::ESCALAS[$i - 1][1] : self::ESCALAS[$i - 1][2]),
            };
            if ($saida === '') {
                $saida = $t;

                continue;
            }
            // «e» antes do último grupo se for < 100 ou centena exacta: «mil e cem», «dois mil e vinte», «um milhão e quinhentos mil»
            $saida .= ($i === $ultimaEscala && ($g < 100 || $g % 100 === 0) ? ' e ' : ' ').$t;
        }

        return $saida;
    }

    private static function centenas(int $n): string
    {
        if ($n === 100) {
            return 'cem';
        }
        $c = intdiv($n, 100);
        $r = $n % 100;
        $partes = [];
        if ($c) {
            $partes[] = self::CENTENAS[$c];
        }
        if ($r) {
            $partes[] = $r < 20 ? self::UNIDADES[$r] : self::DEZENAS[intdiv($r, 10)].($r % 10 ? ' e '.self::UNIDADES[$r % 10] : '');
        }

        return implode(' e ', $partes);
    }

    /** 2026-09-30 → «30 de Setembro de 2026» */
    public static function data(?string $data): string
    {
        if (! $data || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $data, $m)) {
            return '';
        }

        return (int) $m[3].' de '.self::MESES[(int) $m[2]].' de '.$m[1];
    }
}
