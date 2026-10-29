<?php

namespace Tests\Feature\Tenancy;

use App\Mail\TicketSlaVioladoMail;
use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoClientes;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DefinicoesPlataforma;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fase 9 da extensão SaaS (segurança.txt, secção 24): um teste de integração que percorre a cadeia completa por
 * chamadas HTTP reais (nunca atalhos internos), ligando fases que os testes unitários/feature de cada uma já
 * cobrem isoladamente mas nunca em sequência. NÃO repete o que já está bem testado — ver, por fase, cada ficheiro
 * `tests/Feature/Tenancy/*Test.php` correspondente (Fases 1-8). Aqui o valor é a integração entre fases, não a
 * cobertura de cada peça por si.
 *
 * Cenário de falha/recuperação já garantido por construção, não por teste: não existe NENHUM processo automático
 * que consulte um gateway de pagamento para decidir suspender uma empresa — a suspensão é só por data
 * (MaquinaEstadosLicenca::calcular(), Fase 1/3) ou por acção administrativa explícita (Fase 2). Um timeout de rede
 * com um gateway não tem, por desenho, nenhum caminho de código que possa causar uma suspensão indevida.
 */
final class CicloCompletoSaasTest extends TestCase
{
    public function test_ciclo_completo_registo_pagamento_suspensao_e_regularizacao(): void
    {
        config(['erp.licencas.ativo' => true, 'erp.saas.ativo' => true]);
        DefinicoesPlataforma::invalidar();
        app(ContextoTenant::class)->definir(1);

        // 1. Registo público (empresa → tenant → período de demonstração, Fase 1).
        $registo = $this->postJson('/api/autenticacao/registar-empresa', [
            'nome_comercial' => 'Ciclo Completo SaaS, Lda', 'nif' => '5900000001', 'telefone' => '923000111', 'provincia' => 'Luanda',
            'nome_completo' => 'Dona da Empresa', 'nome_utilizador' => 'dona.ciclo', 'email' => 'dona@ciclo.co.ao',
            'palavra_passe' => 'Segredo@2026!Forte', 'plano' => null,
        ])->assertCreated();
        $tenantId = (int) $registo->json('dados.tenant_id');
        $empresaId = (int) $registo->json('dados.empresa_id');
        $sessaoDona = ['Authorization' => 'Bearer '.$registo->json('dados.token'), 'X-Empresa-Id' => $empresaId];

        $empresa = Empresa::query()->findOrFail($empresaId);
        $this->assertSame(Empresa::LICENCA_TRIAL, $empresa->estado_licenca, 'Período de demonstração: a empresa nasce em TRIAL.');

        // 2. A plataforma atribui um plano comercial (Fase 8: dias de teste e limites do plano).
        app(ContextoTenant::class)->definir(1); // o registo (acima) correu como um pedido HTTP à parte — repor o tenant da plataforma.
        [, $plataforma] = $this->superAdminPlataforma();
        $plano = PlanoLicenca::query()->create(['codigo' => 'ciclo-mensal', 'nome' => 'Mensal', 'preco_mensal' => 20000, 'moeda' => 'AOA', 'ativo' => true, 'max_empresas' => 5, 'max_utilizadores' => 5]);
        $this->putJson("/api/plataforma/clientes/{$tenantId}/plano", ['plano' => $plano->codigo], $plataforma)->assertOk();

        // 3. A própria dona pede a renovação (Fase 0/3: fluxo de cobrança manual, sem geração automática).
        $subscricaoId = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $sessaoDona)
            ->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE')->json('dados.id');

        // 4. Um administrador comum da empresa NÃO confirma o próprio pagamento (só super-administrador — LicencaController::confirmarPagamento).
        $this->postJson("/api/licenca/subscricoes/{$subscricaoId}/confirmar-pagamento", [], $sessaoDona)->assertForbidden();

        // 5. A equipa (super-administrador, revisão manual do comprovativo) confirma — licença passa a PLANO.
        $sessaoRevisor = $this->sessaoSuperAdminNaEmpresa($empresaId);
        $this->postJson("/api/licenca/subscricoes/{$subscricaoId}/confirmar-pagamento", ['metodo' => 'transferencia'], $sessaoRevisor)
            ->assertOk()->assertJsonPath('dados.estado', 'PAGO');
        $this->assertSame(Empresa::LICENCA_PLANO, $empresa->fresh()->licenca_origem);
        $this->assertSame(Empresa::LICENCA_ACTIVA, $empresa->fresh()->estado_licenca);

        // 6. O tempo passa sem nenhuma acção manual: o comando diário (Fase 3) avança o estado por si, sem bloqueio total (Fase 2).
        \Illuminate\Support\Facades\DB::table('empresas')->where('id', $empresaId)->update(['licenca_fim' => now()->subDays(2)->toDateString()]);
        $this->artisan('erp:licencas:sincronizar')->assertSuccessful();
        $this->assertSame(Empresa::LICENCA_SUSPENSA, $empresa->fresh()->estado_licenca);
        $this->assertSame(Empresa::MOTIVO_INCUMPRIMENTO, $empresa->fresh()->motivo_suspensao);

        $this->getJson('/api/licenca/estado', $sessaoDona)->assertOk(); // leitura: nunca bloqueio total
        $this->postJson('/api/sistema/notificacoes/teste', ['tipo' => 'INFO', 'categoria' => 'SISTEMA', 'titulo' => 'X', 'mensagem' => 'Y'], $sessaoDona)
            ->assertStatus(403)->assertJsonPath('codigo', 'LICENCA_EXPIRADA'); // escrita fora da licença: bloqueada
        $ticketRegularizacao = $this->postJson('/api/licenca/suporte', ['assunto' => 'Como regularizo?', 'descricao' => 'A conta ficou suspensa.'], $sessaoDona)
            ->assertCreated()->json('dados.id'); // rotas licenca.* continuam sempre acessíveis, mesmo suspensa

        // 7. Regularização: novo pedido + confirmação reactiva automaticamente (ServicoLicenca::confirmarPagamento → sincronizar()).
        $subscricao2 = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $sessaoDona)->assertCreated()->json('dados.id');
        $this->postJson("/api/licenca/subscricoes/{$subscricao2}/confirmar-pagamento", ['metodo' => 'transferencia'], $sessaoRevisor)->assertOk();
        $this->assertSame(Empresa::LICENCA_ACTIVA, $empresa->fresh()->estado_licenca);
        $this->postJson('/api/sistema/notificacoes/teste', ['tipo' => 'INFO', 'categoria' => 'SISTEMA', 'titulo' => 'X', 'mensagem' => 'Y'], $sessaoDona)->assertCreated();

        $this->assertNotNull($ticketRegularizacao);
    }

    public function test_ciclo_completo_suporte_com_sla_transferencia_e_isolamento_entre_clientes(): void
    {
        config(['erp.saas.ativo' => true]);
        app(ContextoTenant::class)->definir(1);
        [, $plataforma] = $this->superAdminPlataforma();
        $perfilAgenteB = \App\Models\PerfilUtilizador::create(['nome' => 'Agente B', 'permissoes' => ['_v2' => true, 'suporte_tickets_geral_gerir' => true], 'restrito_plataforma' => true]);
        $agenteB = $this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE, 'perfil_utilizador_id' => $perfilAgenteB->id]);

        [, $sessaoA] = $this->clientePlataforma('ciclo-cliente-a');
        [, $sessaoB] = $this->clientePlataforma('ciclo-cliente-b');

        // 1. Cliente A abre um ticket (Fase 6: prazo de SLA calculado pela prioridade, consciente de dias úteis).
        $ticket = $this->postJson('/api/minha-conta/suporte', ['assunto' => 'Falha ao emitir factura', 'descricao' => 'Erro 500'], $sessaoA)
            ->assertCreated()->json('dados.id');
        $prazoInicial = TicketSuporte::query()->findOrFail($ticket)->prazo_sla;
        $this->assertNotNull($prazoInicial, 'O SLA é sempre calculado na criação (Fase 6).');

        // 2. Isolamento: o cliente B nunca vê nem acede ao ticket do cliente A (404, sem revelar que existe).
        $this->getJson("/api/minha-conta/suporte/{$ticket}/mensagens", $sessaoB)->assertNotFound();

        // 3. A plataforma responde (ticket entra em atendimento), atribui e depois TRANSFERE para outro agente,
        //    registando o tempo de atendimento (Fase 6 parte 2, pontos 273/283/289).
        $this->postJson("/api/plataforma/suporte/{$ticket}/mensagens", ['mensagem' => 'Já estamos a verificar.'], $plataforma)->assertCreated();
        $this->assertSame(TicketSuporte::EM_CURSO, TicketSuporte::query()->findOrFail($ticket)->estado);
        $this->putJson("/api/plataforma/suporte/{$ticket}", ['agente_id' => $agenteB->id, 'tempo_atendimento_minutos' => 30], $plataforma)
            ->assertOk()->assertJsonPath('dados.agente_id', $agenteB->id)->assertJsonPath('dados.tempo_atendimento_minutos', 30);

        // 4. Resolve e fecha — fora das filas activas e sem contar mais para "em atraso" (ESTADOS_TERMINAIS).
        $this->putJson("/api/plataforma/suporte/{$ticket}", ['estado' => TicketSuporte::RESOLVIDO], $plataforma)->assertOk();
        $this->putJson("/api/plataforma/suporte/{$ticket}", ['estado' => TicketSuporte::FECHADO], $plataforma)->assertOk();
        $this->assertFalse(TicketSuporte::query()->findOrFail($ticket)->emAtraso());

        // 5. Um segundo ticket, ainda aberto, violando o SLA: o comando avisa a equipa exactamente uma vez.
        Mail::fake();
        $ticket2 = $this->postJson('/api/minha-conta/suporte', ['assunto' => 'Outro pedido'], $sessaoA)->assertCreated()->json('dados.id');
        TicketSuporte::query()->whereKey($ticket2)->update(['prazo_sla' => now()->subHour()]);

        $this->artisan('erp:suporte:avisar-sla')->assertSuccessful();
        Mail::assertQueued(TicketSlaVioladoMail::class, 1);
        $this->artisan('erp:suporte:avisar-sla')->assertSuccessful(); // nunca duplica o aviso
        Mail::assertQueued(TicketSlaVioladoMail::class, 1);
    }

    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function superAdminPlataforma(): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return [$u, $this->entrar($u)];
    }

    /** @return array<string, string> sessão do super-administrador da plataforma, operando sobre uma empresa específica. */
    private function sessaoSuperAdminNaEmpresa(int $empresaId): array
    {
        [, $sessao] = $this->superAdminPlataforma();

        return $sessao + ['X-Empresa-Id' => $empresaId];
    }

    /**
     * Cliente criado directamente pela plataforma (ServicoClientes), como em PlataformaClienteTest. A palavra-
     * passe é sempre gerada pelo sistema: entra com a gerada e troca-a de imediato (ExigirTrocaPassword bloqueia
     * toda a API, excepto sair/eu/trocar-password, enquanto `deve_alterar_password` estiver activo).
     *
     * @return array{0: Tenant, 1: array<string, string>} tenant e sessão já pronta a usar
     */
    private function clientePlataforma(string $slug): array
    {
        $c = app(ServicoClientes::class)->criar([
            'nome' => $slug, 'slug' => $slug,
            'administrador' => ['nome_utilizador' => 'adm_'.$slug],
        ]);
        $sessao = $this->entrar($c['administrador'], $c['palavra_passe']);
        $this->putJson('/api/autenticacao/palavra-passe', [
            'palavra_passe_actual' => $c['palavra_passe'], 'palavra_passe_nova' => 'SenhaForte123', 'palavra_passe_nova_confirmation' => 'SenhaForte123',
        ], $sessao)->assertOk();

        return [$c['cliente'], $sessao];
    }
}
