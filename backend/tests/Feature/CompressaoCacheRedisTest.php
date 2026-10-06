<?php

namespace Tests\Feature;

use App\Support\Cache\CacheComprimida;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Optimização de memória do Redis (docs/arquitetura/CACHE.md, §Compressão), com o Redis REAL do ambiente nas bases
 * lógicas 10 (cache) e 11 (default), que nenhum ambiente usa:
 *  - prova que a compressão ao nível da ligação (Redis::OPT_COMPRESSION) partiria o INCRBY do RateLimiter e de
 *    Cache::increment — e que a ligação «cache» continua sem compressão;
 *  - a compressão ao nível da aplicação (CacheComprimida) reduz a memória, é retrocompatível e não muda a chave.
 */
final class CompressaoCacheRedisTest extends TestCase
{
    /** @var list<string> chaves (sem prefixo) a apagar no fim */
    private array $chaves = ['teste_compr:limite', 'teste_compr:grande', 'teste_compr:bruto', 'teste_compr:pequeno', 'teste_compr:antigo', 'teste_compr:corrompido', 'teste_compr:versao'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'redis', 'database.redis.cache.database' => '10', 'database.redis.default.database' => '11']);
        app('redis')->purge('cache');
        app('redis')->purge('default');
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
            Redis::connection('cache')->del(array_map(fn ($c) => Cache::getStore()->getPrefix().$c, $this->chaves));
            RateLimiter::clear('teste_compr:rl');
        } catch (\Throwable) {
        } finally {
            parent::tearDown();
        }
    }

    private function chaveBruta(string $chave): string
    {
        return Cache::getStore()->getPrefix().$chave;
    }

    /** @return list<array<string, mixed>> conjunto com a forma do catálogo/plano de contas (chaves repetidas) */
    private function conjuntoGrande(int $n = 1500): array
    {
        return array_map(fn (int $i) => ['id' => $i, 'codigo' => sprintf('%05d', $i), 'descricao' => "Conta de movimento número {$i}",
            'tipo' => 'M', 'codigo_moeda' => null], range(1, $n));
    }

    #[Test]
    public function ligacao_cache_continua_sem_compressao_e_rate_limiter_e_increment_funcionam(): void
    {
        $this->assertSame(\Redis::COMPRESSION_NONE, Redis::connection('cache')->client()->getOption(\Redis::OPT_COMPRESSION));
        $this->assertArrayNotHasKey(\Redis::OPT_COMPRESSION, (array) config('database.redis.cache.options', []));

        // o RateLimiter usa a store por omissão (redis -> ligação cache): add(0) + INCRBY
        $this->assertSame(1, RateLimiter::hit('teste_compr:rl', 60));
        $this->assertSame(2, RateLimiter::hit('teste_compr:rl', 60));
        $this->assertSame(2, RateLimiter::attempts('teste_compr:rl'));

        // Cache::add + increment (empresas:versao, ServicoEmpresas::invalidarTodos)
        Cache::add('teste_compr:versao', 1);
        $this->assertSame(2, Cache::increment('teste_compr:versao'));
        $this->assertSame(2, (int) Cache::get('teste_compr:versao'));
    }

    #[Test]
    public function valor_comprimido_pela_ligacao_parte_o_incrby(): void
    {
        // O que a ligação gravaria com OPT_COMPRESSION=ZLIB no Cache::add('empresas:versao', 1) de
        // ServicoEmpresas::invalidarTodos: o «1» comprimido (o RateLimiter do Laravel 12 protege-se com
        // withoutSerializationOrCompression; o Cache::add/increment da aplicação não).
        $cliente = Redis::connection('cache')->client();
        $cliente->set($this->chaveBruta('teste_compr:versao'), gzcompress('1'));

        // o phpredis não lança: o INCRBY devolve false e a versão NUNCA sobe — a invalidação de todas as listas de
        // empresas acessíveis ficaria silenciosamente sem efeito até ao TTL (acesso retirado mantido).
        $this->assertFalse(Cache::increment('teste_compr:versao'));
        $this->assertMatchesRegularExpression('/not an integer/i', (string) $cliente->getLastError());
        $cliente->clearLastError();
        $this->assertSame(gzcompress('1'), $cliente->get($this->chaveBruta('teste_compr:versao')));
    }

    #[Test]
    public function conjunto_grande_fica_comprimido_com_menos_memoria_e_mesma_chave(): void
    {
        $dados = $this->conjuntoGrande();
        $chamadas = 0;
        $calcular = function () use ($dados, &$chamadas) {
            $chamadas++;

            return $dados;
        };

        $this->assertSame($dados, CacheComprimida::lembrar('teste_compr:grande', 600, $calcular));
        $this->assertSame($dados, CacheComprimida::lembrar('teste_compr:grande', 600, $calcular));
        $this->assertSame(1, $chamadas, 'a segunda leitura vem da cache');

        $guardado = Cache::get('teste_compr:grande');
        $this->assertIsString($guardado);
        $this->assertStringStartsWith(CacheComprimida::MARCADOR, $guardado);
        $ttl = Redis::connection('cache')->client()->ttl($this->chaveBruta('teste_compr:grande'));
        $this->assertGreaterThan(500, $ttl);

        Cache::put('teste_compr:bruto', $dados, 600);   // como era gravado antes
        $cliente = Redis::connection('cache')->client();
        $memComprimida = (int) $cliente->rawCommand('MEMORY', 'USAGE', $this->chaveBruta('teste_compr:grande'));
        $memBruta = (int) $cliente->rawCommand('MEMORY', 'USAGE', $this->chaveBruta('teste_compr:bruto'));
        $this->assertLessThan($memBruta * 0.3, $memComprimida, "comprimido {$memComprimida} B vs bruto {$memBruta} B");

        // a invalidação continua a ser um forget da mesma chave
        Cache::forget('teste_compr:grande');
        $this->assertNull(Cache::get('teste_compr:grande'));
    }

    #[Test]
    public function retrocompativel_pequeno_sem_compressao_e_envelope_corrompido_recalculado(): void
    {
        // formato antigo (array gravado por Cache::remember) é lido sem recalcular
        Cache::put('teste_compr:antigo', ['antigo' => true], 600);
        $this->assertSame(['antigo' => true], CacheComprimida::lembrar('teste_compr:antigo', 600, fn () => $this->fail('não devia recalcular')));

        // abaixo do limiar fica tal como está
        CacheComprimida::lembrar('teste_compr:pequeno', 600, fn () => [1, 2, 3]);
        $this->assertSame([1, 2, 3], Cache::get('teste_compr:pequeno'));

        // envelope corrompido = falta de cache (recalcula e regrava)
        Cache::put('teste_compr:corrompido', CacheComprimida::MARCADOR.'lixo', 600);
        $this->assertSame(['novo'], CacheComprimida::lembrar('teste_compr:corrompido', 600, fn () => ['novo']));
        $this->assertSame(['novo'], Cache::get('teste_compr:corrompido'));

        // a desserialização não instancia objectos (A08)
        $malicioso = CacheComprimida::MARCADOR.gzcompress(serialize(['x' => new \ArrayObject([1])]));
        $aberto = CacheComprimida::abrir($malicioso);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $aberto['x']);
    }
}
