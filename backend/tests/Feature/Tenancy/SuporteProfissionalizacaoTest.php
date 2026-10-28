<?php

namespace Tests\Feature\Tenancy;

use App\Models\PerfilUtilizador;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Profissionalização do backoffice de suporte: SLA com indicador de atraso, atribuição a agente, notas internas
 * (nunca visíveis ao cliente), anexos na conversa, avaliação de satisfação (CSAT) do cliente, dashboard de métricas
 * e respostas rápidas pré-definidas. Isolamento por categoria (ADR-077) já coberto em SuporteEspecializacaoTest.
 */
final class SuporteProfissionalizacaoTest extends TestCase
{
    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function superAdmin(): array
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]);

        return [$u, $this->entrar($u)];
    }

    /** @return array{0: Utilizador, 1: array<string, string>} */
    private function agente(array $chaves = ['suporte_tickets_geral_gerir']): array
    {
        $perfil = PerfilUtilizador::create(['nome' => 'Perfil Teste '.uniqid(), 'permissoes' => ['_v2' => true] + array_fill_keys($chaves, true), 'restrito_plataforma' => true]);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_AGENTE_SUPORTE, 'perfil_utilizador_id' => $perfil->id]);

        return [$u, $this->entrar($u)];
    }

    private function ticket(int $tenantId, array $extra = []): TicketSuporte
    {
        return TicketSuporte::query()->create($extra + ['tenant_id' => $tenantId, 'assunto' => 'Não emite facturas', 'estado' => 'ABERTO', 'criado_por' => 'cliente.user']);
    }

    /** Utilizador super-administrador de um cliente SaaS (para /api/minha-conta/*, middleware `admin_cliente`). */
    private function adminDoCliente(int $tenantId): Utilizador
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $u->forceFill(['tenant_id' => $tenantId])->save();

        return $u->refresh();
    }

    public function test_criar_ticket_calcula_o_prazo_do_sla_pela_prioridade_e_sinaliza_atraso(): void
    {
        [, $sa] = $this->superAdmin();
        config(['erp.saas.ativo' => true]);
        $cliente = Tenant::query()->create(['nome' => 'Cliente SLA', 'slug' => 'cliente-sla']);

        $r = $this->postJson('/api/plataforma/suporte', ['tenant_id' => $cliente->id, 'assunto' => 'Urgente!', 'prioridade' => 'URGENTE'], $sa)->assertCreated();
        $id = $r->json('dados.id');
        $this->assertFalse($r->json('dados.em_atraso'));

        $t = TicketSuporte::findOrFail($id);
        $this->assertEqualsWithDelta(now()->addHours(4)->timestamp, $t->prazo_sla->timestamp, 5);

        $t->update(['prazo_sla' => now()->subHour()]);
        $this->assertTrue($this->getJson('/api/plataforma/suporte', $sa)->assertOk()->json('dados.0.em_atraso'));
    }

    public function test_atribuir_um_ticket_a_um_agente_e_listar_agentes_disponiveis(): void
    {
        [$agenteUtilizador, $as] = $this->agente();
        [, $sa] = $this->superAdmin();
        config(['erp.saas.ativo' => true]);
        $cliente = Tenant::query()->create(['nome' => 'Cliente Atribuicao', 'slug' => 'cliente-atrib']);
        $ticket = $this->ticket($cliente->id);

        $lista = $this->getJson('/api/suporte/agentes', $as)->assertOk()->json('dados');
        $this->assertContains($agenteUtilizador->id, array_column($lista, 'id'));

        $this->putJson("/api/plataforma/suporte/{$ticket->id}", ['agente_id' => $agenteUtilizador->id], $sa)
            ->assertOk()->assertJsonPath('dados.agente_id', $agenteUtilizador->id)->assertJsonPath('dados.agente_nome', $agenteUtilizador->nome_utilizador);

        $this->assertSame($agenteUtilizador->id, $ticket->refresh()->agente_id);
    }

    public function test_notas_internas_ficam_sempre_fora_da_conversa_do_cliente(): void
    {
        [, $as] = $this->agente();
        $cliente = Tenant::query()->create(['nome' => 'Cliente Notas', 'slug' => 'cliente-notas']);
        $admin = $this->adminDoCliente($cliente->id);
        config(['erp.saas.ativo' => true]);
        $cs = $this->entrar($admin);
        $ticket = $this->ticket($cliente->id);

        $this->postJson("/api/suporte/tickets/{$ticket->id}/notas", ['nota' => 'Cliente já teve 2 incidentes parecidos.'], $as)->assertCreated();
        $this->assertCount(1, $this->getJson("/api/suporte/tickets/{$ticket->id}/notas", $as)->assertOk()->json('dados'));

        // o cliente nunca tem acesso às notas internas (rota não existe do lado dele) nem elas aparecem na conversa
        $this->getJson("/api/minha-conta/suporte/{$ticket->id}/notas", $cs)->assertNotFound();
        $conversa = $this->getJson("/api/minha-conta/suporte/{$ticket->id}/mensagens", $cs)->assertOk()->json('dados');
        $this->assertSame([], $conversa);
    }

    public function test_pesquisa_por_texto_na_lista_de_tickets(): void
    {
        [, $sa] = $this->superAdmin();
        config(['erp.saas.ativo' => true]);
        $cliente = Tenant::query()->create(['nome' => 'Cliente Pesquisa', 'slug' => 'cliente-pesq']);
        $this->ticket($cliente->id, ['assunto' => 'Erro ao emitir SAF-T']);
        $this->ticket($cliente->id, ['assunto' => 'Pedido de novo utilizador']);

        $this->assertSame(['Erro ao emitir SAF-T'], $this->getJson('/api/plataforma/suporte?pesquisa=SAF-T', $sa)->assertOk()->json('dados.*.assunto'));
    }

    public function test_anexos_na_conversa_aceitam_imagem_e_pdf_mas_recusam_outros_tipos(): void
    {
        Storage::fake(\App\Services\Plataforma\ServicoAnexosSuporte::DISCO);
        [, $as] = $this->agente();
        config(['erp.saas.ativo' => true]);
        $cliente = Tenant::query()->create(['nome' => 'Cliente Anexos', 'slug' => 'cliente-anexos']);
        $ticket = $this->ticket($cliente->id);

        $imagem = UploadedFile::fake()->image('ecra.png', 10, 10);
        $r = $this->postJson("/api/suporte/tickets/{$ticket->id}/mensagens", ['mensagem' => 'Veja o anexo.', 'ficheiros' => [$imagem]], $as)->assertCreated();
        $anexoId = $r->json('dados.anexos.0.id');
        $this->assertNotNull($anexoId);
        $anexo = \App\Models\AnexoSuporte::findOrFail($anexoId);
        Storage::disk(\App\Services\Plataforma\ServicoAnexosSuporte::DISCO)->assertExists($anexo->caminho);

        $this->getJson("/api/suporte/anexos/{$anexoId}", $as)->assertOk()->assertHeader('Content-Type', 'image/png');

        $texto = UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload');
        $this->postJson("/api/suporte/tickets/{$ticket->id}/mensagens", ['mensagem' => 'Outro.', 'ficheiros' => [$texto]], $as)->assertStatus(422)->assertJsonPath('codigo', 'ANEXO_TIPO_INVALIDO');
    }

    public function test_csat_so_depois_de_resolvido_e_uma_unica_vez(): void
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente CSAT', 'slug' => 'cliente-csat']);
        $admin = $this->adminDoCliente($cliente->id);
        config(['erp.saas.ativo' => true]);
        $cs = $this->entrar($admin);
        $ticket = $this->ticket($cliente->id);

        $this->postJson("/api/minha-conta/suporte/{$ticket->id}/avaliacao", ['nota' => 5], $cs)->assertStatus(422)->assertJsonPath('codigo', 'TICKET_NAO_RESOLVIDO');

        $ticket->update(['estado' => 'RESOLVIDO']);
        $this->assertTrue($this->getJson('/api/minha-conta/suporte', $cs)->assertOk()->json('dados.0.pode_avaliar'));
        $this->postJson("/api/minha-conta/suporte/{$ticket->id}/avaliacao", ['nota' => 5, 'comentario' => 'Muito bom!'], $cs)
            ->assertOk()->assertJsonPath('dados.csat_nota', 5);

        $this->postJson("/api/minha-conta/suporte/{$ticket->id}/avaliacao", ['nota' => 1], $cs)->assertStatus(422)->assertJsonPath('codigo', 'TICKET_JA_AVALIADO');
        $this->assertFalse($this->getJson('/api/minha-conta/suporte', $cs)->assertOk()->json('dados.0.pode_avaliar'));
    }

    /**
     * Qualquer utilizador autenticado da empresa (não só o administrador) abre e conversa um ticket via
     * /api/licenca/suporte — mesmos tickets de /api/minha-conta/suporte (por tenant_id), porta de entrada mais
     * aberta: um utilizador normal (papel UTILIZADOR) não passa no admin_cliente de /minha-conta.
     */
    public function test_qualquer_utilizador_da_empresa_abre_e_conversa_um_ticket_via_licenca_suporte(): void
    {
        $cliente = Tenant::query()->create(['nome' => 'Cliente Licença', 'slug' => 'cliente-licenca']);
        $funcionario = $this->criarUtilizador(['papel' => Utilizador::PAPEL_UTILIZADOR]);
        $funcionario->forceFill(['tenant_id' => $cliente->id])->save();
        config(['erp.saas.ativo' => true]);
        $empresa = app(ContextoTenant::class)->executarComo($cliente->id, fn () => $this->criarEmpresa());
        $funcionario->empresas()->attach($empresa->id);
        $fs = $this->entrar($funcionario) + ['X-Empresa-Id' => $empresa->id];

        // /minha-conta/suporte continua reservado ao administrador — um utilizador normal não entra.
        $this->getJson('/api/minha-conta/suporte', $fs)->assertForbidden();

        // /licenca/suporte está aberto a qualquer utilizador autenticado da empresa.
        $id = $this->postJson('/api/licenca/suporte', ['assunto' => 'Como faço X?', 'categoria' => 'GERAL'], $fs)
            ->assertCreated()->json('dados.id');
        $this->assertSame([], $this->getJson("/api/licenca/suporte/{$id}/mensagens", $fs)->assertOk()->json('dados'));
        $this->postJson("/api/licenca/suporte/{$id}/mensagens", ['mensagem' => 'Obrigado'], $fs)->assertCreated();
        $this->assertCount(1, $this->getJson('/api/licenca/suporte', $fs)->assertOk()->json('dados'));

        // nunca os tickets de outro cliente.
        $outroCliente = Tenant::query()->create(['nome' => 'Outro Cliente Licença', 'slug' => 'outro-cliente-licenca']);
        $outroFuncionario = $this->criarUtilizador(['papel' => Utilizador::PAPEL_UTILIZADOR]);
        $outroFuncionario->forceFill(['tenant_id' => $outroCliente->id])->save();
        $outraEmpresa = app(ContextoTenant::class)->executarComo($outroCliente->id, fn () => $this->criarEmpresa());
        $outroFuncionario->empresas()->attach($outraEmpresa->id);
        $this->getJson("/api/licenca/suporte/{$id}/mensagens", $this->entrar($outroFuncionario) + ['X-Empresa-Id' => $outraEmpresa->id])->assertNotFound();
    }

    public function test_dashboard_de_metricas_conta_por_estado_e_por_agente(): void
    {
        [$agenteUtilizador, $as] = $this->agente();
        config(['erp.saas.ativo' => true]);
        $cliente = Tenant::query()->create(['nome' => 'Cliente Metricas', 'slug' => 'cliente-met']);
        $this->ticket($cliente->id, ['estado' => 'ABERTO']);
        $this->ticket($cliente->id, ['estado' => 'RESOLVIDO', 'agente_id' => $agenteUtilizador->id]);

        $m = $this->getJson('/api/suporte/metricas', $as)->assertOk()->json('dados');
        $this->assertSame(1, $m['por_estado']['ABERTO']);
        $this->assertSame(1, $m['por_estado']['RESOLVIDO']);
        $this->assertSame(1, collect($m['por_agente'])->firstWhere('agente_id', $agenteUtilizador->id)['total']);
    }

    public function test_respostas_rapidas_sao_um_catalogo_partilhado_pela_equipa(): void
    {
        [, $as] = $this->agente();
        config(['erp.saas.ativo' => true]);

        $id = $this->postJson('/api/suporte/respostas-rapidas', ['titulo' => 'Pedir acesso remoto', 'texto' => 'Pode autorizar...'], $as)->assertCreated()->json('dados.id');
        $this->assertCount(1, $this->getJson('/api/suporte/respostas-rapidas', $as)->assertOk()->json('dados'));
        $this->putJson("/api/suporte/respostas-rapidas/{$id}", ['titulo' => 'Pedir acesso remoto', 'texto' => 'Texto actualizado.'], $as)->assertOk();
        $this->deleteJson("/api/suporte/respostas-rapidas/{$id}", [], $as)->assertOk();
        $this->assertCount(0, $this->getJson('/api/suporte/respostas-rapidas', $as)->assertOk()->json('dados'));
    }
}
