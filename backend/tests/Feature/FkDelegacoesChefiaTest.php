<?php

namespace Tests\Feature;

use App\Models\AssuntoMetodoTrabalho;
use App\Models\DelegacaoChefia;
use App\Models\LogAuditoria;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\CenarioFaseK;
use Tests\TestCase;

/**
 * Fase K (ADR-079) — delegação da chefia: regras de criação (período, âmbito, sem cadeia, sem sobreposição, delegado com utilizador,
 * quem cria e revoga) e o efeito nos pontos de decisão (portal, Método de Trabalho, entregáveis), só dentro do período e do âmbito,
 * sem decidir o que é do próprio delegado nem furar a segregação CHEFIA → RH, com o rasto `por_delegado_de`.
 */
final class FkDelegacoesChefiaTest extends TestCase
{
    use CenarioFaseK;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenarioK();
    }

    /** Delegação gravada à mão (para testar datas fora do período sem mexer no relógio). */
    private function delegacaoDireta(int $delegante, int $delegado, string $inicio, string $fim, array $ambito = ['PORTAL', 'METODO', 'ENTREGAVEIS'], string $estado = 'ATIVA'): int
    {
        return $this->em(fn () => DelegacaoChefia::create(['delegante_colaborador_id' => $delegante, 'delegado_colaborador_id' => $delegado, 'data_inicio' => $inicio, 'data_fim' => $fim,
            'ambito' => $ambito, 'estado' => $estado])->id);
    }

    private function delegar(array $h, array $extra = []): TestResponse
    {
        return $this->postJson('/api/rh/portal/delegacoes', $extra + ['delegado_colaborador_id' => $this->ids['suplente'], 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-20',
            'ambito' => ['PORTAL', 'METODO', 'ENTREGAVEIS'], 'motivo' => 'Missão fora'], $h);
    }

    #[Test]
    public function candidatos_so_activos_com_utilizador_sem_o_proprio_e_so_id_e_nome(): void
    {
        $r = $this->getJson('/api/rh/portal/delegacoes/candidatos', $this->s['chefe'])->assertOk();
        $ids = array_column($r->json('dados'), 'id');
        $this->assertContains($this->ids['suplente'], $ids);
        $this->assertNotContains($this->ids['chefe'], $ids);
        $this->assertNotContains($this->ids['sem_utilizador'], $ids);
        $this->assertSame(['id', 'nome'], array_keys($r->json('dados.0')));
        // sem colaborador ligado não há «o próprio»
        $this->getJson('/api/rh/portal/delegacoes/candidatos', $this->s['rh'])->assertForbidden();
    }

    #[Test]
    public function regras_de_criacao_listagem_e_revogacao(): void
    {
        $chefe = $this->s['chefe'];
        // sem colaborador ligado não há «o próprio» (o portal exige colaborador)
        $this->delegar($this->s['rh'])->assertForbidden();
        // período: não no passado, fim ≥ início, máximo 90 dias
        $this->delegar($chefe, ['data_inicio' => '2026-03-09'])->assertStatus(422)->assertJsonPath('codigo', 'DATA_NO_PASSADO');
        $this->delegar($chefe, ['data_fim' => '2026-03-09'])->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INVALIDO');
        $this->delegar($chefe, ['data_fim' => '2026-06-08'])->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_DEMASIADO_LONGO');   // 91 dias
        $this->delegar($chefe, ['ambito' => ['PORTAL', 'COMPRAS']])->assertStatus(422);
        $this->delegar($chefe, ['delegado_colaborador_id' => $this->ids['chefe']])->assertStatus(422)->assertJsonPath('codigo', 'DELEGADO_INVALIDO');
        $this->delegar($chefe, ['delegado_colaborador_id' => $this->ids['sem_utilizador']])->assertStatus(422)->assertJsonPath('codigo', 'DELEGADO_SEM_UTILIZADOR');
        $this->delegar($chefe, ['delegado_colaborador_id' => 999999])->assertStatus(422)->assertJsonPath('codigo', 'COLABORADOR_INVALIDO');

        // criar (omissão do âmbito = os três); 90 dias exactos valem
        $d = $this->delegar($chefe, ['data_fim' => '2026-06-07', 'ambito' => ['PORTAL', 'ENTREGAVEIS']])->assertCreated()->json('dados');
        $this->assertSame([$this->ids['chefe'], $this->ids['suplente'], ['PORTAL', 'ENTREGAVEIS'], true], [$d['delegante']['id'], $d['delegado']['id'], $d['ambito'], $d['vigente']]);
        // a mesma coisa ou parte dela sobrepõe-se; outro âmbito não
        $this->delegar($chefe, ['ambito' => ['PORTAL']])->assertStatus(422)->assertJsonPath('codigo', 'DELEGACAO_SOBREPOSTA');
        $this->delegar($chefe, ['ambito' => ['METODO']])->assertCreated();
        // sem cadeia: o delegado não delega o que recebeu (no mesmo período e âmbito)
        $this->delegar($this->s['suplente'], ['delegado_colaborador_id' => $this->ids['rui'], 'ambito' => ['PORTAL']])->assertStatus(422)->assertJsonPath('codigo', 'RE_DELEGACAO');
        $this->delegar($this->s['suplente'], ['delegado_colaborador_id' => $this->ids['rui'], 'ambito' => ['METODO']])->assertStatus(422)->assertJsonPath('codigo', 'RE_DELEGACAO');

        // em nome de outro: só com est_delegar (o Chefe não pode pelo Director; o RH sim, e avisa as duas pessoas)
        $porOutro = ['delegante_colaborador_id' => $this->ids['director'], 'delegado_colaborador_id' => $this->ids['rui'], 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-12'];
        $this->postJson('/api/rh/estrutura/delegacoes', $porOutro, $chefe)->assertForbidden()->assertJsonPath('codigo', 'SEM_PERMISSAO');
        $dd = $this->postJson('/api/rh/estrutura/delegacoes', $porOutro, $this->s['rh'])->assertCreated()->assertJsonPath('dados.delegante.nome', 'Director')->json('dados');

        // listagens: o Rui (só o portal) vê apenas as suas; o RH vê todas (filtro por estado)
        $this->assertSame([$dd['id']], array_column($this->getJson('/api/rh/estrutura/delegacoes', $this->s['rui'])->assertOk()->json('dados'), 'id'));
        $this->assertCount(3, $this->getJson('/api/rh/estrutura/delegacoes', $this->s['rh'])->assertOk()->json('dados'));
        $portal = $this->getJson('/api/rh/portal/delegacoes', $this->s['suplente'])->assertOk()->json('dados');
        $this->assertSame([2, 0], [count($portal['recebidas']), count($portal['dadas'])]);
        $this->assertSame(2, $this->getJson('/api/rh/portal/resumo', $this->s['suplente'])->assertOk()->json('dados.delegacoes_recebidas_ativas'));
        $this->assertSame(0, $this->getJson('/api/rh/portal/resumo', $this->s['ana'])->json('dados.delegacoes_recebidas_ativas'));

        // revogar: só o delegante (portal) ou est_delegar; uma vez
        $this->postJson("/api/rh/portal/delegacoes/{$d['id']}/revogar", [], $this->s['rui'])->assertForbidden();
        $this->postJson("/api/rh/portal/delegacoes/{$d['id']}/revogar", [], $chefe)->assertOk()->assertJsonPath('dados.estado', 'REVOGADA');
        $this->postJson("/api/rh/portal/delegacoes/{$d['id']}/revogar", [], $chefe)->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_INVALIDO');
        $this->postJson("/api/rh/estrutura/delegacoes/{$dd['id']}/revogar", [], $chefe)->assertForbidden();
        $this->postJson("/api/rh/estrutura/delegacoes/{$dd['id']}/revogar", [], $this->s['rh'])->assertOk();
        $this->assertSame(1, $this->getJson('/api/rh/portal/resumo', $this->s['suplente'])->json('dados.delegacoes_recebidas_ativas'));   // a do âmbito METODO continua
        // uma delegação revogada deixa de bloquear: pode criar-se outra no mesmo período
        $this->delegar($chefe, ['ambito' => ['PORTAL']])->assertCreated();
    }

    #[Test]
    public function portal_o_delegado_decide_a_etapa_da_chefia_so_no_periodo_e_ambito_e_nunca_o_que_e_seu(): void
    {
        $suplente = $this->s['suplente'];   // tem também rh_portal_aprovar (para a segregação CHEFIA → RH)
        $ana = $this->s['ana'];
        $pedido = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'FERIAS', 'data_inicio' => '2026-04-06', 'data_fim' => '2026-04-10'], $ana)->assertCreated()->json('dados');
        $this->assertSame([$this->ids['chefe'], 'PENDENTE'], [$pedido['etapas'][0]['aprovador_colaborador_id'], $pedido['etapas'][0]['estado']]);
        $decidir = fn (array $h, string $decisao = 'APROVADO') => $this->postJson("/api/rh/portal/pedidos/{$pedido['id']}/decidir", ['decisao' => $decisao], $h);

        // sem delegação: não é dele
        $decidir($suplente)->assertForbidden()->assertJsonPath('codigo', 'SEM_PERMISSAO_ETAPA');
        $this->assertSame(0, $this->getJson('/api/rh/portal/resumo', $suplente)->json('dados.aprovacoes_detalhe.pedidos'));

        // fora do período (acabou / ainda não começou) e fora do âmbito (só Método): 403
        $this->delegacaoDireta($this->ids['chefe'], $this->ids['suplente'], '2026-03-01', '2026-03-05');
        $this->delegacaoDireta($this->ids['chefe'], $this->ids['suplente'], '2026-03-13', '2026-03-20');
        $this->delegacaoDireta($this->ids['chefe'], $this->ids['suplente'], '2026-03-10', '2026-03-20', ['METODO', 'ENTREGAVEIS']);
        $this->delegacaoDireta($this->ids['chefe'], $this->ids['suplente'], '2026-03-10', '2026-03-20', ['PORTAL'], 'REVOGADA');
        $decidir($suplente)->assertForbidden()->assertJsonPath('codigo', 'SEM_PERMISSAO_ETAPA');
        $this->assertSame([], $this->getJson('/api/rh/portal/aprovacoes', $suplente)->assertOk()->json('dados'));

        // dentro do período e do âmbito: vê o pedido «em nome de» e decide
        $this->delegar($this->s['chefe'], ['ambito' => ['PORTAL'], 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-12'])->assertCreated();
        $lista = $this->getJson('/api/rh/portal/aprovacoes', $suplente)->assertOk()->json('dados');
        $this->assertSame([$pedido['id']], array_column($lista, 'id'));
        $this->assertSame($this->ids['chefe'], $lista[0]['em_nome_de']['colaborador_id']);
        $this->assertSame(1, $this->getJson('/api/rh/portal/resumo', $suplente)->json('dados.aprovacoes_detalhe.pedidos'));
        $this->getJson("/api/rh/portal/pedidos/{$pedido['id']}/analise", $suplente)->assertOk();
        $feito = $decidir($suplente)->assertOk()->assertJsonPath('dados.estado', 'PENDENTE_RH')->json('dados');
        $this->assertSame(['Chefe', $this->ids['chefe']], [$feito['etapas'][0]['por_delegado_de']['nome'], $feito['etapas'][0]['por_delegado_de']['colaborador_id']]);
        $this->assertTrue($this->em(fn () => LogAuditoria::query()->where('modulo', 'Portal do Colaborador')->where('detalhes', 'like', '%decidiu em nome de Chefe%')->exists()));

        // segregação CHEFIA → RH: quem aprovou como delegado não aprova também a etapa do RH
        $decidir($suplente)->assertForbidden()->assertJsonPath('codigo', 'SEGREGACAO_ETAPAS');
        $decidir($this->s['rh'])->assertOk()->assertJsonPath('dados.estado', 'APROVADO');

        // nunca o que é seu: o pedido do próprio delegado vai para a chefia dele (o Chefe, que delegou) — o delegado não o decide
        $meu = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'FERIAS', 'data_inicio' => '2026-05-04', 'data_fim' => '2026-05-08'], $suplente)->assertCreated()->json('dados');
        $this->postJson("/api/rh/portal/pedidos/{$meu['id']}/decidir", ['decisao' => 'APROVADO'], $suplente)->assertForbidden()->assertJsonPath('codigo', 'AUTO_APROVACAO');
        $this->assertSame([], array_column($this->getJson('/api/rh/portal/aprovacoes', $suplente)->json('dados'), 'id'));
    }

    #[Test]
    public function metodo_o_delegado_decide_o_assunto_do_designado_so_no_periodo_e_ambito(): void
    {
        $base = ['titulo' => 'Renovar o contrato de limpeza', 'responsaveis' => [['tipo' => 'COLABORADOR', 'ref_id' => $this->ids['ana'], 'nome' => 'Ana']], 'responsavel_principal' => "COLABORADOR:{$this->ids['ana']}",
            'data_inicio' => now()->toDateString(), 'data_fim' => now()->addDays(10)->toDateString(), 'local_tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'fundamentacao' => str_repeat('O contrato actual termina no fim do mês e a limpeza é indispensável. ', 2), 'recursos' => 'Proposta do fornecedor', 'custo_estimado' => 1000];
        $a = $this->postJson('/api/metodo/assuntos', $base, $this->s['ana'])->assertCreated()->json('dados.assunto');
        $this->postJson("/api/metodo/assuntos/{$a['id']}/submeter", [], $this->s['ana'])->assertOk()->assertJsonPath('dados.aprovado', false)->assertJsonPath('dados.aprovador.colaborador_id', $this->ids['chefe']);
        $suplente = $this->s['suplente'];
        $decidir = fn (string $accao = 'APROVAR', string $nota = '') => $this->postJson("/api/metodo/assuntos/{$a['id']}/decidir", ['accao' => $accao, 'nota' => $nota], $suplente);
        $minha = fn () => collect($this->getJson('/api/metodo/assuntos', $suplente)->assertOk()->json('dados.assuntos'))->pluck('id')->all();

        // sem delegação, ou fora do período / âmbito: não vê nem decide
        $decidir()->assertForbidden();
        $this->assertSame([], $minha());
        $this->delegacaoDireta($this->ids['chefe'], $this->ids['suplente'], '2026-03-01', '2026-03-05');
        $this->delegacaoDireta($this->ids['chefe'], $this->ids['suplente'], '2026-03-10', '2026-03-20', ['PORTAL', 'ENTREGAVEIS']);
        $decidir()->assertForbidden();
        $this->assertSame([], $minha());

        // com delegação METODO vigente: vê o assunto à sua espera e decide; a deliberação e o histórico ficam «em nome de»
        $this->delegar($this->s['chefe'], ['ambito' => ['METODO'], 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-11'])->assertCreated();
        $this->assertSame([$a['id']], $minha());
        $decidir('DEVOLVER', 'x')->assertStatus(422);   // devolver exige nota (≥ 5 caracteres)
        $this->postJson("/api/metodo/assuntos/{$a['id']}/decidir", ['accao' => 'APROVAR'], $this->s['ana'])->assertForbidden();   // o criador nunca decide
        $decidir()->assertOk();
        $assunto = $this->em(fn () => AssuntoMetodoTrabalho::query()->findOrFail($a['id']));
        $del = $assunto->deliberacao;
        $this->assertSame('EM_CURSO', $assunto->estado);
        $this->assertSame(['APROVADO', $this->ids['chefe'], 'Chefe'], [$del['estado'], $del['por_delegado_de']['colaborador_id'], $del['por_delegado_de']['nome']]);
        $this->assertSame($this->ids['chefe'], $del['aprovador_colaborador_id']);   // o designado continua a ser o Chefe
        $this->assertTrue(collect($this->getJson("/api/metodo/assuntos/{$a['id']}/historico", $suplente)->assertOk()->json('dados.registos'))->contains(fn ($x) => str_contains((string) $x['detalhe'], 'em nome de Chefe')));
        $this->assertSame([$a['id']], $minha());   // continua a ver o que decidiu
    }

    #[Test]
    public function entregaveis_o_delegado_valida_entregas_e_impedimentos_mas_nunca_o_que_e_do_delegante(): void
    {
        $ana = $this->s['ana'];
        $suplente = $this->s['suplente'];
        $x = $this->postJson("/api/rh/portal/entregaveis/{$this->ids['entregavel']}/execucoes", ['quantidade' => 1, 'data' => '2026-03-02'], $ana)->assertCreated()->json('dados');
        $validar = fn (array $h) => $this->postJson("/api/rh/portal/execucoes/{$x['id']}/validar", ['decisao' => 'VALIDADA'], $h);
        $para = fn () => array_column($this->getJson('/api/rh/portal/aprovacoes/entregas', $suplente)->assertOk()->json('dados'), 'id');

        // sem delegação (ou fora do âmbito / período) não é responsável da cadeia
        $this->assertSame([], $para());
        $validar($suplente)->assertForbidden();
        $this->delegacaoDireta($this->ids['chefe'], $this->ids['suplente'], '2026-03-10', '2026-03-20', ['PORTAL', 'METODO']);
        $this->delegacaoDireta($this->ids['chefe'], $this->ids['suplente'], '2026-03-01', '2026-03-05', ['ENTREGAVEIS']);
        $this->assertSame([], $para());
        $validar($suplente)->assertForbidden();

        // dentro: vê a entrega por validar «em nome» do Chefe (e do Director, que também responde pela unidade, se delegar) e valida
        $this->delegar($this->s['chefe'], ['ambito' => ['ENTREGAVEIS'], 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-12'])->assertCreated();
        $lista = $this->getJson('/api/rh/portal/aprovacoes/entregas', $suplente)->assertOk()->json('dados');
        $this->assertSame([$x['id']], array_column($lista, 'id'));
        $this->assertSame($this->ids['chefe'], $lista[0]['em_nome_de']['colaborador_id']);
        $this->assertTrue($lista[0]['pode_validar']);
        $r = $validar($suplente)->assertOk()->assertJsonPath('dados.validacao', 'VALIDADA')->assertJsonPath('dados.validada_como', 'RESPONSAVEL')->json('dados');
        $this->assertSame($this->ids['chefe'], $r['por_delegado_de']['colaborador_id']);
        $this->assertTrue($this->em(fn () => LogAuditoria::query()->where('acao', 'Validar entrega')->where('detalhes', 'like', '%decidiu em nome de Chefe%')->exists()));
        // repor por validar limpa a marca de delegação
        $this->postJson("/api/rh/estrutura/execucoes/{$x['id']}/repor", [], $suplente)->assertOk()->assertJsonPath('dados.por_delegado_de', null);

        // nunca a entrega do próprio delegante: o Chefe executa um entregável e o Suplente (delegado dele) não a valida por delegação
        $y = $this->postJson("/api/rh/estrutura/entregaveis/{$this->ids['entregavel']}/execucoes", ['colaborador_id' => $this->ids['chefe'], 'quantidade' => 1, 'data' => '2026-03-03'], $this->s['rh'])->assertCreated()->json('dados');
        $this->assertNotContains($y['id'], $para());
        $this->postJson("/api/rh/portal/execucoes/{$y['id']}/validar", ['decisao' => 'VALIDADA'], $suplente)->assertForbidden();
        $this->postJson("/api/rh/portal/execucoes/{$y['id']}/validar", ['decisao' => 'VALIDADA'], $this->s['director'])->assertOk();   // quem está acima valida

        // impedimentos: o Rui (membro da unidade, sem ser dono) não pode; a Ana regista, o delegado decide em nome do Chefe
        $i = $this->postJson("/api/rh/portal/entregaveis/{$this->ids['entregavel']}/impedimentos", ['periodo_de' => '2026-02-01', 'motivo' => 'Sem acesso ao sistema'], $ana)->assertCreated()->json('dados');
        $this->assertSame([$i['id']], array_column($this->getJson('/api/rh/portal/aprovacoes/impedimentos', $suplente)->assertOk()->json('dados'), 'id'));
        $dec = $this->postJson("/api/rh/portal/impedimentos/{$i['id']}/decidir", ['estado' => 'ACEITE'], $suplente)->assertOk()->assertJsonPath('dados.estado', 'ACEITE')->json('dados');
        $this->assertSame($this->ids['chefe'], $dec['por_delegado_de']['colaborador_id']);
        // com o impedimento aceite o período está justificado: sem atraso nas unidades do Suplente (delegado)
        $this->assertSame([], $this->getJson('/api/rh/portal/aprovacoes/atrasos', $suplente)->assertOk()->json('dados'));
    }

    #[Test]
    public function o_delegado_ve_os_atrasos_das_unidades_do_delegante_e_deixa_de_ver_ao_revogar(): void
    {
        $suplente = $this->s['suplente'];
        $this->assertSame([], $this->getJson('/api/rh/portal/aprovacoes/atrasos', $suplente)->assertOk()->json('dados'));
        $id = $this->delegar($this->s['chefe'], ['ambito' => ['ENTREGAVEIS']])->assertCreated()->json('dados.id');
        $atrasos = $this->getJson('/api/rh/portal/aprovacoes/atrasos', $suplente)->assertOk()->json('dados');
        $this->assertSame(['Relatório'], array_column(array_column($atrasos, 'entregavel'), 'nome'));
        $this->postJson("/api/rh/portal/delegacoes/{$id}/revogar", [], $this->s['chefe'])->assertOk();
        $this->assertSame([], $this->getJson('/api/rh/portal/aprovacoes/atrasos', $suplente)->json('dados'));
    }
}
