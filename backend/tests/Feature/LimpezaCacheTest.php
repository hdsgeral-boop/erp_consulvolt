<?php

namespace Tests\Feature;

use App\Support\Cache\LimpezaCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R1 — limpeza da cache depois da migração do legado, com o Redis real do ambiente (bases lógicas 7 e 8, que nenhum
 * ambiente usa): numa base partilhada com filas/locks só se apagam as chaves da cache; numa dedicada faz-se flush.
 */
final class LimpezaCacheTest extends TestCase
{
    private function usarRedis(string $baseDefault, string $baseCache): void
    {
        config(['cache.default' => 'redis', 'database.redis.default.database' => $baseDefault, 'database.redis.cache.database' => $baseCache]);
        app('redis')->purge('default');
        app('redis')->purge('cache');
        app('cache')->forgetDriver('redis');
        try {
            Redis::connection('cache')->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis indisponível: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach (['7', '8'] as $base) {
                config(['database.redis.default.database' => $base]);
                app('redis')->purge('default');
                Redis::connection('default')->del('teste_r1:fila', 'teste_r1:lock');
            }
        } catch (\Throwable) {
        } finally {
            parent::tearDown();
        }
    }

    #[Test]
    public function base_partilhada_apaga_so_as_chaves_da_cache(): void
    {
        $this->usarRedis('7', '7');
        Cache::put('utilizador:1:empresas:v1', [1, 2], 3600);
        Redis::connection('default')->set('teste_r1:fila', 'job');   // fila/lock na mesma base lógica

        $this->assertStringContainsString('por prefixo', LimpezaCache::limparTudo());
        $this->assertNull(Cache::get('utilizador:1:empresas:v1'));
        $this->assertSame('job', Redis::connection('default')->get('teste_r1:fila'));
    }

    #[Test]
    public function base_dedicada_faz_flush(): void
    {
        $this->usarRedis('7', '8');
        Cache::put('utilizador:1:empresas:v1', [1, 2], 3600);
        Redis::connection('default')->set('teste_r1:lock', 'x');

        $this->assertStringContainsString('dedicada', LimpezaCache::limparTudo());
        $this->assertNull(Cache::get('utilizador:1:empresas:v1'));
        $this->assertSame('x', Redis::connection('default')->get('teste_r1:lock'));
    }
}
