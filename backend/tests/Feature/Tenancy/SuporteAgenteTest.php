<?php

namespace Tests\Feature\Tenancy;

use App\Mail\AgenteSuporteCriadoMail;
use App\Models\PerfilUtilizador;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Backoffice de suporte isolado (ADR-076, fase 4 fatia 3): um agente de suporte só vê e responde a tickets
 * (/api/suporte/*) — nunca clientes, planos, administradores nem definições (/api/plataforma/*, continua só
 * super-administrador). Os agentes são geridos pelos super-administradores (/api/plataforma/agentes-suporte). A
 * especialização por categoria (ADR-077) está em SuporteEspecializacaoTest.
 */
final class SuporteAgenteTest extends TestCase
{
    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function superAdmin(): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return [$u, $this->entrar($u)];
    }

    private function perfilSuporte(array $chaves): PerfilUtilizador
    {
        return PerfilUtilizador::create(['nome' => 'Perfil Teste '.uniqid(), 'permissoes' => ['_v2' => true] + array_fill_keys($chaves, true), 'restrito_plataforma' => true]);
    }

    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function agente(?int $perfilUtilizadorId = null): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE, 'perfil_utilizador_id' => $perfilUtilizadorId]);

        return [$u, $this->entrar($u)];
    }

    public function test_so_super_administrador_gere_agentes_de_suporte(): void
    {
        [, $sa] = $this->superAdmin();
        [, $agenteSessao] = $this->agente();
        $comum = $this->entrar($this->criarUtilizador());
        $perfil = $this->perfilSuporte(['suporte_tickets_geral_gerir']);
        Mail::fake();

        $this->getJson('/api/plataforma/agentes-suporte', $comum)->assertForbidden();
        $this->getJson('/api/plataforma/agentes-suporte', $agenteSessao)->assertForbidden();

        $this->postJson('/api/plataforma/agentes-suporte', ['nome_utilizador' => 'sem.perfil', 'palavra_passe' => 'SenhaForte123'], $sa)->assertStatus(422);

        $id = $this->postJson('/api/plataforma/agentes-suporte', [
            'nome_utilizador' => 'novo.agente', 'palavra_passe' => 'SenhaForte123', 'email' => 'agente@consulvolt.ao', 'perfil_utilizador_id' => $perfil->id,
        ], $sa)->assertCreated()->assertJsonPath('dados.nome_utilizador', 'novo.agente')->assertJsonPath('dados.perfil.id', $perfil->id)->json('dados.id');
        Mail::assertQueued(AgenteSuporteCriadoMail::class, fn ($m) => $m->hasTo('agente@consulvolt.ao'));

        $this->assertSame(Utilizador::PAPEL_AGENTE_SUPORTE, Utilizador::findOrFail($id)->papel);
        $this->putJson("/api/plataforma/agentes-suporte/{$id}/estado", ['ativo' => false], $sa)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->deleteJson("/api/plataforma/agentes-suporte/{$id}", [], $sa)->assertOk();
    }

    public function test_agente_de_suporte_fica_isolado_da_administracao_da_plataforma(): void
    {
        [, $as] = $this->agente();
        config(['erp.saas.ativo' => true]);

        foreach (['clientes', 'planos', 'administradores', 'sessoes', 'definicoes', 'agentes-suporte', 'auditoria'] as $rota) {
            $this->getJson("/api/plataforma/{$rota}", $as)->assertForbidden();
        }
        $this->getJson('/api/plataforma/painel', $as)->assertForbidden();
    }

    public function test_agente_de_suporte_ve_e_responde_tickets_mas_nao_cria_nem_elimina(): void
    {
        $perfil = $this->perfilSuporte(['suporte_tickets_geral_gerir']);
        [, $as] = $this->agente($perfil->id);
        config(['erp.saas.ativo' => true]);
        $cliente = Tenant::query()->create(['nome' => 'Cliente Suporte', 'slug' => 'cliente-suporte']);
        $ticket = TicketSuporte::query()->create(['tenant_id' => $cliente->id, 'assunto' => 'Não emite facturas', 'estado' => 'ABERTO', 'criado_por' => 'cliente.user']);

        $this->getJson('/api/suporte/tickets', $as)->assertOk()->assertJsonCount(1, 'dados');
        $this->getJson("/api/suporte/tickets/{$ticket->id}/mensagens", $as)->assertOk();
        $this->postJson("/api/suporte/tickets/{$ticket->id}/mensagens", ['mensagem' => 'Já estamos a ver o caso.'], $as)->assertCreated();
        $this->assertSame('EM_CURSO', $ticket->refresh()->estado);
        $this->putJson("/api/suporte/tickets/{$ticket->id}", ['estado' => 'RESOLVIDO'], $as)->assertOk();
        $this->assertSame('RESOLVIDO', $ticket->refresh()->estado);

        // Nunca ficou exposto um caminho para abrir ou eliminar tickets no backoffice de suporte (405: a rota de
        // listar/actualizar existe, só não para estes métodos — a eliminar()/store() continuam só em /plataforma/suporte).
        $this->postJson('/api/suporte/tickets', ['tenant_id' => $cliente->id, 'assunto' => 'Tentativa'], $as)->assertMethodNotAllowed();
        $this->deleteJson("/api/suporte/tickets/{$ticket->id}", [], $as)->assertMethodNotAllowed();
    }

    public function test_super_administrador_tambem_usa_o_backoffice_de_suporte(): void
    {
        [, $sa] = $this->superAdmin();
        config(['erp.saas.ativo' => true]);
        Tenant::query()->create(['nome' => 'Outro Cliente', 'slug' => 'outro-cliente-sup']);

        $this->getJson('/api/suporte/tickets', $sa)->assertOk();
    }

    public function test_utilizador_comum_nao_acede_ao_backoffice_de_suporte(): void
    {
        $comum = $this->entrar($this->criarUtilizador());

        $this->getJson('/api/suporte/tickets', $comum)->assertForbidden();
    }
}
