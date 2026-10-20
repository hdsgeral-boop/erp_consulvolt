<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * O frontend não tem HTML de entrada: o Laravel devolve a view Blade da plataforma SaaS no host
 * `<DOMINIO_PLATAFORMA>.<DOMINIO_BASE>` e a do ERP em qualquer outro host, para qualquer caminho do SPA.
 * Os activos vêm do manifesto do Vite; aqui usa-se um manifesto falso numa pasta `public` temporária (apagada no fim).
 */
class PaginaFrontendPorHostTest extends TestCase
{
    /** Pasta `public` temporária deste teste (nunca se escreve no public/build real, que pode estar montado só de leitura). */
    private ?string $publicTemporario = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['erp.dominio.base' => 'meuerp.ao', 'erp.dominio.prefixo_plataforma' => 'gestao-admin']);
    }

    protected function tearDown(): void
    {
        if ($this->publicTemporario !== null) {
            File::deleteDirectory($this->publicTemporario);
        }

        parent::tearDown();
    }

    /** Usa um manifesto do Vite falso, com as duas entradas, numa pasta `public` temporária. */
    private function comManifesto(): void
    {
        $this->publicTemporario = sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-teste-public-'.uniqid('', true);
        File::ensureDirectoryExists($this->publicTemporario.DIRECTORY_SEPARATOR.'build');
        File::put($this->publicTemporario.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'src/main.tsx' => ['file' => 'assets/main-erp.js', 'src' => 'src/main.tsx', 'isEntry' => true, 'css' => ['assets/main-erp.css']],
            'src/plataforma/main.tsx' => ['file' => 'assets/main-plataforma.js', 'src' => 'src/plataforma/main.tsx', 'isEntry' => true],
        ]));
        $this->app->usePublicPath($this->publicTemporario);
    }

    private function pagina(string $host, string $caminho = '/')
    {
        return $this->call('GET', 'http://'.$host.$caminho);
    }

    public function test_host_da_plataforma_devolve_a_view_da_plataforma(): void
    {
        $this->comManifesto();

        $resposta = $this->pagina('gestao-admin.meuerp.ao');

        $resposta->assertOk()->assertSee('<title>MEU ERP · Plataforma</title>', false)->assertSee('<div id="raiz"></div>', false);
        $resposta->assertSee('/build/assets/', false);
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }

    public function test_host_de_cliente_devolve_a_view_do_erp(): void
    {
        $this->comManifesto();

        $resposta = $this->pagina('empresa1.meuerp.ao');

        $resposta->assertOk()->assertSee('<title>MEU ERP</title>', false)->assertDontSee('Plataforma</title>', false);
        $resposta->assertSee('/build/assets/', false);
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }

    public function test_hosts_fora_do_dominio_base_e_o_proprio_dominio_devolvem_o_erp(): void
    {
        $this->comManifesto();

        foreach (['localhost', '127.0.0.1', 'meuerp.ao', 'www.meuerp.ao', 'outro.exemplo.com'] as $host) {
            $this->pagina($host)->assertOk()->assertSee('<title>MEU ERP</title>', false);
        }
    }

    public function test_rotas_do_spa_devolvem_a_view_certa_por_host(): void
    {
        $this->comManifesto();

        foreach (['/plataforma/painel', '/plataforma/clientes', '/m/vendas/faturacao', '/redefinir-senha'] as $caminho) {
            $this->pagina('gestao-admin.meuerp.ao', $caminho)->assertOk()->assertSee('Plataforma</title>', false);
            $this->pagina('empresa1.meuerp.ao', $caminho)->assertOk()->assertSee('<title>MEU ERP</title>', false);
        }
    }

    public function test_a_pagina_nao_apanha_a_api_nem_o_health_check(): void
    {
        $this->comManifesto();

        $this->pagina('empresa1.meuerp.ao', '/api/rota-que-nao-existe')->assertNotFound()->assertJsonStructure(['sucesso', 'mensagem']);
        $this->pagina('gestao-admin.meuerp.ao', '/api')->assertNotFound()->assertDontSee('<div id="raiz">', false);
        $this->pagina('empresa1.meuerp.ao', '/build/assets/nao-existe.js')->assertNotFound()->assertDontSee('<div id="raiz">', false);
        $this->pagina('empresa1.meuerp.ao', '/up')->assertOk()->assertDontSee('<div id="raiz">', false);
        // Prefixos parecidos continuam a ser rotas do SPA
        $this->pagina('empresa1.meuerp.ao', '/apice')->assertOk()->assertSee('<div id="raiz">', false);
    }

    public function test_host_encaminhado_nao_abre_a_plataforma(): void
    {
        $this->comManifesto();

        // Vindo do proxy (rede privada) com um X-Forwarded-Host forjado: o endereço que decide é o do pedido, nunca o encaminhado.
        $resposta = $this->call('GET', 'http://empresa1.meuerp.ao/', [], [], [], [
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_X_FORWARDED_HOST' => 'gestao-admin.meuerp.ao',
        ]);

        $resposta->assertOk()->assertSee('<title>MEU ERP</title>', false)->assertDontSee('Plataforma', false);
    }

    public function test_proxies_confiaveis_so_para_ip_e_esquema_nunca_para_o_host(): void
    {
        $cabecalhos = new \ReflectionProperty(\Illuminate\Http\Middleware\TrustProxies::class, 'alwaysTrustHeaders');
        $proxies = new \ReflectionProperty(\Illuminate\Http\Middleware\TrustProxies::class, 'alwaysTrustProxies');
        $mascara = $cabecalhos->getValue();

        $this->assertNotSame(0, $mascara & \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR);
        $this->assertNotSame(0, $mascara & \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO);
        $this->assertSame(0, $mascara & \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST);
        $this->assertSame(0, $mascara & \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT);
        $this->assertNotContains('*', (array) $proxies->getValue());
        $this->assertContains('172.16.0.0/12', (array) $proxies->getValue());
    }

    public function test_sem_manifesto_devolve_pagina_de_erro_clara(): void
    {
        // Directório de build sem manifest.json (o public/build real pode estar montado só de leitura).
        Vite::useBuildDirectory('build-inexistente');

        try {
            $resposta = $this->pagina('empresa1.meuerp.ao', '/m/vendas/faturacao');
            $resposta->assertStatus(503)->assertSee('Interface indisponível')->assertSee('npm run build');
            $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        } finally {
            Vite::useBuildDirectory('build');
        }
    }
}
