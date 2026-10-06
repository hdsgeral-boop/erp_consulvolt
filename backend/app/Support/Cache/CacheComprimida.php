<?php

namespace App\Support\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Compressão AO NÍVEL DA APLICAÇÃO dos conjuntos grandes em cache (plano de contas, catálogo de produtos).
 *
 * Porque não Redis::OPT_COMPRESSION na ligação «cache»: comprime TODOS os valores, incluindo o «1» que
 * Cache::add('empresas:versao', 1) grava antes do INCRBY — o Redis responde «ERR value is not an integer», o phpredis
 * devolve false sem lançar e a versão nunca sobe (invalidação silenciosamente perdida). O RateLimiter do Laravel 12
 * protege-se (withoutSerializationOrCompression); o código da aplicação não. Além disso a extensão phpredis da imagem
 * não foi compilada com LZ4/ZSTD/LZF/ZLIB (docs/arquitetura/CACHE.md, §Compressão).
 *
 * Envelope: MARCADOR + gzcompress(serialize(valor)). Leitura retrocompatível: um valor sem o marcador (formato antigo,
 * ou abaixo do limiar) é devolvido tal como está. Um envelope corrompido conta como «não está em cache» e é
 * recalculado. A desserialização não aceita objectos (allowed_classes=false): só arrays/escalares.
 * A chave é a mesma de sempre (ChaveCache) — invalidações com Cache::forget continuam a funcionar sem alterações.
 */
final class CacheComprimida
{
    /** Versão do formato no próprio valor: muda se um dia mudar o algoritmo (ex.: erpgz2 com zstd). */
    public const MARCADOR = "\x00erpgz1:";

    /**
     * Como Cache::remember, mas grava comprimido se o valor serializado passar do limiar.
     *
     * @template T
     *
     * @param  Closure(): T  $calcular
     * @return T
     */
    public static function lembrar(string $chave, int $ttl, Closure $calcular): mixed
    {
        $guardado = Cache::get($chave);
        if ($guardado !== null) {
            $valor = self::abrir($guardado);
            if ($valor !== null) {
                return $valor;
            }
        }

        $valor = $calcular();
        Cache::put($chave, self::embrulhar($valor), $ttl);

        return $valor;
    }

    /** Valor a gravar: o próprio valor (pequeno) ou o envelope comprimido. */
    public static function embrulhar(mixed $valor): mixed
    {
        $serializado = serialize($valor);
        if (strlen($serializado) < (int) config('erp.cache.comprimir_acima_de_bytes', 8192) || ! function_exists('gzcompress')) {
            return $valor;
        }
        $comprimido = gzcompress($serializado, 6);

        return $comprimido === false ? $valor : self::MARCADOR.$comprimido;
    }

    /** Valor lido: desembrulha o envelope; null se estiver corrompido (o chamador recalcula). */
    public static function abrir(mixed $guardado): mixed
    {
        if (! is_string($guardado) || ! str_starts_with($guardado, self::MARCADOR)) {
            return $guardado;
        }
        $serializado = @gzuncompress(substr($guardado, strlen(self::MARCADOR)));
        if ($serializado === false) {
            return null;
        }
        $valor = @unserialize($serializado, ['allowed_classes' => false]);

        return ($valor === false && $serializado !== serialize(false)) ? null : $valor;
    }
}
