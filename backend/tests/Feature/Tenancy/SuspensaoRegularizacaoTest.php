<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\Utilizador;
use Tests\TestCase;

/**
 * Fase 2 da extensão SaaS (segurança.txt, secção 10): suspensão com regularização. Uma empresa suspensa, cancelada
 * ou em encerramento fica só de leitura — nunca bloqueio total — e as rotas de licença (regularizar, suporte) ficam
 * sempre acessíveis. A transição de estado em si é um acto administrativo da plataforma, validado e auditado.
 */
class SuspensaoRegularizacaoTest extends TestCase
{
    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function admin(): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return [$u, $this->entrar($u)];
    }

    public function test_so_a_plataforma_transiciona_o_estado_da_licenca(): void
    {
        config(['erp.saas.ativo' => true]);
        app(\App\Support\Tenancy\ContextoTenant::class)->definir(1);
        $empresa = $this->criarEmpresa(['nif' => '5000000301']);
        $comum = $this->entrar($this->criarUtilizador());

        $this->postJson("/api/plataforma/saas/empresas/{$empresa->id}/estado-licenca", ['estado' => Empresa::LICENCA_SUSPENSA, 'motivo' => Empresa::MOTIVO_ADMINISTRATIVA], $comum)
            ->assertForbidden();

        [, $super] = $this->admin();
        $this->postJson("/api/plataforma/saas/empresas/{$empresa->id}/estado-licenca", ['estado' => Empresa::LICENCA_SUSPENSA, 'motivo' => Empresa::MOTIVO_ADMINISTRATIVA], $super)
            ->assertOk()->assertJsonPath('dados.estado_licenca', Empresa::LICENCA_SUSPENSA)->assertJsonPath('dados.motivo_suspensao', Empresa::MOTIVO_ADMINISTRATIVA);

        $empresa->refresh();
        $this->assertSame(Empresa::LICENCA_SUSPENSA, $empresa->estado_licenca);
    }

    public function test_suspender_sem_motivo_ou_com_transicao_invalida_e_rejeitado(): void
    {
        config(['erp.saas.ativo' => true]);
        app(\App\Support\Tenancy\ContextoTenant::class)->definir(1);
        [, $super] = $this->admin();
        $empresa = $this->criarEmpresa(['nif' => '5000000302']);

        $this->postJson("/api/plataforma/saas/empresas/{$empresa->id}/estado-licenca", ['estado' => Empresa::LICENCA_SUSPENSA], $super)
            ->assertStatus(422);

        $this->postJson("/api/plataforma/saas/empresas/{$empresa->id}/estado-licenca", ['estado' => Empresa::LICENCA_ENCERRADA], $super)
            ->assertStatus(422)->assertJsonPath('codigo', 'TRANSICAO_INVALIDA');
    }

    public function test_empresa_suspensa_fica_so_de_leitura_mas_suporte_e_regularizacao_continuam_acessiveis(): void
    {
        config(['erp.licencas.ativo' => true, 'erp.saas.ativo' => true]);
        \App\Support\Tenancy\DefinicoesPlataforma::invalidar();
        app(\App\Support\Tenancy\ContextoTenant::class)->definir(1);
        $empresa = $this->criarEmpresa(['nif' => '5000000303']);
        [, $super] = $this->admin();
        $this->postJson("/api/plataforma/saas/empresas/{$empresa->id}/estado-licenca", ['estado' => Empresa::LICENCA_SUSPENSA, 'motivo' => Empresa::MOTIVO_INCUMPRIMENTO], $super)
            ->assertOk();

        $utilizador = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR]);
        $utilizador->empresas()->attach($empresa->id);
        $sessao = $this->entrar($utilizador) + ['X-Empresa-Id' => $empresa->id];

        // leitura continua disponível (nunca bloqueio total)
        $this->getJson('/api/licenca/estado', $sessao)->assertOk();
        // uma operação de escrita fora do namespace da licença fica bloqueada
        $this->postJson('/api/sistema/notificacoes/teste', ['tipo' => 'INFO', 'categoria' => 'SISTEMA', 'titulo' => 'X', 'mensagem' => 'Y'], $sessao)
            ->assertStatus(403)->assertJsonPath('codigo', 'LICENCA_EXPIRADA');
        // mas abrir e responder a um ticket de suporte (rotas licenca.suporte.*) continua disponível, mesmo sendo POST
        $ticketId = $this->postJson('/api/licenca/suporte', ['assunto' => 'Preciso de ajuda', 'descricao' => 'Conta suspensa, como regularizo?'], $sessao)
            ->assertCreated()->json('dados.id');
        $this->assertNotNull($ticketId);
    }

    public function test_cancelamento_antecipado_bloqueia_mesmo_com_licenca_fim_no_futuro(): void
    {
        // a mudança de estado (Fase 1) é o que manda, não só a data: uma empresa cancelada antecipadamente (a meio
        // de um período já pago) fica bloqueada mesmo que `licenca_fim` ainda esteja no futuro.
        config(['erp.licencas.ativo' => true, 'erp.saas.ativo' => true]);
        \App\Support\Tenancy\DefinicoesPlataforma::invalidar();
        app(\App\Support\Tenancy\ContextoTenant::class)->definir(1);
        $empresa = $this->criarEmpresa(['nif' => '5000000304', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->addDays(20)->toDateString()]);
        [, $super] = $this->admin();
        $this->postJson("/api/plataforma/saas/empresas/{$empresa->id}/estado-licenca", ['estado' => Empresa::LICENCA_SUSPENSA, 'motivo' => Empresa::MOTIVO_SEGURANCA], $super)
            ->assertOk();

        $utilizador = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR]);
        $utilizador->empresas()->attach($empresa->id);
        $sessao = $this->entrar($utilizador) + ['X-Empresa-Id' => $empresa->id];

        $this->getJson('/api/licenca/estado', $sessao)->assertOk();
        $this->postJson('/api/sistema/notificacoes/teste', ['tipo' => 'INFO', 'categoria' => 'SISTEMA', 'titulo' => 'X', 'mensagem' => 'Y'], $sessao)
            ->assertStatus(403);
    }

    public function test_atualizar_licenca_pela_plataforma_sincroniza_o_estado_automaticamente(): void
    {
        config(['erp.licencas.ativo' => true]);
        \App\Support\Tenancy\DefinicoesPlataforma::invalidar();
        [, $super] = $this->admin();
        $empresa = $this->criarEmpresa(['nif' => '5000000305', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->subDays(3)->toDateString()]);
        $this->assertSame(Empresa::LICENCA_SUSPENSA, $empresa->fresh()->estado_licenca);

        $this->putJson("/api/plataforma/saas/empresas/{$empresa->id}", ['origem' => Empresa::LICENCA_PLANO, 'dias_adicionar' => 30], $super)
            ->assertOk()->assertJsonPath('dados.estado_licenca', Empresa::LICENCA_ACTIVA);

        $this->assertSame(Empresa::LICENCA_ACTIVA, $empresa->fresh()->estado_licenca);
    }
}
