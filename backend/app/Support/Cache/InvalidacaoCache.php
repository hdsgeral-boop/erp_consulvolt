<?php

namespace App\Support\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Invalidação de cache em duplo tempo (R2): imediata e de novo DEPOIS do COMMIT.
 * Só a imediata não chega — dentro da transacção, um pedido concorrente ainda vê os dados antigos (são os
 * confirmados) e volta a guardá-los em cache até ao TTL: conta nova recusada 24 h, conta passada a totalizadora
 * aceite 24 h, acesso a uma empresa retirado mantido até 1 h. Sem transacção aberta, o afterCommit corre já.
 */
final class InvalidacaoCache
{
    /** Executa a invalidação agora e repete-a depois do commit da transacção em curso. */
    public static function agoraEDepoisDoCommit(Closure $invalidar): void
    {
        $invalidar();
        DB::afterCommit($invalidar);
    }

    public static function esquecer(string $chave): void
    {
        self::agoraEDepoisDoCommit(fn () => Cache::forget($chave));
    }
}
