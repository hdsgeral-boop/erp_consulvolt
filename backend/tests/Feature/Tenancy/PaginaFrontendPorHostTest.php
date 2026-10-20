<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * O frontend não tem HTML de entrada: o Laravel devolve a view Blade da plataforma SaaS no host
 * `<DOMINIO_PLATAFORMA>.<DOMINIO_BASE>` e a do ERP em qualquer outro host, para qualquer caminho do SPA.
 * Os activos vêm do manifesto do Vite; aqui usa-se um manifesto temporário em public/build (apagado no fim).
 */
class PaginaFrontendPorHostTest extends TestCase
{
    private bool $manifestoCriado = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['erp.dominio.base' => 'meuerp.ao', 'erp.dominio.prefixo_plataforma' => 'gestao-admin']);
    }

    protected function tearDown(): void
    {
        if ($this->manifestoCriado) {
            File::delete(public_path('build/manifest.json'));
        }

        parent::tearDown();
    }

    /** Garante um manifesto do Vite com as duas entradas (se o frontend real já estiver compilado, usa-o). */
    private function comManifesto(): void
    {
        if (File::exists(public_path('build/manifest.json'))) {
            return;
        }
        File::ensureDirectoryExists(public_path('build'));
        File::put(public_path('build/manifest.json'), json_encode([
            'src/main.tsx' => ['file' => 'assets/main-erp.js', 'src' => 'src/main.tsx', 'isEntry' => true, 'css' => ['assets/main-erp.css']],
            'src/plataforma/main.tsx' => ['file' => 'assets/main-plataforma.js', 'src' => 'src/plataforma/main.tsx', 'isEntry' => true],
        ]));
        $this->manifestoCriado = true;
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
