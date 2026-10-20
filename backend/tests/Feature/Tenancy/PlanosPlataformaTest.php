<?php

namespace Tests\Feature\Tenancy;

use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use App\Models\Tenant;
use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Catálogo de planos na app da plataforma: criar plano (+ versão 1 em rascunho), editar dados, regras de activação e limites. */
class PlanosPlataformaTest extends TestCase
{
    /** @return array<string, string> */
    private function admin(): array
    {
        config(['erp.saas.ativo' => true]);

        return $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
    }

    /** @return array<string, mixed> */
    private function corpo(array $extra = []): array
    {
        return $extra + ['codigo' => 'basico', 'nome' => 'Básico', 'descricao' => 'Para começar', 'preco_mensal' => 15000, 'moeda' => 'aoa',
            'max_empresas' => 2, 'max_utilizadores' => 5, 'modulos' => ['vendas', 'stock'], 'ordem' => 3];
    }

    public function test_cria_o_plano_com_a_primeira_versao_em_rascunho_e_inactivo(): void
    {
        $sessao = $this->admin();

        $id = $this->postJson('/api/plataforma/planos', $this->corpo(['ativo' => true]), $sessao)->assertCreated()
            ->assertJsonPath('dados.codigo', 'basico')->assertJsonPath('dados.ativo', false)->json('dados.id');

        $plano = PlanoLicenca::query()->findOrFail($id);
        $this->assertSame('AOA', $plano->moeda);
        $this->assertSame(3, (int) $plano->ordem);
        $versao = PlanoLicencaVersao::query()->where('plano_id', $id)->firstOrFail();
        $this->assertSame(PlanoLicencaVersao::RASCUNHO, $versao->estado);
        $this->assertSame(1, (int) $versao->numero);
        $this->assertSame(5, (int) $versao->max_utilizadores);
        $this->assertEqualsCanonicalizing(['vendas', 'stock'], $versao->modulos);
        $this->assertDatabaseHas('logs_auditoria', ['modulo' => 'Plataforma', 'acao' => 'Criou plano']);

        // Inactivo e sem versão publicada: as empresas não o veem nem o podem comprar.
        $this->postJson("/api/plataforma/versoes/{$versao->id}/publicar", [], $sessao)->assertOk();
        $this->putJson("/api/plataforma/planos/{$id}", ['ativo' => true], $sessao)->assertOk()->assertJsonPath('dados.ativo', true);
    }

    public function test_limites_nulos_significam_sem_limite(): void
    {
        $sessao = $this->admin();
        $id = $this->postJson('/api/plataforma/planos', $this->corpo(['max_empresas' => null, 'max_utilizadores' => null]), $sessao)->assertCreated()->json('dados.id');

        $lista = $this->getJson('/api/plataforma/planos', $sessao)->assertOk()->json('dados.planos');
        $plano = collect($lista)->firstWhere('id', $id);
        $this->assertNull($plano['max_empresas']);
        $this->assertNull($plano['max_utilizadores']);
        $this->assertNull($plano['versoes'][0]['max_empresas']);
        $this->assertSame(0, $plano['clientes']);
        $this->assertFalse($plano['tem_versao_publicada']);
    }

    public function test_recusa_codigo_e_nome_duplicados_e_dados_invalidos(): void
    {
        $sessao = $this->admin();
        $this->postJson('/api/plataforma/planos', $this->corpo(), $sessao)->assertCreated();

        $this->postJson('/api/plataforma/planos', $this->corpo(['nome' => 'Outro']), $sessao)->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->postJson('/api/plataforma/planos', $this->corpo(['codigo' => 'outro']), $sessao)->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->postJson('/api/plataforma/planos', $this->corpo(['codigo' => 'Com Espaço', 'nome' => 'X']), $sessao)->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->postJson('/api/plataforma/planos', $this->corpo(['codigo' => 'neg', 'nome' => 'Neg', 'preco_mensal' => -1]), $sessao)->assertStatus(422)->assertJsonValidationErrors('preco_mensal');
        $this->postJson('/api/plataforma/planos', $this->corpo(['codigo' => 'zero', 'nome' => 'Zero', 'max_empresas' => 0]), $sessao)->assertStatus(422)->assertJsonValidationErrors('max_empresas');
        $this->postJson('/api/plataforma/planos', $this->corpo(['codigo' => 'zeroU', 'nome' => 'ZeroU']), $sessao)->assertStatus(422);
        $this->postJson('/api/plataforma/planos', $this->corpo(['codigo' => 'mod', 'nome' => 'Mod', 'modulos' => ['inexistente']]), $sessao)->assertStatus(422)->assertJsonValidationErrors('modulos.0');
        $this->postJson('/api/plataforma/planos', $this->corpo(['codigo' => 'moe', 'nome' => 'Moe', 'moeda' => 'KWANZA']), $sessao)->assertStatus(422)->assertJsonValidationErrors('moeda');
        $this->assertSame(1, PlanoLicenca::query()->count());
    }

    public function test_edita_os_dados_mas_o_codigo_nao_muda_e_o_preco_so_por_versao(): void
    {
        $sessao = $this->admin();
        $id = $this->postJson('/api/plataforma/planos', $this->corpo(), $sessao)->assertCreated()->json('dados.id');

        $this->putJson("/api/plataforma/planos/{$id}", ['nome' => 'Básico+', 'descricao' => 'Nova', 'ordem' => 9, 'codigo' => 'trocado', 'preco_mensal' => 1], $sessao)
            ->assertOk()->assertJsonPath('dados.nome', 'Básico+')->assertJsonPath('dados.codigo', 'basico')->assertJsonPath('dados.ordem', 9);
        $plano = PlanoLicenca::query()->findOrFail($id);
        $this->assertSame('basico', $plano->codigo);
        $this->assertSame(15000.0, (float) $plano->preco_mensal);

        $this->postJson('/api/plataforma/planos', $this->corpo(['codigo' => 'pro', 'nome' => 'Pro']), $sessao)->assertCreated();
        $this->putJson("/api/plataforma/planos/{$id}", ['nome' => 'Pro'], $sessao)->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->putJson('/api/plataforma/planos/999999', ['nome' => 'X'], $sessao)->assertNotFound();
    }

    public function test_so_activa_com_versao_publicada_e_nao_desactiva_plano_em_uso_sem_confirmar(): void
    {
        $sessao = $this->admin();
        $id = $this->postJson('/api/plataforma/planos', $this->corpo(), $sessao)->assertCreated()->json('dados.id');

        $this->putJson("/api/plataforma/planos/{$id}", ['ativo' => true], $sessao)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_SEM_VERSAO_PUBLICADA');

        $versao = PlanoLicencaVersao::query()->where('plano_id', $id)->firstOrFail();
        $this->postJson("/api/plataforma/versoes/{$versao->id}/publicar", [], $sessao)->assertOk();
        $this->putJson("/api/plataforma/planos/{$id}", ['ativo' => true], $sessao)->assertOk();

        $cliente = Tenant::query()->create(['nome' => 'Cliente Plano', 'slug' => 'cliente-plano', 'estado' => Tenant::ESTADO_ATIVO]);
        $this->putJson("/api/plataforma/clientes/{$cliente->id}/plano", ['plano' => 'basico'], $sessao)->assertOk();
        $this->assertDatabaseHas('logs_auditoria', ['modulo' => 'Plataforma', 'acao' => 'Atribuiu plano']);
        $this->assertSame(1, collect($this->getJson('/api/plataforma/planos', $sessao)->json('dados.planos'))->firstWhere('id', $id)['clientes']);

        $this->putJson("/api/plataforma/planos/{$id}", ['ativo' => false], $sessao)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_EM_USO');
        $this->assertTrue(PlanoLicenca::query()->findOrFail($id)->ativo);

        $this->putJson("/api/plataforma/planos/{$id}", ['ativo' => false, 'confirmar_desativacao' => true], $sessao)->assertOk()->assertJsonPath('dados.ativo', false);
        // O cliente mantém o plano e os limites.
        $this->assertSame('basico', $cliente->fresh()->plano);
    }

    public function test_publicar_copia_preco_e_limites_para_o_plano_e_so_uma_versao_fica_em_vigor(): void
    {
        $sessao = $this->admin();
        $id = $this->postJson('/api/plataforma/planos', $this->corpo(), $sessao)->assertCreated()->json('dados.id');
        $v1 = PlanoLicencaVersao::query()->where('plano_id', $id)->firstOrFail();
        $this->postJson("/api/plataforma/versoes/{$v1->id}/publicar", [], $sessao)->assertOk();

        $v2 = $this->postJson("/api/plataforma/planos/{$id}/versoes", [], $sessao)->assertCreated()->json('dados.id');
        $this->putJson("/api/plataforma/versoes/{$v2}", ['preco_mensal' => 20000, 'max_empresas' => null], $sessao)->assertOk();
        $this->postJson("/api/plataforma/versoes/{$v2}/publicar", [], $sessao)->assertOk();
        $this->postJson("/api/plataforma/versoes/{$v2}/publicar", [], $sessao)->assertStatus(422);

        $plano = PlanoLicenca::query()->findOrFail($id);
        $this->assertSame(20000.0, (float) $plano->preco_mensal);
        $this->assertNull($plano->max_empresas);
        $this->assertSame(1, PlanoLicencaVersao::query()->where('plano_id', $id)->where('estado', PlanoLicencaVersao::PUBLICADA)->count());
        $this->assertSame(PlanoLicencaVersao::DESCONTINUADA, $v1->fresh()->estado);
    }

    public function test_cliente_comum_e_super_admin_de_cliente_recebem_403(): void
    {
        config(['erp.saas.ativo' => true]);
        $comum = $this->entrar($this->criarUtilizador());
        $outro = Tenant::query()->create(['nome' => 'Outro', 'slug' => 'outro-planos', 'estado' => Tenant::ESTADO_ATIVO]);
        $superCliente = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);
        $superCliente->forceFill(['tenant_id' => $outro->id])->save();
        $sessaoSuper = $this->entrar($superCliente);

        foreach ([$comum, $sessaoSuper] as $sessao) {
            $this->getJson('/api/plataforma/planos', $sessao)->assertForbidden();
            $this->postJson('/api/plataforma/planos', $this->corpo(), $sessao)->assertForbidden();
        }
        $id = PlanoLicenca::query()->create(['codigo' => 'x', 'nome' => 'X', 'preco_mensal' => 1, 'moeda' => 'AOA', 'ativo' => true])->id;
        $this->putJson("/api/plataforma/planos/{$id}", ['nome' => 'Y'], $comum)->assertForbidden();
        $this->putJson("/api/plataforma/planos/{$id}", ['nome' => 'Y'], $sessaoSuper)->assertForbidden();
        $this->assertSame('X', DB::table('planos_licenca')->where('id', $id)->value('nome'));
    }
}
