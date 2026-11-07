<?php

namespace App\Services\Imobiliario;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Cálculos puros das reservas de áreas comuns (calcularValor, horasEntre, marcarPrazo, previsaoCancelamento e estadoCaucao do legado),
 * em bcmath e sem acesso à base de dados: dinheiro nunca passa por float. Arredondamento «meio para cima» ao cêntimo.
 */
final class CalculadoraReserva
{
    /** Duração em horas com uma casa decimal, arredondada aos 6 minutos (150 min = 2,5 h). Devolve null se as horas são inválidas ou o fim não é depois do início. */
    public static function horasEntre(?string $inicio, ?string $fim): ?string
    {
        $a = CatalogoParqueamento::minutos($inicio);
        $b = CatalogoParqueamento::minutos($fim);
        if ($a === null || $b === null || $b <= $a) {
            return null;
        }
        $decimas = (int) round(($b - $a) / 6, 0, PHP_ROUND_HALF_UP);

        return number_format($decimas / 10, 1, '.', '');
    }

    /**
     * Preço da reserva: `preço × max(1, ceil(horas ÷ horas da unidade))`; o preço fixo cobra uma só vez. Espaço sem regime pago não custa nada.
     *
     * @return array{valor: string, caucao: string, unidades: int, detalhe: string}
     */
    public static function valor(string $regime, string|float $preco, string $unidade, string|float $caucao, string $horas): array
    {
        if (! (CatalogoParqueamento::REGIMES[$regime]['paga'] ?? false)) {
            return ['valor' => '0.00', 'caucao' => '0.00', 'unidades' => 0, 'detalhe' => 'Sem custo'];
        }
        $u = CatalogoParqueamento::UNIDADES_PRECO[$unidade] ?? CatalogoParqueamento::UNIDADES_PRECO['HORA'];
        $unidades = $u['horas'] > 0 ? max(1, (int) ceil(round((float) $horas / $u['horas'], 6))) : 1;
        $valor = bcmul(number_format((float) $preco, 2, '.', ''), (string) $unidades, 2);
        $detalhe = $u['horas'] > 0 ? $unidades.' × '.preg_replace('/^por /', '', $u['rot']).' ('.rtrim(rtrim($horas, '0'), '.').' h)' : 'valor fixo';

        return ['valor' => $valor, 'caucao' => number_format((float) $caucao, 2, '.', ''), 'unidades' => $unidades, 'detalhe' => $detalhe];
    }

    /**
     * Prazo para pagar: agora + horas do espaço (mínimo 1, 48 por omissão), nunca depois do início do evento (hora local).
     *
     * @param  string  $data  AAAA-MM-DD do evento
     */
    public static function prazoPagamento(CarbonInterface $agora, ?int $horasPrazo, string $data, string $horaInicio): CarbonImmutable
    {
        $horas = max(1, (int) ($horasPrazo ?: 48));
        $prazo = CarbonImmutable::instance($agora)->addHours($horas);
        $min = CatalogoParqueamento::minutos($horaInicio);
        if ($min === null) {
            return $prazo;
        }
        $limite = CarbonImmutable::parse($data.' 00:00:00', $agora->getTimezone())->addMinutes($min);

        return $prazo->lessThan($limite) ? $prazo : $limite;
    }

    /**
     * Cancelamento com escala: com `cancel_dias` de antecedência (ou mais) devolve-se tudo; depois, `cancel_pct` % do recebido.
     *
     * @param  int  $faltam  dias de calendário até à reserva (pode ser negativo)
     * @return array{pct: int, devolve: string, retem: string}
     */
    public static function reembolso(string $recebido, int $faltam, int $cancelDias, int $cancelPct): array
    {
        $pct = $faltam >= max(0, $cancelDias) ? 100 : min(100, max(0, $cancelPct));
        $devolve = self::pctDe($recebido, $pct);

        return ['pct' => $pct, 'devolve' => $devolve, 'retem' => bcsub(number_format((float) $recebido, 2, '.', ''), $devolve, 2)];
    }

    /** `valor × pct ÷ 100`, meio para cima ao cêntimo. */
    public static function pctDe(string|float $valor, int $pct): string
    {
        $bruto = bcdiv(bcmul(number_format((float) $valor, 2, '.', ''), (string) $pct, 4), '100', 6);

        return bcadd(bcadd($bruto, '0.005', 6), '0', 2);
    }

    /** Texto da previsão do cancelamento. */
    public static function explicacao(string $recebido, int $faltam, int $cancelDias, int $pct): string
    {
        if (bccomp($recebido, '0', 2) <= 0) {
            return 'Nada foi pago: não há nada a devolver.';
        }
        $limite = max(0, $cancelDias);

        return $faltam >= $limite ? "Faltam {$faltam} dia(s) e o espaço devolve tudo até {$limite} dia(s) antes."
            : "Faltam só {$faltam} dia(s): dentro dos {$limite} dia(s) do regulamento devolve-se {$pct}%.";
    }

    /** Estado da caução a partir dos totais do ledger. */
    public static function estadoCaucao(float $caucao, float $recebida, float $retida, float $devolvida): string
    {
        return match (true) {
            $caucao <= 0 => 'NAO_APLICA',
            $recebida <= 0 => 'POR_RECEBER',
            $retida > 0 && $devolvida > 0 => 'RETIDA_PARCIAL',
            $retida > 0 => 'RETIDA_TOTAL',
            $devolvida > 0 => 'DEVOLVIDA',
            default => 'EM_PODER',
        };
    }

    /** Duas janelas semiabertas [a,b) e [c,d) sobrepõem-se (encostar o fim ao início não conflita). */
    public static function sobrepoe(int $a, int $b, int $c, int $d): bool
    {
        return $a < $d && $b > $c;
    }
}
