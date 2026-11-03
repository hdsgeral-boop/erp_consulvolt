<?php

namespace Tests\Feature\Tenancy;

use App\Mail\OperadorPlataformaCriadoMail;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fase 4 da extensão SaaS (segurança.txt, secção 12): RBAC granular do back-office. Um operador da plataforma
 * (OPERADOR_PLATAFORMA) entra na área (ExigirPlataforma), mas cada operação sensível exige ainda a chave
 * `plataforma_*_gerir` correspondente no seu perfil — nunca o acesso irrestrito que um super-administrador tem.
 */
class RbacPlataformaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ContextoTenant::class)->definir(1);
    }

    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function superAdmin(): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return [$u, $this->entrar($u)];
    }

    private function perfilPlataforma(array $chaves): PerfilUtilizador
    {
        return PerfilUtilizador::create(['nome' => 'Perfil Plataforma '.uniqid(), 'permissoes' => ['_v2' => true] + array_fill_keys($chaves, true), 'restrito_plataforma' => true]);
    }

    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function operador(array $chaves): array
    {
        $perfil = $this->perfilPlataforma($chaves);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_OPERADOR_PLATAFORMA, 'perfil_utilizador_id' => $perfil->id]);

        return [$u, $this->entrar($u)];
    }

    public function test_operador_sem_nenhuma_chave_entra_na_area_mas_e_bloqueado_em_tudo(): void
    {
        config(['erp.saas.ativo' => true]);
        [, $sessao] = $this->operador([]);

        // entra na área (middleware `plataforma` já não é binário) mas sem nenhuma chave não gere nada
        $this->getJson('/api/plataforma/clientes', $sessao)->assertOk();
        $this->postJson('/api/plataforma/clientes', ['nome' => 'X', 'slug' => 'x', 'administrador' => ['nome_utilizador' => 'y', 'palavra_passe' => 'SenhaForte123']], $sessao)
            ->assertForbidden();
        $this->putJson('/api/plataforma/saas/geral', ['licencas_ativas' => true], $sessao)->assertForbidden();
        $this->putJson('/api/plataforma/definicoes', ['nome_plataforma' => 'X'], $sessao)->assertForbidden();
    }

    public function test_operador_com_chave_de_clientes_so_gere_clientes(): void
    {
        config(['erp.saas.ativo' => true]);
        [, $sessao] = $this->operador(['plataforma_clientes_gerir']);

        $this->postJson('/api/plataforma/clientes', [
            'nome' => 'Cliente Teste RBAC', 'slug' => 'cliente-teste-rbac',
            'administrador' => ['nome_utilizador' => 'adm_rbac', 'palavra_passe' => 'SenhaForte123'],
        ], $sessao)->assertCreated();

        // mas continua sem acesso a outras áreas sensíveis
        $this->putJson('/api/plataforma/saas/geral', ['licencas_ativas' => true], $sessao)->assertForbidden();
        $this->postJson('/api/plataforma/cupoes', ['codigo' => 'X10', 'tipo_desconto' => 'PERCENTAGEM', 'valor_desconto' => 10,
            'data_inicio' => now()->toDateString(), 'data_fim' => now()->addMonth()->toDateString(), 'limite_por_cliente' => 1, 'ativo' => true], $sessao)
            ->assertForbidden();
    }

    /**
     * Gerir administradores ou operadores da plataforma nunca é delegável por uma chave de perfil — mesmo com a
     * chave (hipoteticamente) atribuída, um OPERADOR_PLATAFORMA não pode gerir nenhum dos dois, porque isso
     * equivaleria a poder criar/alterar contas com mais poder do que o seu próprio perfil. Só mesmo um
     * super-administrador gere isto. Gerir agentes de suporte já é delegável (plataforma_suporte_gerir), por
     * estarem sempre limitados à sua especialização.
     */
    public function test_gerir_administradores_e_operadores_nunca_e_delegavel_mas_agentes_de_suporte_e(): void
    {
        config(['erp.saas.ativo' => true]);
        [, $sessaoSemNada] = $this->operador([]);
        [, $sessaoComSuporte] = $this->operador(['plataforma_suporte_gerir']);
        $perfilSuporte = $this->perfilPlataforma([]);

        $this->postJson('/api/plataforma/administradores', ['nome_utilizador' => 'x1', 'email' => 'x1@x.ao'], $sessaoSemNada)->assertForbidden();
        $this->postJson('/api/plataforma/operadores-plataforma', ['nome_utilizador' => 'x2', 'perfil_utilizador_id' => $perfilSuporte->id], $sessaoSemNada)->assertForbidden();

        // mesmo com uma chave "plataforma_*_gerir" qualquer atribuída, continua sem poder.
        $this->postJson('/api/plataforma/administradores', ['nome_utilizador' => 'x3', 'email' => 'x3@x.ao'], $sessaoComSuporte)->assertForbidden();
        $this->postJson('/api/plataforma/operadores-plataforma', ['nome_utilizador' => 'x4', 'perfil_utilizador_id' => $perfilSuporte->id], $sessaoComSuporte)->assertForbidden();

        // mas gerir agentes de suporte é delegável com a chave certa.
        $this->postJson('/api/plataforma/agentes-suporte', ['nome_utilizador' => 'x5', 'perfil_utilizador_id' => $perfilSuporte->id], $sessaoComSuporte)->assertCreated();
        $this->postJson('/api/plataforma/agentes-suporte', ['nome_utilizador' => 'x6', 'perfil_utilizador_id' => $perfilSuporte->id], $sessaoSemNada)->assertForbidden();
    }

    public function test_super_administrador_continua_com_acesso_total_sem_chaves(): void
    {
        config(['erp.saas.ativo' => true]);
        [, $sessao] = $this->superAdmin();

        $this->putJson('/api/plataforma/saas/geral', ['licencas_ativas' => true], $sessao)->assertOk();
        $this->putJson('/api/plataforma/definicoes', ['nome_plataforma' => 'X'], $sessao)->assertOk();
    }

    public function test_utilizador_comum_continua_sem_entrar_na_area(): void
    {
        config(['erp.saas.ativo' => true]);
        $comum = $this->entrar($this->criarUtilizador());

        $this->getJson('/api/plataforma/clientes', $comum)->assertForbidden();
    }

    public function test_criar_operador_gera_palavra_passe_e_exige_troca_no_primeiro_acesso(): void
    {
        config(['erp.saas.ativo' => true]);
        [, $sessao] = $this->superAdmin();
        $perfil = $this->perfilPlataforma(['plataforma_clientes_gerir']);
        Mail::fake();

        $id = $this->postJson('/api/plataforma/operadores-plataforma', [
            'nome_utilizador' => 'operador_novo', 'email' => 'operador@consulvolt.ao', 'perfil_utilizador_id' => $perfil->id,
        ], $sessao)->assertCreated()->json('dados.id');

        $novo = Utilizador::query()->findOrFail($id);
        $this->assertSame(Utilizador::PAPEL_OPERADOR_PLATAFORMA, $novo->papel);
        $this->assertTrue($novo->deve_alterar_password);

        $senha = null;
        Mail::assertQueued(OperadorPlataformaCriadoMail::class, function (OperadorPlataformaCriadoMail $mail) use (&$senha) {
            $senha = $this->palavraPasseDoEmail($mail);

            return true;
        });
        $this->entrar($novo, $senha);
    }

    public function test_operador_so_se_cria_com_perfil_restrito_a_plataforma(): void
    {
        config(['erp.saas.ativo' => true]);
        [, $sessao] = $this->superAdmin();
        $perfilNormal = $this->criarPerfil(['_v2' => true, 'lancamentos_view' => true], 'Perfil de empresa');

        $this->postJson('/api/plataforma/operadores-plataforma', [
            'nome_utilizador' => 'operador_invalido', 'perfil_utilizador_id' => $perfilNormal->id,
        ], $sessao)->assertStatus(422);
    }
}
