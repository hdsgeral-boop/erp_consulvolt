<?php

namespace Tests\Feature\Tenancy;

use App\Models\PerfilUtilizador;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use Tests\TestCase;

/**
 * Especialização do backoffice de suporte (ADR-077): um agente só vê e responde às categorias de tickets que o seu
 * perfil concede (técnico/geral, financeiro, marketing); sem perfil, não vê nenhuma (seguro por omissão); o
 * super-administrador continua a ver tudo. A isolação base (quem entra em /api/suporte/*) está em SuporteAgenteTest.
 */
final class SuporteEspecializacaoTest extends TestCase
{
    private function ticket(string $categoria): TicketSuporte
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente ADR-077 '.uniqid(), 'slug' => 'cliente-adr-077-'.uniqid()]);

        return TicketSuporte::query()->create(['tenant_id' => $cliente->id, 'assunto' => "Ticket {$categoria}", 'categoria' => $categoria, 'estado' => 'ABERTO', 'criado_por' => 'cliente.user']);
    }

    private function perfil(array $chaves): PerfilUtilizador
    {
        return PerfilUtilizador::create(['nome' => 'Perfil Teste '.uniqid(), 'permissoes' => ['_v2' => true] + array_fill_keys($chaves, true), 'restrito_plataforma' => true]);
    }

    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function agente(?int $perfilUtilizadorId): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE, 'perfil_utilizador_id' => $perfilUtilizadorId]);

        return [$u, $this->entrar($u)];
    }

    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function superAdmin(): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return [$u, $this->entrar($u)];
    }

    public function test_agente_financeiro_so_ve_tickets_de_plano_e_facturacao(): void
    {
        [, $s] = $this->agente($this->perfil(['suporte_tickets_financeiro_gerir'])->id);
        config(['erp.saas.ativo' => true]);
        $this->ticket('GERAL');
        $pl = $this->ticket('PLANO');
        $fa = $this->ticket('FACTURACAO');
        $this->ticket('MARKETING');

        $vistos = $this->getJson('/api/suporte/tickets', $s)->assertOk()->json('dados');
        $this->assertSame(collect([$pl->id, $fa->id])->sort()->values()->all(), collect($vistos)->pluck('id')->sort()->values()->all());
    }

    public function test_agente_sem_nenhuma_chave_de_categoria_nao_ve_nada(): void
    {
        [, $s] = $this->agente($this->perfil(['dashboard_view'])->id);   // perfil real, mas sem nenhuma chave de suporte
        config(['erp.saas.ativo' => true]);
        $this->ticket('GERAL');

        $this->getJson('/api/suporte/tickets', $s)->assertOk()->assertJsonCount(0, 'dados');
    }

    public function test_agente_sem_perfil_nenhum_nao_ve_nada(): void
    {
        [, $s] = $this->agente(null);
        config(['erp.saas.ativo' => true]);
        $this->ticket('GERAL');

        $this->getJson('/api/suporte/tickets', $s)->assertOk()->assertJsonCount(0, 'dados');
    }

    public function test_agente_so_responde_a_tickets_da_sua_categoria(): void
    {
        [, $s] = $this->agente($this->perfil(['suporte_tickets_geral_gerir'])->id);
        config(['erp.saas.ativo' => true]);
        $geral = $this->ticket('GERAL');
        $marketing = $this->ticket('MARKETING');

        $this->putJson("/api/suporte/tickets/{$geral->id}", ['estado' => 'EM_CURSO'], $s)->assertOk();
        $this->getJson("/api/suporte/tickets/{$geral->id}/mensagens", $s)->assertOk();
        $this->postJson("/api/suporte/tickets/{$geral->id}/mensagens", ['mensagem' => 'Resposta geral.'], $s)->assertCreated();

        $this->putJson("/api/suporte/tickets/{$marketing->id}", ['estado' => 'EM_CURSO'], $s)->assertForbidden();
        $this->getJson("/api/suporte/tickets/{$marketing->id}/mensagens", $s)->assertForbidden();
        $this->postJson("/api/suporte/tickets/{$marketing->id}/mensagens", ['mensagem' => 'Tentativa.'], $s)->assertForbidden();
    }

    public function test_super_administrador_ve_e_responde_a_todas_as_categorias_sem_perfil_nenhum(): void
    {
        [, $s] = $this->superAdmin();
        config(['erp.saas.ativo' => true]);
        $marketing = $this->ticket('MARKETING');

        $this->getJson('/api/suporte/tickets', $s)->assertOk()->assertJsonCount(1, 'dados');
        $this->putJson("/api/suporte/tickets/{$marketing->id}", ['estado' => 'EM_CURSO'], $s)->assertOk();
    }

    public function test_perfis_suporte_ficam_isolados_dos_perfis_normais(): void
    {
        [, $s] = $this->superAdmin();
        $normal = PerfilUtilizador::create(['nome' => 'Perfil Normal '.uniqid(), 'permissoes' => ['_v2' => true, 'dashboard_view' => true]]);
        $suporte = $this->perfil(['suporte_tickets_geral_gerir']);

        $ids = collect($this->getJson('/api/plataforma/perfis-suporte', $s)->assertOk()->json('dados'))->pluck('id');
        $this->assertTrue($ids->contains($suporte->id));
        $this->assertFalse($ids->contains($normal->id));
    }

    public function test_comando_atribui_perfil_inicial_aos_agentes_sem_perfil_e_simular_nao_grava(): void
    {
        [$semPerfil] = $this->agente(null);
        [$comPerfil] = $this->agente($this->perfil(['suporte_tickets_geral_gerir'])->id);
        $perfilAntes = $comPerfil->perfil_utilizador_id;

        $this->artisan('erp:suporte:atribuir-perfil-inicial --simular')->assertSuccessful();
        $this->assertNull($semPerfil->refresh()->perfil_utilizador_id);

        $this->artisan('erp:suporte:atribuir-perfil-inicial')->assertSuccessful();
        $this->assertNotNull($semPerfil->refresh()->perfil_utilizador_id);
        $this->assertSame($perfilAntes, $comPerfil->refresh()->perfil_utilizador_id);   // quem já tinha perfil não é tocado

        // idempotente: já ninguém fica sem perfil, por isso a segunda chamada não altera nada
        $atribuidoNaPrimeira = $semPerfil->perfil_utilizador_id;
        $this->artisan('erp:suporte:atribuir-perfil-inicial')->assertSuccessful();
        $this->assertSame($atribuidoNaPrimeira, $semPerfil->refresh()->perfil_utilizador_id);
    }
}
