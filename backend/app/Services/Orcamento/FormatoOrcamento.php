<?php

namespace App\Services\Orcamento;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Formato das respostas do Orçamento (ADR-064): os valores monetários saem sempre como texto decimal com 2 casas
 * («1234.50»), como no resto da API. Os cálculos internos do controlo e do planeamento não mudam; a normalização é
 * feita à saída, pelas chaves monetárias conhecidas:
 *   - valor escalar numérico → texto com 2 casas;
 *   - lista ou mapa só de números (p. ex. os 12 «valores» de uma linha, «saldos_fim_mes», a «previsao» mensal) →
 *     todas as folhas em texto com 2 casas;
 *   - qualquer outra coisa (p. ex. o modelo da previsão ou do cenário, que partilham o nome da chave) é percorrida
 *     com as regras normais, sem converter ids, revisões ou percentagens.
 */
final class FormatoOrcamento
{
    /** Chaves cujo conteúdo é dinheiro (escalar, lista ou mapa de valores). */
    public const CHAVES = [
        'valores', 'orcado', 'orcado_inicial', 'orcado_ano', 'realizado', 'total', 'valor', 'valor_excesso', 'valor_orcado', 'valor_consumido',
        'compromissos', 'consumido', 'disponivel', 'excesso', 'desvio', 'desvio_total', 'acumulado', 'saldo_inicial', 'saldos_fim_mes',
        'real', 'real_recente', 'anterior', 'variacao', 'previsao', 'total_12', 'real_ano', 'previsto_ano', 'fecho_estimado',
        'base', 'cenario', 'resultado_base', 'resultado_cenario', 'montante',
    ];

    /** Normaliza recursivamente os valores monetários de uma resposta. */
    public static function normalizar(mixed $dados, array $extra = []): mixed
    {
        return self::percorrer(self::simples($dados), array_merge(self::CHAVES, $extra));
    }

    private static function percorrer(mixed $v, array $chaves): mixed
    {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = is_string($k) && in_array($k, $chaves, true) ? self::monetario($x, $chaves) : self::percorrer($x, $chaves);
            }

            return $v;
        }
        if ($v instanceof \stdClass) {
            foreach (get_object_vars($v) as $k => $x) {
                $v->{$k} = in_array($k, $chaves, true) ? self::monetario($x, $chaves) : self::percorrer($x, $chaves);
            }
        }

        return $v;
    }

    private static function monetario(mixed $v, array $chaves): mixed
    {
        if (self::numero($v)) {
            return self::dinheiro($v);
        }
        if (is_array($v) && $v !== [] && self::soNumeros($v)) {
            array_walk_recursive($v, function (&$x) {
                $x = $x === null ? null : self::dinheiro($x);
            });

            return $v;
        }

        return self::percorrer($v, $chaves);
    }

    private static function soNumeros(array $v): bool
    {
        foreach ($v as $x) {
            if (is_array($x) ? ! self::soNumeros($x) : ! ($x === null || self::numero($x))) {
                return false;
            }
        }

        return true;
    }

    private static function numero(mixed $v): bool
    {
        return is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v) === 1);
    }

    /** Converte modelos, colecções e objectos serializáveis em arrays/stdClass simples (objecto vazio continua objecto). */
    private static function simples(mixed $v): mixed
    {
        if ($v instanceof Arrayable) {
            $v = $v->toArray();
        } elseif ($v instanceof JsonSerializable) {
            $v = $v->jsonSerialize();
        }
        if (is_array($v)) {
            return array_map(fn ($x) => self::simples($x), $v);
        }
        if ($v instanceof \stdClass) {
            $o = new \stdClass;
            foreach (get_object_vars($v) as $k => $x) {
                $o->{$k} = self::simples($x);
            }

            return $o;
        }

        return $v;
    }

    /** Texto decimal com 2 casas, arredondado meio-para-cima (sem «-0.00»). */
    public static function dinheiro(int|float|string $v): string
    {
        if (is_string($v)) {
            $meio = str_starts_with($v, '-') ? '-0.005' : '0.005';
            $r = bcadd(bcadd($v, $meio, 4), '0', 2);
        } else {
            $r = number_format((float) $v, 2, '.', '');
        }

        return $r === '-0.00' ? '0.00' : $r;
    }
}
