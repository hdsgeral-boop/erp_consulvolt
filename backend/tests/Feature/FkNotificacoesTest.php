<?php

namespace Tests\Feature;

use App\Mail\NotificacaoRHMail;
use App\Models\AssuntoMetodoTrabalho;
use App\Models\DelegacaoChefia;
use App\Models\MultaEntregavel;
use App\Models\NotificacaoSistema;
use App\Models\PedidoPortalColaborador;
use App\Models\PlanoFeriasColaborador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\CenarioFaseK;
use Tests\TestCase;

/**
 * Fase K (ADR-079) — notificações de RH, Método e Estrutura (NotificadorRH): um teste por evento principal, a notificação dirigida
 * chega sempre ao destinatário (mesmo sem permissão para ver o módulo), uma falha a notificar nunca falha a operação de negócio,
 * a ponte de e-mail opcional e o comando diário de alertas (idempotente).
 */
final class FkNotificacoesTest extends TestCase
{
    use CenarioFaseK;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenarioK();
    }

    /** Títulos das notificações persistentes por ler de quem fez o pedido (as dirigidas e as da empresa). @return list<string> */
    private function titulos(string $quem): array
    {
        return collect($this->getJson('/api/sistema/notificacoes?apenas_nao_lidas=1&origem=EVENTO_EMPRESA&limite=100', $this->s[$quem])->assertOk()->json('dados.notificacoes'))->pluck('titulo')->all();
    }

    private function contar(string $titulo, ?string $mensagemContem = null): int
    {
        return $this->em(fn () => NotificacaoSistema::query()->where('titulo', $titulo)->when($mensagemContem, fn ($q, $t) => $q->where('mensagem', 'like', "%{$t}%"))->count());
    }

    private function ferias(array $h, string $de = '2026-04-06', string $ate = '2026-04-10'): array
    {
        return $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'FERIAS', 'data_inicio' => $de, 'data_fim' => $ate], $h)->assertCreated()->json('dados');
    }

    private function assunto(): array
    {
        $base = ['titulo' => 'Renovar o contrato de limpeza', 'responsaveis' => [['tipo' => 'COLABORADOR', 'ref_id' => $this->ids['ana'], 'nome' => 'Ana']], 'responsavel_principal' => "COLABORADOR:{$this->ids['ana']}",
            'data_inicio' => now()->toDateString(), 'data_fim' => now()->addDays(10)->toDateString(), 'local_tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'fundamentacao' => str_repeat('O contrato actual termina no fim do mês e a limpeza é indispensável. ', 2), 'recursos' => 'Proposta do fornecedor', 'custo_estimado' => 1000];

        return $this->postJson('/api/metodo/assuntos', $base, $this->s['ana'])->assertCreated()->json('dados.assunto');
    }

    #[Test]
    public function pedido_do_portal_avisa_o_aprovador_a_etapa_decidida_o_requerente_e_o_rh(): void
    {
        $p = $this->ferias($this->s['ana']);
        $this->assertSame(['Novo pedido de férias de Ana'], $this->titulos('chefe'));   // a chefia designada
        $this->assertSame([], $this->titulos('director'));
        $this->assertSame([], $this->titulos('rh'));   // a etapa ainda é da chefia
        $this->assertSame([], $this->titulos('ana'));

        // chefia aprova → o requerente sabe e o RH (rh_portal_aprovar) é avisado de que é a sua vez
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['chefe'])->assertOk()->assertJsonPath('dados.estado', 'PENDENTE_RH');
        $this->assertSame(['Pedido aprovado pela chefia'], $this->titulos('ana'));
        $this->assertContains('Novo pedido de férias de Ana', $this->titulos('rh'));
        $this->assertContains('Novo pedido de férias de Ana', $this->titulos('suplente'));   // também tem rh_portal_aprovar
        $this->assertSame([], $this->titulos('rui'));
        // RH aprova
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['rh'])->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $this->assertSame(['Pedido aprovado', 'Pedido aprovado pela chefia'], collect($this->titulos('ana'))->sort()->values()->all());

        // recusa, com o motivo; o requerente que também tem rh_portal_aprovar não é avisado do próprio pedido
        $q = $this->ferias($this->s['ana'], '2026-05-04', '2026-05-08');
        $this->postJson("/api/rh/portal/pedidos/{$q['id']}/decidir", ['decisao' => 'RECUSADO', 'nota' => 'Período de fecho de contas'], $this->s['chefe'])->assertOk();
        $this->assertSame(1, $this->contar('Pedido recusado', 'Período de fecho de contas'));
        $this->ferias($this->s['suplente'], '2026-06-01', '2026-06-05');
        $this->assertNotContains('Novo pedido de férias de Suplente', $this->titulos('suplente'));
        $this->assertSame(1, $this->contar('Novo pedido de férias de Suplente'));   // só a chefia

        // o utilizador só lê e marca as suas: o Rui não marca a notificação da Ana
        $id = $this->em(fn () => NotificacaoSistema::query()->where('titulo', 'Pedido aprovado')->value('id'));
        $this->postJson("/api/sistema/notificacoes/{$id}/ler", [], $this->s['rui'])->assertOk();
        $this->assertFalse($this->em(fn () => (bool) NotificacaoSistema::query()->find($id)->lida));
        $this->postJson("/api/sistema/notificacoes/{$id}/ler", [], $this->s['ana'])->assertOk();
        $this->assertTrue($this->em(fn () => (bool) NotificacaoSistema::query()->find($id)->lida));
        $this->assertNotContains('Pedido aprovado', $this->titulos('ana'));
        $this->assertSame(2, $this->getJson('/api/sistema/notificacoes?apenas_nao_lidas=1&origem=EVENTO_EMPRESA', $this->s['ana'])->json('dados.nao_lidas_bd'));   // por ler: aprovado pela chefia + recusado
    }

    #[Test]
    public function metodo_submetido_avisa_o_aprovador_e_a_decisao_o_criador(): void
    {
        $a = $this->assunto();
        $this->postJson("/api/metodo/assuntos/{$a['id']}/submeter", [], $this->s['ana'])->assertOk();
        $numero = $this->em(fn () => AssuntoMetodoTrabalho::query()->findOrFail($a['id'])->numero);
        $this->assertSame(["Assunto por aprovar: {$numero}"], $this->titulos('chefe'));
        $this->assertSame([], $this->titulos('director'));
        $this->postJson("/api/metodo/assuntos/{$a['id']}/decidir", ['accao' => 'DEVOLVER', 'nota' => 'Falta o orçamento detalhado'], $this->s['chefe'])->assertOk();
        // o criador é também o responsável principal: uma só notificação; quem decidiu não é avisado
        $this->assertSame(["Assunto devolvido para correcção: {$numero}"], $this->titulos('ana'));
        $this->assertSame(["Assunto por aprovar: {$numero}"], $this->titulos('chefe'));
        $this->assertSame(1, $this->contar("Assunto devolvido para correcção: {$numero}", 'Falta o orçamento detalhado'));
        $n = collect($this->getJson('/api/sistema/notificacoes?apenas_nao_lidas=1&origem=EVENTO_EMPRESA', $this->s['ana'])->json('dados.notificacoes'))->firstWhere('categoria', 'metodo');
        $this->assertSame(['metodo', '/m/metodo/mt_assuntos'], [$n['categoria'], $n['link']]);   // a Ana só tem consulta de Método: a dirigida chega-lhe sempre
    }

    #[Test]
    public function entregas_e_impedimentos_avisam_quem_valida_e_o_autor_mesmo_sem_ver_o_modulo(): void
    {
        $x = $this->postJson("/api/rh/portal/entregaveis/{$this->ids['entregavel']}/execucoes", ['quantidade' => 1, 'data' => '2026-03-02'], $this->s['ana'])->assertCreated()->json('dados');
        $this->assertSame(['Entrega por validar'], $this->titulos('chefe'));
        $this->assertSame(['Entrega por validar'], $this->titulos('director'));   // a unidade acima também responde
        $this->assertSame([], $this->titulos('ana'));   // nunca o executor
        $this->assertSame([], $this->titulos('rui'));

        $this->postJson("/api/rh/portal/execucoes/{$x['id']}/validar", ['decisao' => 'DEVOLVIDA', 'motivo' => 'Falta o anexo'], $this->s['chefe'])->assertOk();
        $this->assertSame(['Entrega devolvida para correcção'], $this->titulos('ana'));   // a Ana não tem permissões de Estrutura: chega na mesma
        $this->assertSame(1, $this->contar('Entrega devolvida para correcção', 'Falta o anexo'));
        $this->postJson("/api/rh/portal/execucoes/{$x['id']}/reenviar", [], $this->s['ana'])->assertOk();
        $this->assertSame(4, $this->contar('Entrega por validar'));   // chefe e director, duas vezes cada (a entrega nova e a reenviada)

        // impedimento: avisa os responsáveis; a decisão avisa o autor
        $i = $this->postJson("/api/rh/portal/entregaveis/{$this->ids['entregavel']}/impedimentos", ['periodo_de' => '2026-02-01', 'motivo' => 'Sem acesso ao sistema'], $this->s['ana'])->assertCreated()->json('dados');
        $this->assertContains('Impedimento por decidir', $this->titulos('chefe'));
        $this->assertContains('Impedimento por decidir', $this->titulos('director'));
        $this->postJson("/api/rh/portal/impedimentos/{$i['id']}/decidir", ['estado' => 'ACEITE'], $this->s['chefe'])->assertOk();
        $this->assertContains('Impedimento aceite', $this->titulos('ana'));
        $this->postJson("/api/rh/portal/execucoes/{$x['id']}/validar", ['decisao' => 'VALIDADA'], $this->s['director'])->assertOk();
        $this->assertContains('Entrega validada', $this->titulos('ana'));
        $n = collect($this->getJson('/api/sistema/notificacoes?apenas_nao_lidas=1&origem=EVENTO_EMPRESA', $this->s['ana'])->json('dados.notificacoes'))->firstWhere('titulo', 'Entrega validada');
        $this->assertSame(['estrutura', 'SUCESSO', 'Estrutura Orgânica'], [$n['categoria'], $n['tipo'], $n['modulo_nome']]);
    }

    #[Test]
    public function multas_propostas_avisam_quem_as_gere_e_a_decisao_o_penalizado(): void
    {
        $this->postJson('/api/rh/estrutura/multas/propor', [], $this->s['rh'])->assertOk()->assertJsonPath('dados.criadas', 1);
        $this->assertSame(['Multas de entregáveis por decidir'], $this->titulos('rh'));   // est_multas_gerir
        $this->assertSame([], $this->titulos('chefe'));
        $id = $this->em(fn () => MultaEntregavel::query()->value('id'));
        $this->postJson("/api/rh/estrutura/multas/{$id}/aplicar", [], $this->s['rh'])->assertOk();
        $this->assertSame(['Multa aplicada'], $this->titulos('ana'));
        $this->postJson("/api/rh/estrutura/multas/{$id}/repor", [], $this->s['rh'])->assertOk();
        $this->postJson("/api/rh/estrutura/multas/{$id}/dispensar", ['motivo' => 'Atraso justificado'], $this->s['rh'])->assertOk();
        $this->assertContains('Multa dispensada', $this->titulos('ana'));
        // propor outra vez não cria nada (já existe) e não volta a avisar
        $antes = $this->contar('Multas de entregáveis por decidir');
        $this->postJson('/api/rh/estrutura/multas/propor', [], $this->s['rh'])->assertOk()->assertJsonPath('dados.criadas', 0);
        $this->assertSame($antes, $this->contar('Multas de entregáveis por decidir'));
    }

    #[Test]
    public function delegacao_criada_ou_revogada_avisa_o_delegado_e_o_delegante_se_for_um_terceiro(): void
    {
        $d = $this->postJson('/api/rh/portal/delegacoes', ['delegado_colaborador_id' => $this->ids['suplente'], 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-20'], $this->s['chefe'])->assertCreated()->json('dados');
        $this->assertContains('Delegação de chefia recebida', $this->titulos('suplente'));
        $this->assertNotContains('Delegou a sua chefia', $this->titulos('chefe'));   // foi ele a criar
        $this->postJson("/api/rh/portal/delegacoes/{$d['id']}/revogar", [], $this->s['chefe'])->assertOk();
        $this->assertContains('Delegação de chefia revogada', $this->titulos('suplente'));

        $this->postJson('/api/rh/estrutura/delegacoes', ['delegante_colaborador_id' => $this->ids['director'], 'delegado_colaborador_id' => $this->ids['rui'], 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-12'], $this->s['rh'])->assertCreated();
        $this->assertContains('Delegação de chefia recebida', $this->titulos('rui'));
        $this->assertContains('Delegou a sua chefia', $this->titulos('director'));   // criada por terceiros: o delegante é avisado
    }

    #[Test]
    public function uma_falha_a_notificar_nunca_faz_falhar_a_operacao_de_negocio(): void
    {
        // gravar a notificação lança uma excepção
        Event::listen('eloquent.creating: '.NotificacaoSistema::class, fn () => throw new \RuntimeException('falha ao notificar'));
        $p = $this->ferias($this->s['ana']);
        $this->assertSame('PENDENTE_CHEFIA', $this->em(fn () => PedidoPortalColaborador::query()->findOrFail($p['id'])->estado));
        $this->assertSame(0, $this->contar('Novo pedido de férias de Ana'));
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'RECUSADO', 'nota' => 'Não pode ser'], $this->s['chefe'])->assertOk()->assertJsonPath('dados.estado', 'RECUSADO');

        // um erro de SQL a notificar não envenena a transacção de quem chama (ponto de salvaguarda): o resto da operação continua
        Event::forget('eloquent.creating: '.NotificacaoSistema::class);
        Event::listen('eloquent.creating: '.NotificacaoSistema::class, fn () => DB::select('select * from tabela_que_nao_existe'));
        $q = $this->ferias($this->s['ana'], '2026-05-04', '2026-05-08');
        $this->assertSame('PENDENTE_CHEFIA', $this->em(fn () => PedidoPortalColaborador::query()->findOrFail($q['id'])->estado));
        $x = $this->postJson("/api/rh/portal/entregaveis/{$this->ids['entregavel']}/execucoes", ['quantidade' => 1, 'data' => '2026-03-02'], $this->s['ana'])->assertCreated()->json('dados');
        $this->postJson("/api/rh/portal/execucoes/{$x['id']}/validar", ['decisao' => 'VALIDADA'], $this->s['chefe'])->assertOk()->assertJsonPath('dados.validacao', 'VALIDADA');
    }

    #[Test]
    public function ponte_de_email_desligada_por_omissao_e_em_fila_quando_ligada(): void
    {
        Mail::fake();
        $utilizador = DB::table('utilizador_empresa')->where('colaborador_id', $this->ids['chefe'])->value('utilizador_id');
        DB::table('utilizadores')->where('id', $utilizador)->update(['email' => 'chefe@empresa.ao']);
        $this->assertFalse((bool) config('erp.notificacoes.email'));
        $this->ferias($this->s['ana']);
        Mail::assertNothingQueued();
        $this->assertSame(1, $this->contar('Novo pedido de férias de Ana'));   // o centro de notificações funciona sempre

        config(['erp.notificacoes.email' => true]);
        $this->ferias($this->s['ana'], '2026-05-04', '2026-05-08');
        Mail::assertQueued(NotificacaoRHMail::class, fn (NotificacaoRHMail $m) => $m->hasTo('chefe@empresa.ao') && $m->envelope()->subject === 'Novo pedido de férias de Ana');
        Mail::assertQueuedCount(1);
        $html = (new NotificacaoRHMail('Novo pedido', 'Mensagem de teste', 'http://localhost/m/rh/rh_portal'))->render();
        $this->assertStringContainsString('CONSULVOLT', $html);
        $this->assertStringContainsString('Mensagem de teste', $html);
    }

    #[Test]
    public function comando_de_alertas_avisa_atrasos_ferias_pendencias_e_delegacoes_sem_repetir(): void
    {
        $this->em(function () {
            PlanoFeriasColaborador::create(['colaborador_id' => $this->ids['ana'], 'ano' => 2026, 'data_inicio' => '2026-03-13', 'data_fim' => '2026-03-20', 'dias' => 6, 'direito' => 22, 'estado' => 'APROVADO']);
            DelegacaoChefia::create(['delegante_colaborador_id' => $this->ids['chefe'], 'delegado_colaborador_id' => $this->ids['suplente'], 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-11',
                'ambito' => ['ENTREGAVEIS'], 'estado' => 'ATIVA']);
        });
        $p = $this->ferias($this->s['ana']);
        $this->em(fn () => PedidoPortalColaborador::query()->whereKey($p['id'])->update(['criado_em' => '2026-03-01 09:00:00']));   // pendente há 9 dias
        $a = $this->assunto();
        $this->postJson("/api/metodo/assuntos/{$a['id']}/submeter", [], $this->s['ana'])->assertOk();
        $this->em(function () use ($a) {
            $m = AssuntoMetodoTrabalho::query()->findOrFail($a['id']);
            $m->forceFill(['deliberacao' => array_merge((array) $m->deliberacao, ['pedida_em' => '2026-03-02T09:00:00+00:00'])])->save();
        });
        $antes = $this->em(fn () => NotificacaoSistema::query()->count());

        $this->assertSame(0, Artisan::call('erp:rh:alertas-prazos'));
        $depois = $this->em(fn () => NotificacaoSistema::query()->count());
        $this->assertSame(1, $this->contar('Entregável em atraso'));   // o dono (Ana)
        $this->assertSame(1, $this->contar('As suas férias começam em breve'));
        $this->assertSame(1, $this->contar('Férias da equipa a começar'));   // a chefia
        $this->assertSame(1, $this->contar('Pedido à espera da sua decisão'));   // a chefia aprovadora do pedido pendente
        $this->assertSame(1, $this->contar('Delegação de chefia a terminar'));   // o delegado
        $this->assertSame(1, $this->contar('Delegação da sua chefia a terminar'));   // o delegante
        $numero = $this->em(fn () => AssuntoMetodoTrabalho::query()->findOrFail($a['id'])->numero);
        $this->assertSame(1, $this->contar("Assunto à espera da sua decisão: {$numero}"));
        $this->assertGreaterThan($antes, $depois);

        // idempotente: correr de novo no mesmo dia não repete nada
        $this->assertSame(0, Artisan::call('erp:rh:alertas-prazos'));
        $this->assertSame($depois, $this->em(fn () => NotificacaoSistema::query()->count()));

        // passados 3 dias o lembrete das pendências repete-se (1 vez de 3 em 3 dias); os avisos únicos não
        Carbon::setTestNow('2026-03-14 07:30:00');
        $this->assertSame(0, Artisan::call('erp:rh:alertas-prazos'));
        $this->assertSame(2, $this->contar('Pedido à espera da sua decisão'));
        $this->assertSame(2, $this->contar("Assunto à espera da sua decisão: {$numero}"));
        $this->assertSame(1, $this->contar('Entregável em atraso'));
        $this->assertSame(1, $this->contar('As suas férias começam em breve'));
    }
}
