<?php

namespace App\Support\Cache;

/**
 * Chaves de cache normalizadas. Com CACHE_PREFIX=erp: ficam no Redis como
 *   erp:{empresa_id}:{modulo}:{chave}   (dados de uma empresa — directiva, requisito 3)
 *   erp:utilizador:{id}:{chave}         (dados de um utilizador)
 */
final class ChaveCache
{
    public static function empresa(int $empresaId, string $modulo, string $chave): string
    {
        return "{$empresaId}:{$modulo}:{$chave}";
    }

    public static function utilizador(int $utilizadorId, string $chave): string
    {
        return "utilizador:{$utilizadorId}:{$chave}";
    }
}
