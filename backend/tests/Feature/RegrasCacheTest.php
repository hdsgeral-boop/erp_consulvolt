<?php

namespace Tests\Feature;

use App\Models\PlanoConta;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Cache\CacheComprimida;
use App\Support\Cache\ChaveCache;
use App\Support\Dados\VerificadorReferencias;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regras de cache (docs/arquitetura/CACHE.md): inventário fechado dos pontos que gravam na cache, versionamento das
 * chaves de metadados do esquema e plano de contas comprimido com a mesma chave e a mesma invalidação.
 */
final class RegrasCacheTest extends TestCase
{
    /**
     * Ficheiros autorizados a GRAVAR na cache (remember/put/forever/add/increment/flexible), cada um descrito no
     * inventário do CACHE.md. Um ficheiro novo aqui exige passar pela lista de verificação do documento (chave com
     * empresa/utilizador, TTL, invalidação depois do commit, sem dados sensíveis, tamanho).
     */
    private const AUTORIZADOS = [
        'Http/Controllers/Api/Gestao/PaineisController.php',
        'Services/Contabilidade/ServicoPlanoContas.php',
        'Services/Gestao/Paineis/ServicoPaineis.php',
        'Services/Integracoes/Operacoes/ServicoOperacoes.php',
        'Services/Logistica/ServicoProdutos.php',
        'Services/Sistema/ServicoCopiaEmpresa.php',
        'Services/Sistema/ServicoEmpresas.php',
        'Support/Cache/Batimentos.php',
        'Support/Cache/CacheComprimida.php',
        'Support/Dados/VerificadorReferencias.php',
    ];

    #[Test]
    public function so_os_pontos_do_inventario_gravam_na_cache(): void
    {
        $encontrados = [];
        $ficheiros = self::ficheirosPhp(app_path());
        $this->assertGreaterThan(300, count($ficheiros), 'o inventário tem de percorrer todo o app/');
        foreach ($ficheiros as $relativo => $conteudo) {
            if (preg_match('/\b(Cache::|cache\(\)->)(remember|rememberForever|put|forever|add|increment|decrement|flexible|putMany|many)\s*\(/', $conteudo)
                || preg_match('/\bCacheComprimida::lembrar\s*\(/', $conteudo)) {
                $encontrados[] = $relativo;
            }
        }
        sort($encontrados);
        $novos = array_values(array_diff($encontrados, self::AUTORIZADOS));
        $this->assertSame([], $novos, 'Escrita em cache fora do inventário: aplicar a lista de verificação de docs/arquitetura/CACHE.md e acrescentar o ficheiro ao inventário e a este teste.');
    }

    /**
     * Percorre a pasta com scandir, e não com o Finder/DirectoryIterator: na montagem Windows do Docker Desktop o
     * rewinddir salta entradas em pastas grandes (app/Models) e o inventário ficaria incompleto sem ninguém notar.
     *
     * @return array<string, string> caminho relativo => conteúdo
     */
    private static function ficheirosPhp(string $raiz, string $prefixo = ''): array
    {
        $r = [];
        foreach (scandir($raiz) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $caminho = $raiz.'/'.$e;
            if (is_dir($caminho)) {
                $r += self::ficheirosPhp($caminho, $prefixo.$e.'/');
            } elseif (str_ends_with($e, '.php')) {
                $r[$prefixo.$e] = (string) file_get_contents($caminho);
            }
        }

        return $r;
    }

    #[Test]
    public function referencias_fk_versionadas_pela_ultima_migracao(): void
    {
        $verificador = app(VerificadorReferencias::class);
        $versao = (string) DB::table(config('database.migrations.table'))->max('migration');
        $verificador->referenciasPara('empresas');
        $this->assertTrue(Cache::has("fk_referencias:{$versao}:empresas"));

        // uma migração nova muda a chave: a lista é relida logo (antes ficava até 1 h com as FK antigas)
        DB::table(config('database.migrations.table'))->insert(['migration' => '9999_12_31_000000_teste_versao_cache', 'batch' => 9999]);
        $verificador->referenciasPara('empresas');
        $this->assertTrue(Cache::has('fk_referencias:9999_12_31_000000_teste_versao_cache:empresas'));
    }

    #[Test]
    public function plano_de_contas_comprimido_na_mesma_chave_e_invalidado_pelos_eventos(): void
    {
        config(['erp.cache.comprimir_acima_de_bytes' => 64]);   // força a compressão com um plano pequeno
        $empresa = $this->criarEmpresa();
        $chave = ChaveCache::empresa($empresa->id, 'contabilidade', 'plano_contas');
        $this->assertSame("{$empresa->id}:contabilidade:plano_contas", $chave, 'padrão da chave inalterado');

        app(ContextoEmpresa::class)->executarComo($empresa->id, function () use ($chave) {
            PlanoConta::create(['codigo' => '611', 'descricao' => 'Vendas', 'tipo' => 'M']);
            PlanoConta::create(['codigo' => '62', 'descricao' => 'Prestações', 'tipo' => 'T']);
            $plano = app(ServicoPlanoContas::class);
            $this->assertSame(['611', '62'], array_map('strval', array_keys($plano->todas())));   // chaves numéricas viram int (como antes)
            $this->assertStringStartsWith(CacheComprimida::MARCADOR, Cache::get($chave));
            $this->assertSame('611', $plano->contaDeMovimento('611')['codigo']);

            PlanoConta::create(['codigo' => '612', 'descricao' => 'Vendas 2', 'tipo' => 'M']);   // evento -> forget
            $this->assertNull(Cache::get($chave));
            $this->assertArrayHasKey('612', $plano->todas());
        });
    }
}
