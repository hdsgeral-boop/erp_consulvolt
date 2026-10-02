<?php

namespace App\Support\Cache;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Batimentos do scheduler e do worker (R11), para o /api/saude saber se os processos em segundo plano estão vivos
 * (antes só via o tamanho das filas). Sem registo (ambiente sem scheduler, ou logo depois de um cache:clear) é só
 * informativo; um batimento mais antigo do que o limite é FALHA.
 */
final class Batimentos
{
    public const SCHEDULER = 'scheduler';

    public const WORKER = 'worker';

    /** Idade máxima, em segundos: o scheduler bate a cada minuto; o worker a cada 5 min, na fila «baixa» (a última a ser servida). */
    public const LIMITES = [self::SCHEDULER => 300, self::WORKER => 900];

    public static function registar(string $processo): void
    {
        Cache::forever("batimento:{$processo}", now()->timestamp);
    }

    /** Resumo legível; lança RuntimeException se algum batimento estiver atrasado. */
    public static function estado(): string
    {
        $partes = [];
        $atrasados = [];
        foreach (self::LIMITES as $processo => $limite) {
            $ultimo = Cache::get("batimento:{$processo}");
            if ($ultimo === null) {
                $partes[] = "{$processo}: sem registo";

                continue;
            }
            $idade = max(0, now()->timestamp - (int) $ultimo);
            $partes[] = "{$processo}: há {$idade} s";
            if ($idade > $limite) {
                $atrasados[] = "{$processo} sem batimento há {$idade} s (limite {$limite} s)";
            }
        }
        if ($atrasados) {
            throw new RuntimeException(implode('; ', $atrasados));
        }

        return implode('; ', $partes);
    }
}
