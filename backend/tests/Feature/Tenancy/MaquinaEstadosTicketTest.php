<?php

namespace Tests\Feature\Tenancy;

use App\Models\PerfilUtilizador;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;
use Tests\TestCase;

/**
 * Fase 6 da extensão SaaS (segurança.txt, secção 13.3): máquina de estados dos tickets — valida transições,
 * mantém o fluxo simples já em produção (ABERTO directo para EM_CURSO) e acrescenta a categoria técnica (ADR-077).
 */
final class MaquinaEstadosTicketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.saas.ativo' => true]);
        app(ContextoTenant::class)->definir(1);
    }

    private function ticket(string $categoria = 'GERAL', string $estado = 'ABERTO'): TicketSuporte
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente MET '.uniqid(), 'slug' => 'cliente-met-'.uniqid()]);

        return TicketSuporte::query()->create(['tenant_id' => $cliente->id, 'assunto' => 'Teste', 'categoria' => $categoria, 'estado' => $estado, 'criado_por' => 'cliente.user']);
    }

    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function superAdmin(): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return [$u, $this->entrar($u)];
    }

    public function test_fluxo_simples_aberto_para_em_curso_continua_valido(): void
    {
        [, $super] = $this->superAdmin();
        $ticket = $this->ticket();

        $this->putJson("/api/plataforma/suporte/{$ticket->id}", ['estado' => 'EM_CURSO'], $super)->assertOk();
    }

    public function test_transicao_directa_de_aberto_para_resolvido_e_permitida_fluxo_simples(): void
    {
        // um pedido trivial pode ser resolvido de imediato, sem passar pelos estados intermédios — fluxo já em
        // produção, confirmado por PlataformaGestaoTest > suporte_cria_actualiza_filtra_e_elimina.
        [, $super] = $this->superAdmin();
        $ticket = $this->ticket();

        $this->putJson("/api/plataforma/suporte/{$ticket->id}", ['estado' => 'RESOLVIDO'], $super)->assertOk();
    }

    public function test_fechado_so_transiciona_para_reaberto(): void
    {
        [, $super] = $this->superAdmin();
        $ticket = $this->ticket(estado: 'FECHADO');

        $this->putJson("/api/plataforma/suporte/{$ticket->id}", ['estado' => 'ABERTO'], $super)
            ->assertStatus(422)->assertJsonPath('codigo', 'TRANSICAO_INVALIDA');
        $this->putJson("/api/plataforma/suporte/{$ticket->id}", ['estado' => 'REABERTO'], $super)->assertOk();
    }

    public function test_cancelado_e_terminal_sem_transicoes_possiveis(): void
    {
        [, $super] = $this->superAdmin();
        $ticket = $this->ticket(estado: 'CANCELADO');

        $this->putJson("/api/plataforma/suporte/{$ticket->id}", ['estado' => 'EM_CURSO'], $super)
            ->assertStatus(422)->assertJsonPath('codigo', 'TRANSICAO_INVALIDA');
    }

    public function test_transferencia_de_ticket_para_outro_agente_e_registo_de_tempo_de_atendimento(): void
    {
        [, $super] = $this->superAdmin();
        $ticket = $this->ticket();
        $agenteA = $this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE]);
        $agenteB = $this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE]);

        $this->putJson("/api/plataforma/suporte/{$ticket->id}", ['agente_id' => $agenteA->id], $super)
            ->assertOk()->assertJsonPath('dados.agente_id', $agenteA->id);

        // Transferência: reatribuir a outro agente é só mudar agente_id (ponto 283 "Transferir tickets").
        $this->putJson("/api/plataforma/suporte/{$ticket->id}", ['agente_id' => $agenteB->id, 'tempo_atendimento_minutos' => 45], $super)
            ->assertOk()->assertJsonPath('dados.agente_id', $agenteB->id)->assertJsonPath('dados.tempo_atendimento_minutos', 45);

        $this->assertSame($agenteB->id, $ticket->fresh()->agente_id);
        $this->assertSame(45, $ticket->fresh()->tempo_atendimento_minutos);
    }

    public function test_agente_tecnico_ve_categoria_tecnica_mas_nao_geral_nem_financeiro(): void
    {
        $perfil = PerfilUtilizador::create(['nome' => 'Agente Técnico Teste', 'permissoes' => ['_v2' => true, 'suporte_tickets_tecnico_gerir' => true], 'restrito_plataforma' => true]);
        $agente = $this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE, 'perfil_utilizador_id' => $perfil->id]);
        $sessao = $this->entrar($agente);

        $tecnico = $this->ticket('INCIDENTE_TECNICO');
        $geral = $this->ticket('GERAL');

        $this->getJson('/api/suporte/tickets', $sessao)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.id', $tecnico->id);
        $this->putJson("/api/suporte/tickets/{$geral->id}", ['estado' => 'EM_CURSO'], $sessao)->assertForbidden();
        $this->putJson("/api/suporte/tickets/{$tecnico->id}", ['estado' => 'EM_CURSO'], $sessao)->assertOk();
    }
}
