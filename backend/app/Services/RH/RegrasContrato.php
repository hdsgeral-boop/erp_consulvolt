<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoTrabalho;
use Illuminate\Support\Collection;

/**
 * Regras do contrato partilhadas pelo processamento, pela efectividade, pela escala, pelos empréstimos e pelo portal
 * (Versão Actual: js/modules/rh/regras_salariais.js:80-115, contrato_estado.js:88-133 e assiduidade.js:135-226).
 *
 *   - contrato EM VIGOR num mês (contratoActivo): dentro das datas e ACTIVO, ou SUSPENSO fora do período da suspensão;
 *     o CESSADO só vale até à data da cessação (que é a data de fim do contrato);
 *   - contrato VIGENTE (contratoVigente): o que abrange a data, mesmo suspenso ou cessado — só para consulta;
 *   - horário do contrato (1 período = 2 picagens, intervalo fixo descontado; 2 períodos = 4 picagens, intervalo picado).
 *
 * Melhorias sobre o legado novo:
 *   - o legado testava a suspensão só no dia 1 do mês: uma suspensão que começasse a dia 2 deixava o mês inteiro a ser
 *     pago, e uma que acabasse a dia 3 tirava o colaborador do mês inteiro. Aqui o contrato fica fora do processamento
 *     quando a suspensão cobre TODOS os dias do contrato nesse mês; numa suspensão parcial entra, com aviso, e a
 *     efectividade não conta faltas nos dias suspensos;
 *   - o legado excluía o contrato CESSADO também no mês da cessação (os dias trabalhados até à cessação ficavam por
 *     pagar); aqui entra nesse mês, com aviso, e deixa de entrar nos meses seguintes (a data de fim é a da cessação).
 */
final class RegrasContrato
{
    private const HORA = '/^([01]?\d|2[0-3]):([0-5]\d)$/';

    // ───────────── Remunerações e salário base ─────────────

    /** Contrato no formato do MotorSalarial (aceita as chaves do legado e as portuguesas). */
    public static function paraMotor(ContratoTrabalho $c): array
    {
        $lista = is_array($c->remuneracoes) ? $c->remuneracoes : (json_decode((string) $c->remuneracoes, true) ?: []);

        return ['dias' => $c->dias_contrato_mes, 'horas' => $c->horas_por_dia, 'remuneracoes' => array_values(array_filter(array_map(fn ($r) => [
            'infotipo_id' => (int) ($r['infotype_id'] ?? $r['infotipo_id'] ?? 0), 'valor_mes' => $r['value_month'] ?? $r['valor_mes'] ?? null,
            'valor_dia' => $r['value_per_day'] ?? $r['valor_dia'] ?? null], $lista), fn ($r) => $r['infotipo_id'] > 0))];
    }

    /** Salário base (SB) = soma mensal das remunerações do contrato (contrato_estado.js:89-95; emprestimos.js:144-152). */
    public static function salarioBase(?ContratoTrabalho $c): string
    {
        if (! $c) {
            return '0.00';
        }
        $ctr = self::paraMotor($c);

        return array_reduce($ctr['remuneracoes'], fn (string $s, array $r) => bcadd($s, MotorSalarial::mensal($r, $ctr), 2), '0.00');
    }

    // ───────────── Estado em vigor ─────────────

    /** A suspensão produz efeito no dia? (suspensoEm, contrato_estado.js:123) Sem período = suspenso sempre. */
    public static function suspensoEm(ContratoTrabalho $c, string $dia): bool
    {
        if ($c->estado !== 'SUSPENSO') {
            return false;
        }
        $s = is_array($c->suspensao ?? null) ? $c->suspensao : [];
        $de = (string) ($s['de'] ?? '');
        $ate = (string) ($s['ate'] ?? '');
        if ($de !== '' && $dia < $de) {
            return false;
        }

        return ! ($ate !== '' && $dia > $ate);
    }

    /** Dias do contrato no intervalo (limitado pelas datas do contrato). @return list<string> */
    public static function diasDoContrato(ContratoTrabalho $c, string $inicio, string $fim): array
    {
        $de = max(array_filter([$inicio, $c->data_inicio?->toDateString()]));
        $ate = min(array_filter([$fim, $c->data_fim?->toDateString()]));

        return $de > $ate ? [] : ServicoCalendarioRH::dias($de, $ate);
    }

    /** Dias suspensos do contrato no intervalo. @return list<string> */
    public static function diasSuspensos(ContratoTrabalho $c, string $inicio, string $fim): array
    {
        return $c->estado === 'SUSPENSO' ? array_values(array_filter(self::diasDoContrato($c, $inicio, $fim), fn ($d) => self::suspensoEm($c, $d))) : [];
    }

    /** O contrato abrange (pelas datas) o intervalo? */
    public static function abrange(ContratoTrabalho $c, string $inicio, string $fim): bool
    {
        return (! $c->data_inicio || $c->data_inicio->toDateString() <= $fim) && (! $c->data_fim || $c->data_fim->toDateString() >= $inicio);
    }

    /** Contrato que entra no processamento/apuramento do intervalo (contratoActivo, regras_salariais.js:95, com as melhorias acima). */
    public static function emVigor(Collection $contratos, string $inicio, string $fim): ?ContratoTrabalho
    {
        return $contratos->sortBy('id')->values()->first(function (ContratoTrabalho $c) use ($inicio, $fim) {
            if (! self::abrange($c, $inicio, $fim)) {
                return false;
            }

            return match ($c->estado ?? 'ACTIVO') {
                'ACTIVO', 'CESSADO' => true,
                'SUSPENSO' => count(self::diasSuspensos($c, $inicio, $fim)) < count(self::diasDoContrato($c, $inicio, $fim)),
                default => false,   // INACTIVO
            };
        });
    }

    /** Contrato a mostrar (ficha, portal): o em vigor, senão o suspenso ou o cessado que abrange a data, senão o mais recente. */
    public static function vigente(Collection $contratos, string $dia): ?ContratoTrabalho
    {
        $naData = $contratos->filter(fn ($c) => self::abrange($c, $dia, $dia))->sortBy('id')->values();

        return self::emVigor($contratos, $dia, $dia) ?? $naData->firstWhere('estado', 'SUSPENSO') ?? $naData->first()
            ?? $contratos->sortByDesc(fn ($c) => ($c->data_inicio?->toDateString() ?? '').sprintf('%012d', $c->id))->first();
    }

    /** Avisos do contrato no intervalo (suspensão parcial, cessação a meio do mês). @return list<string> */
    public static function avisos(ContratoTrabalho $c, string $inicio, string $fim): array
    {
        $avisos = [];
        $susp = self::diasSuspensos($c, $inicio, $fim);
        if ($susp) {
            $avisos[] = 'Contrato suspenso de '.reset($susp).' a '.end($susp).' ('.count($susp).' dia(s) no mês): ajuste os dias trabalhados.';
        }
        if ($c->estado === 'CESSADO' && $c->data_fim && $c->data_fim->toDateString() < $fim) {
            $avisos[] = 'Contrato cessado em '.$c->data_fim->toDateString().': ajuste os dias trabalhados e lance a compensação acordada, se a houver.';
        }

        return $avisos;
    }

    // ───────────── Horário de trabalho ─────────────

    public static function minutos(mixed $h): ?int
    {
        return is_string($h) && preg_match(self::HORA, trim($h), $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    public static function hhmm(mixed $h): string
    {
        $m = self::minutos($h);

        return $m === null ? '' : sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    /** Horas entre duas horas HH:MM (turno que passa da meia-noite soma 24 h). */
    public static function horasEntre(mixed $de, mixed $ate): ?float
    {
        [$a, $b] = [self::minutos($de), self::minutos($ate)];
        if ($a === null || $b === null) {
            return null;
        }

        return round(($b > $a ? $b - $a : 1440 - $a + $b) / 60, 2);
    }

    /**
     * Horário normalizado (horarioDe, assiduidade.js:148) ou nulo. Com 2 períodos o intervalo é o que decorre entre as
     * picagens do meio; sem as duas picagens do meio passa a 1 período.
     *
     * @return array{entrada: string, saida: string, periodos: int, saida_intervalo: string, entrada_intervalo: string, intervalo_min: int, tolerancia_min: int, dias: list<int>}|null
     */
    public static function horario(mixed $h): ?array
    {
        if (! is_array($h) || self::minutos($h['entrada'] ?? null) === null || self::minutos($h['saida'] ?? null) === null) {
            return null;
        }
        $si = self::hhmm($h['saida_intervalo'] ?? null);
        $ei = self::hhmm($h['entrada_intervalo'] ?? null);
        $dois = (int) ($h['periodos'] ?? 1) === 2 && $si !== '' && $ei !== '';
        $dias = array_values(array_unique(array_filter(array_map('intval', (array) ($h['dias'] ?? [])), fn ($d) => $d >= 0 && $d <= 6)));
        sort($dias);

        return ['entrada' => self::hhmm($h['entrada']), 'saida' => self::hhmm($h['saida']), 'periodos' => $dois ? 2 : 1,
            'saida_intervalo' => $dois ? $si : '', 'entrada_intervalo' => $dois ? $ei : '',
            'intervalo_min' => $dois ? (int) round((self::horasEntre($si, $ei) ?? 0) * 60) : max(0, min(480, (int) ($h['intervalo_min'] ?? 0))),
            'tolerancia_min' => max(0, min(120, (int) ($h['tolerancia_min'] ?? 0))), 'dias' => $dias];
    }

    /** Horas previstas do horário/escala (horasDoHorario, assiduidade.js:169; horasDe, escalas.js:60). */
    public static function horasPrevistas(?array $h): ?float
    {
        if (! $h || self::minutos($h['entrada'] ?? null) === null || self::minutos($h['saida'] ?? null) === null) {
            return null;
        }
        if ((int) ($h['periodos'] ?? 1) === 2 && self::minutos($h['saida_intervalo'] ?? null) !== null && self::minutos($h['entrada_intervalo'] ?? null) !== null) {
            $m = self::horasEntre($h['entrada'], $h['saida_intervalo']);
            $t = self::horasEntre($h['entrada_intervalo'], $h['saida']);

            return $m !== null && $t !== null && $m + $t > 0 ? round($m + $t, 2) : null;
        }
        $liq = (self::horasEntre($h['entrada'], $h['saida']) ?? 0) - (int) ($h['intervalo_min'] ?? 0) / 60;

        return $liq > 0 ? round($liq, 2) : null;
    }

    /**
     * Valida e normaliza o horário recebido no formulário do contrato ou da escala (validar, escalas.js:104).
     *
     * @return array<string, mixed>|null
     */
    public static function validarHorario(?array $h, string $onde = 'O horário'): ?array
    {
        if ($h === null || (($h['entrada'] ?? '') === '' && ($h['saida'] ?? '') === '')) {
            return null;
        }
        if (self::minutos($h['entrada'] ?? null) === null || self::minutos($h['saida'] ?? null) === null) {
            throw new ErroNegocio("{$onde}: indique a entrada e a saída (HH:MM).", 'HORARIO_INVALIDO', 422);
        }
        if ((int) ($h['periodos'] ?? 1) === 2) {
            if (self::minutos($h['saida_intervalo'] ?? null) === null || self::minutos($h['entrada_intervalo'] ?? null) === null) {
                throw new ErroNegocio("{$onde}: no regime de dois períodos indique a saída e a entrada do intervalo (as duas picagens do meio vão juntas).", 'HORARIO_INTERVALO_EM_FALTA', 422);
            }
            $total = self::horasEntre($h['entrada'], $h['saida']);
            $partes = (self::horasEntre($h['entrada'], $h['saida_intervalo']) ?? 0) + (self::horasEntre($h['saida_intervalo'], $h['entrada_intervalo']) ?? 0)
                + (self::horasEntre($h['entrada_intervalo'], $h['saida']) ?? 0);
            if (abs($partes - (float) $total) > 0.01) {   // as picagens do meio têm de cair dentro do turno e pela ordem
                throw new ErroNegocio("{$onde}: as horas do intervalo têm de ficar entre a entrada e a saída, pela ordem.", 'HORARIO_PERIODOS_INVALIDOS', 422);
            }
        }
        $n = self::horario($h);
        if ($n === null || self::horasPrevistas($n) === null) {
            throw new ErroNegocio("{$onde}: o intervalo consome todo o turno.", 'HORARIO_SEM_HORAS', 422);
        }

        return $n;
    }
}
