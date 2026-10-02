<?php

namespace App\Support\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Limpeza total da cache depois de operações que reescrevem a base sem eventos de model (migração do legado com
 * TRUNCATE … RESTART IDENTITY, que reutiliza ids): sem isto, durante até 1 h um utilizador novo com o id de um antigo
 * herdava a lista de empresas acessíveis do antigo (fuga entre empresas), e o plano de contas/catálogo ficavam antigos.
 *
 * Guarda (como PrepararE2E): o flush do Redis limpa a base lógica INTEIRA. Só se faz flush quando a base lógica da
 * cache é dedicada (≠ da ligação default, onde estão filas, locks e rate limiting); se for partilhada, apagam-se
 * apenas as chaves com o prefixo da cache, por SCAN (sem bloquear o Redis como o KEYS).
 */
final class LimpezaCache
{
    /** @return string descrição do que foi feito (para o relatório/consola) */
    public static function limparTudo(): string
    {
        $store = (string) config('cache.default');
        if (config("cache.stores.{$store}.driver") !== 'redis') {
            Cache::flush();

            return "cache «{$store}» limpa";
        }
        $ligacao = (string) config("cache.stores.{$store}.connection", 'cache');
        $baseCache = (string) config("database.redis.{$ligacao}.database");
        if ($baseCache !== (string) config('database.redis.default.database')) {
            Cache::flush();

            return "cache Redis limpa (base lógica {$baseCache}, dedicada)";
        }

        // base partilhada com filas/locks: só as chaves da cache. O SCAN devolve as chaves com o prefixo da ligação
        // (OPT_PREFIX do phpredis), que o DEL volta a acrescentar — por isso retira-se antes de apagar.
        $prefixoLigacao = (string) config('database.redis.options.prefix', '');
        $padrao = $prefixoLigacao.Cache::getStore()->getPrefix().'*';
        $redis = Redis::connection($ligacao);
        $cliente = $redis->client();
        $apagadas = 0;
        $cursor = null;
        do {
            $chaves = $cliente->scan($cursor, $padrao, 1000);
            if ($chaves) {
                $apagadas += (int) $cliente->del(array_map(fn (string $k) => str_starts_with($k, $prefixoLigacao) ? substr($k, strlen($prefixoLigacao)) : $k, $chaves));
            }
        } while ($cursor);

        return "cache Redis limpa por prefixo ({$apagadas} chaves; base lógica {$baseCache} partilhada)";
    }
}
