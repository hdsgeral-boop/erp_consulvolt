<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PerfilUtilizador;
use App\Models\PlanoConta;
use App\Models\Tenant;
use App\Models\Utilizador;
use Tests\TestCase;

final class RegistoEmpresaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_registo_de_nova_empresa_cria_tenant_empresa_administrador_e_devolve_token(): void
    {
        $payload = [
            'nome_comercial' => 'Super Loja Luanda, Lda',
            'nif' => '5418291024',
            'telefone' => '923000111',
            'provincia' => 'Luanda',
            'nome_completo' => 'António Manuel',
            'nome_utilizador' => 'antonio.manuel',
            'email' => 'antonio@superloja.co.ao',
            'palavra_passe' => 'Segredo@2026!Forte',
            'plano' => null,
        ];

        $r = $this->postJson('/api/autenticacao/registar-empresa', $payload);

        $r->assertCreated();
        $r->assertJsonPath('sucesso', true);
        $this->assertNotEmpty($r->json('dados.token'));
        $this->assertNotEmpty($r->json('dados.empresa_id'));

        $this->assertDatabaseHas('tenants', [
            'nome' => 'Super Loja Luanda, Lda',
            'estado' => Tenant::ESTADO_ATIVO,
        ]);

        $this->assertDatabaseHas('empresas', [
            'nome' => 'Super Loja Luanda, Lda',
            'nif' => '5418291024',
            'licenca_origem' => Empresa::LICENCA_TESTE,
        ]);

        $this->assertDatabaseHas('utilizadores', [
            'nome_utilizador' => 'antonio.manuel',
            'email' => 'antonio@superloja.co.ao',
            'papel' => Utilizador::PAPEL_ADMINISTRADOR,
        ]);

        // O administrador da empresa recém-registada tem de ter acesso total (perfil com permissoes.all=true):
        // sem perfil atribuído, ServicoPermissoes::total() é falso e o menu fica vazio (só "Início" aparece).
        $admin = Utilizador::where('nome_utilizador', 'antonio.manuel')->firstOrFail();
        $this->assertNotNull($admin->perfil_utilizador_id);
        $perfil = PerfilUtilizador::findOrFail($admin->perfil_utilizador_id);
        $this->assertTrue(($perfil->permissoes['all'] ?? null) === true);
        $this->assertSame(['*'], $r->json('dados.permissoes'));
    }

    /**
     * Em demonstração a empresa não escolhe plano (o formulário de registo já não tem esse campo): o tenant fica
     * sem plano (`tenant.plano = null`), e `ModulosPlano::doTenant(null)` devolve "sem restrição" — acesso a todos
     * os módulos, não só aos de um plano específico. Só ao assinar um plano pago (depois da demonstração) é que os
     * módulos passam a ficar limitados ao que esse plano inclui.
     */
    public function test_empresa_registada_sem_plano_tem_acesso_a_todos_os_modulos_em_demonstracao(): void
    {
        $payload = [
            'nome_comercial' => 'Empresa Sem Plano, Lda', 'nif' => '5418290099', 'telefone' => '923000444',
            'provincia' => 'Luanda', 'nome_completo' => 'Gestor', 'nome_utilizador' => 'gestor.semplano',
            'email' => 'gestor@semplano.co.ao', 'palavra_passe' => 'Segredo@2026!Forte',
        ];
        $this->postJson('/api/autenticacao/registar-empresa', $payload)->assertCreated();

        $admin = Utilizador::where('nome_utilizador', 'gestor.semplano')->firstOrFail();
        $tenant = Tenant::find($admin->tenant_id);
        $this->assertNull($tenant->plano);

        // null = sem restrição de módulos (ModulosPlano::doTenant/doTenantId): é esta ausência de plano, não um
        // plano específico, que dá acesso a todos os módulos durante a demonstração.
        $this->assertNull(\App\Support\Tenancy\ModulosPlano::doTenant($tenant->plano));
        $this->assertNull(\App\Support\Tenancy\ModulosPlano::doTenantId($tenant->id));
    }

    public function test_dois_registos_reutilizam_o_mesmo_perfil_de_acesso_total(): void
    {
        $payload = fn (string $sufixo, string $nif) => [
            'nome_comercial' => "Empresa {$sufixo}, Lda", 'nif' => $nif,
            'telefone' => '923000111', 'provincia' => 'Luanda',
            'nome_completo' => "Admin {$sufixo}", 'nome_utilizador' => "admin.{$sufixo}",
            'email' => "admin.{$sufixo}@empresa.co.ao", 'palavra_passe' => 'Segredo@2026!Forte',
        ];

        $this->postJson('/api/autenticacao/registar-empresa', $payload('a', '5418290001'))->assertCreated();
        $this->postJson('/api/autenticacao/registar-empresa', $payload('b', '5418290002'))->assertCreated();

        $perfisTotal = PerfilUtilizador::query()->get()->filter(fn (PerfilUtilizador $p) => ($p->permissoes['all'] ?? null) === true);
        $this->assertCount(1, $perfisTotal);
    }

    public function test_empresa_em_demonstracao_fica_limitada_a_um_utilizador(): void
    {
        \App\Models\PlanoLicenca::query()->create(['codigo' => 'basico', 'nome' => 'Básico', 'preco_mensal' => 9500, 'moeda' => 'AOA', 'ativo' => true, 'max_utilizadores' => 5, 'max_empresas' => 2]);

        $payload = [
            'nome_comercial' => 'Loja Em Demonstração, Lda', 'nif' => '5418299001', 'telefone' => '923000333',
            'provincia' => 'Luanda', 'nome_completo' => 'Dona da Loja', 'nome_utilizador' => 'dona.loja',
            'email' => 'dona@loja.co.ao', 'palavra_passe' => 'Segredo@2026!Forte', 'plano' => 'basico',
        ];
        $r = $this->postJson('/api/autenticacao/registar-empresa', $payload)->assertCreated();
        $auth = ['Authorization' => 'Bearer '.$r->json('dados.token'), 'X-Empresa-Id' => $r->json('dados.empresa_id')];
        $perfilTotal = \App\Models\PerfilUtilizador::query()->get()->first(fn ($p) => ($p->permissoes['all'] ?? null) === true);

        // Ainda em demonstração (nenhuma empresa com licença paga): não pode criar um 2.º utilizador, mesmo o plano
        // "basico" permitindo 5 — o limite do período de demonstração é sempre 1, independentemente do plano.
        $this->postJson('/api/sistema/utilizadores', [
            'nome_utilizador' => 'segundo.utilizador', 'palavra_passe' => 'Segredo@2026!Forte', 'perfil_utilizador_id' => $perfilTotal->id,
        ], $auth)->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_PLANO_UTILIZADORES');
    }

    public function test_empresa_nova_recebe_copia_do_plano_de_contas_da_empresa_modelo(): void
    {
        // Em testes (SaaS/RLS desligado), uma empresa criada sem contexto fica no tenant 1 — o mesmo que
        // ServicoPlanoContasModelo usa como modelo (empresa mais antiga do tenant da plataforma).
        $modelo = $this->criarEmpresa();
        app(\App\Support\Tenancy\ContextoEmpresa::class)->executarComo($modelo->id, function () {
            PlanoConta::create(['codigo' => '61', 'descricao' => 'Custo das mercadorias vendidas', 'tipo' => PlanoConta::TIPO_TOTALIZADORA]);
            PlanoConta::create(['codigo' => '611', 'descricao' => 'Mercadorias', 'tipo' => PlanoConta::TIPO_MOVIMENTO]);
        });

        $payload = [
            'nome_comercial' => 'Nova Empresa Com Modelo, Lda', 'nif' => '5418295001', 'telefone' => '923000444',
            'provincia' => 'Luanda', 'nome_completo' => 'Dono Novo', 'nome_utilizador' => 'dono.novo',
            'email' => 'dono@novaempresa.co.ao', 'palavra_passe' => 'Segredo@2026!Forte',
        ];
        $r = $this->postJson('/api/autenticacao/registar-empresa', $payload)->assertCreated();
        $empresaId = (int) $r->json('dados.empresa_id');

        $contexto = app(\App\Support\Tenancy\ContextoEmpresa::class);
        $contas = $contexto->executarComo($empresaId, fn () => PlanoConta::query()->get()->keyBy('codigo'));
        $this->assertCount(2, $contas);
        $this->assertSame('Custo das mercadorias vendidas', $contas['61']->descricao);
        $this->assertSame(PlanoConta::TIPO_MOVIMENTO, $contas['611']->tipo);

        // Independente do modelo: continua com as suas 2 contas, não as da empresa nova.
        $this->assertSame(2, $contexto->executarComo($modelo->id, fn () => PlanoConta::query()->count()));
    }

    public function test_recusa_registo_com_nif_duplicado(): void
    {
        $this->criarEmpresa(['nif' => '5418291024']);

        $payload = [
            'nome_comercial' => 'Outra Empresa, Lda',
            'nif' => '5418291024',
            'telefone' => '923000222',
            'provincia' => 'Benguela',
            'nome_completo' => 'Bernardo Silva',
            'nome_utilizador' => 'bernardo.silva',
            'email' => 'bernardo@outra.co.ao',
            'palavra_passe' => 'Segredo@2026!Forte',
        ];

        $r = $this->postJson('/api/autenticacao/registar-empresa', $payload);

        $r->assertUnprocessable();
        $this->assertArrayHasKey('nif', $r->json('erros') ?? []);
    }
}
