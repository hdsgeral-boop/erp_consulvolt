<?php

namespace Tests\Feature\Tenancy;

use App\Mail\TicketSlaVioladoMail;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fase 6 da extensão SaaS (segurança.txt, secção 14, ponto 269): o comando `erp:suporte:avisar-sla` avisa a equipa
 * de suporte dos tickets com o prazo de SLA vencido, exactamente uma vez por ticket (nunca duplica o aviso).
 */
final class SlaSuporteAvisarCommandTest extends TestCase
{
    private function ticket(array $atributos = []): TicketSuporte
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente SLA '.uniqid(), 'slug' => 'cliente-sla-'.uniqid()]);

        return TicketSuporte::query()->create(array_merge([
            'tenant_id' => $cliente->id, 'assunto' => 'Teste de SLA', 'categoria' => 'GERAL',
            'prioridade' => 'ALTA', 'estado' => TicketSuporte::ABERTO, 'criado_por' => 'cliente.user',
        ], $atributos));
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(ContextoTenant::class)->definir(1);
    }

    public function test_avisa_ticket_com_sla_vencido_e_marca_para_nunca_duplicar(): void
    {
        Mail::fake();
        $ticket = $this->ticket(['prazo_sla' => now()->subHour()]);

        $this->artisan('erp:suporte:avisar-sla')->assertSuccessful();

        Mail::assertQueued(TicketSlaVioladoMail::class);
        $this->assertNotNull($ticket->fresh()->sla_violado_avisado_em);

        // Corrida outra vez: já está marcado, não pode avisar de novo.
        Mail::fake();
        $this->artisan('erp:suporte:avisar-sla')->assertSuccessful();
        Mail::assertNotQueued(TicketSlaVioladoMail::class);
    }

    public function test_nao_avisa_ticket_dentro_do_prazo(): void
    {
        Mail::fake();
        $this->ticket(['prazo_sla' => now()->addDay()]);

        $this->artisan('erp:suporte:avisar-sla')->assertSuccessful();

        Mail::assertNotQueued(TicketSlaVioladoMail::class);
    }

    public function test_nao_avisa_ticket_ja_num_estado_terminal(): void
    {
        Mail::fake();
        $this->ticket(['prazo_sla' => now()->subHour(), 'estado' => TicketSuporte::RESOLVIDO]);

        $this->artisan('erp:suporte:avisar-sla')->assertSuccessful();

        Mail::assertNotQueued(TicketSlaVioladoMail::class);
    }
}
