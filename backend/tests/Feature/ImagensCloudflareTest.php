<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Produto;
use App\Services\Sistema\ServicoImagensPublicas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Imagens no Cloudflare R2 (ServicoImagensPublicas, config erp.imagens.disco): logótipos das empresas, logótipo do ecrã
 * de entrada e imagens de produtos vão para o disco público e a base guarda só o URL; o comando erp:imagens:migrar move
 * o que ainda estava em base64 ou no disco privado. O disco é simulado (Storage::fake) com um URL público fictício.
 */
final class ImagensCloudflareTest extends TestCase
{
    private const URL_PUBLICO = 'https://pub-teste.r2.dev';

    /** PNG 1 × 1 válido (o tipo é confirmado pelo conteúdo, finfo). */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.imagens.disco' => 'imagens']);
        Storage::fake('imagens', ['url' => self::URL_PUBLICO]);
        Storage::fake('local');
        $this->empresa = $this->criarEmpresa(['nif' => '5417000701']);
    }

    private function sessao(array $permissoes, array $utilizador = []): array
    {
        $u = $this->criarUtilizador($utilizador + ['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function caminhoNoDisco(string $url): string
    {
        $this->assertStringStartsWith(self::URL_PUBLICO.'/', $url);

        return substr($url, strlen(self::URL_PUBLICO) + 1);
    }

    #[Test]
    public function logotipo_da_empresa_vai_para_o_r2_e_a_base_guarda_so_o_url(): void
    {
        $s = $this->sessao(['config_empresas_view', 'config_empresas_gerir']);
        $id = $this->empresa->id;

        $this->putJson("/api/sistema/empresas/{$id}", ['logotipo' => self::PNG], $s)->assertOk()->assertJsonPath('dados.tem_logotipo', true);
        $url = (string) DB::table('empresas')->where('id', $id)->value('logotipo');
        $this->assertMatchesRegularExpression('#^'.preg_quote(self::URL_PUBLICO, '#')."/empresas/{$id}/logotipo-[0-9a-f]{16}\.png$#", $url);
        Storage::disk('imagens')->assertExists($this->caminhoNoDisco($url));
        $this->assertStringNotContainsString('base64', $url);

        // a ficha e a identidade devolvem o URL (o navegador lê a imagem directamente da Cloudflare)
        $this->getJson("/api/sistema/gestao-empresas/{$id}", $s)->assertOk()->assertJsonPath('dados.logotipo', $url);
        $this->getJson('/api/sistema/identidade', $s)->assertOk()->assertJsonPath('dados.logotipo', $url);

        // devolver o URL actual tal e qual não altera nada; uma «imagem» que não é raster é recusada
        $this->putJson("/api/sistema/empresas/{$id}", ['logotipo' => $url, 'telefone' => '+244 900 000 000'], $s)->assertOk();
        $this->assertSame($url, DB::table('empresas')->where('id', $id)->value('logotipo'));
        $this->putJson("/api/sistema/empresas/{$id}", ['logotipo' => 'data:image/png;base64,'.base64_encode('nao e png')], $s)->assertStatus(422)->assertJsonPath('codigo', 'IMAGEM_INVALIDA');

        // remover apaga o objecto do R2
        $this->putJson("/api/sistema/empresas/{$id}", ['logotipo' => ''], $s)->assertOk()->assertJsonPath('dados.tem_logotipo', false);
        Storage::disk('imagens')->assertMissing($this->caminhoNoDisco($url));
    }

    #[Test]
    public function logotipo_do_ecra_de_entrada_vai_para_o_r2(): void
    {
        $super = $this->sessao([], ['papel' => 'SUPER_ADMINISTRADOR']);
        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['logotipo' => self::PNG], $super)->assertOk();
        $url = (string) DB::table('configuracoes_sistema')->where('chave', 'login_logo')->value('valor');
        $this->assertStringStartsWith(self::URL_PUBLICO.'/sistema/logotipo-login-', $url);
        Storage::disk('imagens')->assertExists($this->caminhoNoDisco($url));
        $this->getJson('/api/sistema/logotipo-login')->assertOk()->assertJsonPath('dados.logotipo', $url);

        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['logotipo' => null], $super)->assertOk();
        Storage::disk('imagens')->assertMissing($this->caminhoNoDisco($url));
        $this->assertNull(DB::table('configuracoes_sistema')->where('chave', 'login_logo')->value('valor'));
    }

    #[Test]
    public function imagem_do_produto_fica_no_r2_e_o_url_e_publico(): void
    {
        $produto = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::create(['codigo' => 'P1', 'nome' => 'Produto Um', 'preco_unitario' => 1000, 'taxa_imposto' => 14])->id);
        $s = $this->sessao(['vendas_produtos_view', 'vendas_produtos_gerir']);
        $api = "/api/vendas/produtos/{$produto}/imagem";

        $r = $this->post($api, ['imagem' => UploadedFile::fake()->image('foto.png', 40, 30)], $s + ['Accept' => 'application/json'])->assertOk();
        $url = (string) $r->json('dados.imagem_url');
        $this->assertMatchesRegularExpression('#^'.preg_quote(self::URL_PUBLICO, '#')."/empresas/{$this->empresa->id}/produtos/[0-9a-f-]{36}\.png\?v=\d+$#", $url);
        $caminho = (string) DB::table('produtos')->where('id', $produto)->value('imagem_caminho');
        Storage::disk('imagens')->assertExists($caminho);
        Storage::disk('local')->assertMissing($caminho);

        $this->getJson('/api/vendas/produtos/imagens', $s)->assertOk()->assertJsonPath('dados.0.imagem_url', $url);
        $this->getJson("/api/logistica/produtos/{$produto}", $s)->assertOk()->assertJsonPath('dados.imagem_url', $url);
        $this->get($api, $s)->assertOk()->assertHeader('Content-Type', 'image/png');   // a rota autenticada continua a servir os bytes

        $this->delete($api, [], $s)->assertOk()->assertJsonPath('dados.imagem_url', null);
        Storage::disk('imagens')->assertMissing($caminho);
    }

    #[Test]
    public function comando_migra_base64_e_ficheiros_privados_para_o_r2(): void
    {
        $e = $this->empresa;
        DB::table('empresas')->where('id', $e->id)->update(['logotipo' => self::PNG]);
        DB::table('configuracoes_sistema')->updateOrInsert(['chave' => 'login_logo'], ['valor' => self::PNG, 'atualizado_em' => now()]);
        [$legado, $ficheiro] = app(ContextoEmpresa::class)->executarComo($e->id, function () {
            $legado = Produto::create(['codigo' => 'L1', 'nome' => 'Legado', 'imagem_base64' => self::PNG])->id;
            $ficheiro = Produto::create(['codigo' => 'F1', 'nome' => 'Ficheiro'])->id;

            return [$legado, $ficheiro];
        });
        $caminhoPrivado = "empresas/{$e->id}/produtos/00000000-0000-4000-8000-000000000001.png";
        Storage::disk('local')->put($caminhoPrivado, ServicoImagensPublicas::descodificarDataUri(self::PNG)['conteudo']);
        DB::table('produtos')->where('id', $ficheiro)->update(['imagem_caminho' => $caminhoPrivado, 'imagem_mime' => 'image/png', 'imagem_atualizada_em' => now()]);

        $this->artisan('erp:imagens:migrar', ['--simular' => true])->assertSuccessful();
        $this->assertSame(self::PNG, DB::table('empresas')->where('id', $e->id)->value('logotipo'));   // simulação não altera

        $this->artisan('erp:imagens:migrar')->assertSuccessful();

        $logo = (string) DB::table('empresas')->where('id', $e->id)->value('logotipo');
        $this->assertStringStartsWith(self::URL_PUBLICO."/empresas/{$e->id}/logotipo-", $logo);
        Storage::disk('imagens')->assertExists($this->caminhoNoDisco($logo));
        $login = (string) DB::table('configuracoes_sistema')->where('chave', 'login_logo')->value('valor');
        $this->assertStringStartsWith(self::URL_PUBLICO.'/sistema/logotipo-login-', $login);

        $p = DB::table('produtos')->where('id', $legado)->first();
        $this->assertNull($p->imagem_base64);
        $this->assertSame('image/png', $p->imagem_mime);
        Storage::disk('imagens')->assertExists($p->imagem_caminho);

        $f = DB::table('produtos')->where('id', $ficheiro)->first();
        $this->assertSame($caminhoPrivado, $f->imagem_caminho);
        Storage::disk('imagens')->assertExists($caminhoPrivado);
        Storage::disk('local')->assertMissing($caminhoPrivado);

        // idempotente: segunda execução não encontra nada para migrar
        $this->artisan('erp:imagens:migrar')->assertSuccessful()->expectsOutputToContain('Migração concluída');
        $this->assertSame($logo, DB::table('empresas')->where('id', $e->id)->value('logotipo'));
    }

    #[Test]
    public function sem_r2_configurado_tudo_fica_como_antes(): void
    {
        config(['erp.imagens.disco' => '']);
        $s = $this->sessao(['config_empresas_view', 'config_empresas_gerir']);
        $this->putJson("/api/sistema/empresas/{$this->empresa->id}", ['logotipo' => self::PNG], $s)->assertOk();
        $this->assertSame(self::PNG, DB::table('empresas')->where('id', $this->empresa->id)->value('logotipo'));
        $this->artisan('erp:imagens:migrar')->assertFailed();
    }
}
