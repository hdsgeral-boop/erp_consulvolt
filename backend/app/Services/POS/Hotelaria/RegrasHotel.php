<?php

namespace App\Services\POS\Hotelaria;

use App\Models\EstadiaHotel;
use App\Models\TerminalPOS;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Regras horárias da hotelaria por terminal (regras/horaBloqueada/saidaPrevista/propostaTardia, js/hotelaria.js:37-75).
 * As horas são locais (config app.timezone). Valores por omissão do legado: entrada 14:00, saída 12:00, tolerância 60 min,
 * bloqueio da venda à hora activo entre as 21:00 e as 08:00.
 */
final class RegrasHotel
{
    /** @return array{entrada: string, saida: string, tolerancia: int, bloqueio: bool, de: string, ate: string} */
    public static function doTerminal(?TerminalPOS $t): array
    {
        return [
            'entrada' => $t?->hotel_hora_entrada ?: '14:00', 'saida' => $t?->hotel_hora_saida ?: '12:00',
            'tolerancia' => $t?->hotel_tolerancia_atraso_min !== null ? (int) $t->hotel_tolerancia_atraso_min : 60,
            'bloqueio' => $t?->hotel_bloco_horas !== false, 'de' => $t?->hotel_bloco_horas_de ?: '21:00', 'ate' => $t?->hotel_bloco_horas_ate ?: '08:00',
        ];
    }

    public static function local(CarbonInterface $d): Carbon
    {
        return Carbon::instance($d)->setTimezone(config('app.timezone'));
    }

    /** Venda à hora bloqueada na janela [de, ate[ (que pode atravessar a meia-noite); de = ate desliga o bloqueio. */
    public static function horaBloqueada(CarbonInterface $data, array $rg): bool
    {
        if (! $rg['bloqueio']) {
            return false;
        }
        $d = self::local($data);
        $m = $d->hour * 60 + $d->minute;
        $de = self::minutos($rg['de']);
        $ate = self::minutos($rg['ate']);
        if ($de === $ate) {
            return false;
        }

        return $de > $ate ? ($m >= $de || $m < $ate) : ($m >= $de && $m < $ate);
    }

    /** À hora: entrada + horas; à diária: dia da entrada + n.º de diárias (mín. 1), à hora de saída do terminal. */
    public static function saidaPrevista(CarbonInterface $entrada, string $modo, string $quantidade, array $rg): Carbon
    {
        $e = self::local($entrada);
        if ($modo === 'HORA') {
            return $e->copy()->addSeconds((int) round((float) $quantidade * 3600));
        }
        [$h, $m] = array_map('intval', explode(':', $rg['saida']) + [0, 0]);

        return $e->copy()->startOfDay()->addDays(max(1, (int) round((float) $quantidade)))->setTime($h, $m);
    }

    /**
     * Saída tardia: depois da saída prevista + tolerância propõe-se o recálculo — à hora, as horas decorridas (arredondadas
     * para cima); à diária, mais uma diária por cada 24 h (ou fracção) de atraso.
     *
     * @return array{quantidade: string, extra: string}|null
     */
    public static function propostaAtraso(EstadiaHotel $e, array $rg, CarbonInterface $agora): ?array
    {
        $prevista = self::local($e->saida_prevista_em);
        if ($agora->getTimestamp() <= $prevista->copy()->addMinutes($rg['tolerancia'])->getTimestamp()) {
            return null;
        }
        $q = number_format((float) $e->quantidade, 3, '.', '');
        if ($e->modo === 'HORA') {
            $horas = number_format(ceil(($agora->getTimestamp() - $e->entrada_em->getTimestamp()) / 3600), 3, '.', '');

            return bccomp($horas, $q, 3) > 0 ? ['quantidade' => $horas, 'extra' => bcsub($horas, $q, 3)] : null;
        }
        $extra = (int) ceil(($agora->getTimestamp() - $prevista->getTimestamp()) / 86400);

        return $extra > 0 ? ['quantidade' => bcadd($q, (string) $extra, 3), 'extra' => number_format($extra, 3, '.', '')] : null;
    }

    /** «2 diária(s)» / «3,5 hora(s)» (unidadeRot, js/hotelaria.js:30). */
    public static function unidade(string $modo, string|float $quantidade): string
    {
        $q = rtrim(rtrim(number_format((float) $quantidade, 3, ',', ''), '0'), ',');

        return $modo === 'HORA' ? "{$q} hora(s)" : "{$q} diária(s)";
    }

    public static function kz(string|float $v): string
    {
        return number_format((float) $v, 2, ',', ' ').' Kz';
    }

    public static function dataHora(CarbonInterface $d): string
    {
        return self::local($d)->format('d/m/Y H:i');
    }

    private static function minutos(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);

        return $h * 60 + $m;
    }
}
