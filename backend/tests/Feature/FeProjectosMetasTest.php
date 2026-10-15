<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\LogAtividadeProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\TarefaProjeto;
use App\Models\UnidadeNegocio;
use App\Services\Projetos\RegrasMetasProjetos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase E (Versão Actual): calendário de trabalho, metas por quantidade na tarefa e na fase, registo de execução com
 * histórico e conclusão automática, folha de medição em lote, assistente «Tarefas por quantidade», progresso ponderado
 * e regras puras (dias úteis, escala, repartição no tempo, ritmo). Dados fictícios.
 */
final class FeProjectosMetasTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000061']);
        $this->naEmpresa(function () {
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Obras'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Estaleiro'])->id;
            $this->ids['ana'] = Colaborador::create(['nome_completo' => 'Ana Teste', 'nif' => 'A1', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
        });
        $this->s = $this->sessao(['projectos_carteira_view', 'proj_gerir', 'proj_execucao', 'proj_eliminar']);
        $this->ids['p'] = $this->postJson('/api/projetos', ['nome' => 'Pavimentação', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'centro_custo_id' => $this->ids['cc']], $this->s)->assertCreated()->json('dados.id');
        $this->ids['m_ana'] = $this->postJson("/api/projetos/{$this->ids['p']}/equipa/membros", ['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['ana'], 'horas_alocadas' => 8], $this->s)
            ->assertCreated()->json('dados.id');
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $e = null): array
    {
        $e ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($e->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $e->id];
    }

    #[Test]
    public function regras_do_calendario_escala_reparticao_e_ritmo(): void
    {
        $cal = RegrasMetasProjetos::calendario(null);
        // 2026-09-07 (segunda) a 2026-09-13 (domingo): 5 dias úteis; com meio sábado 5,5
        $this->assertSame(5.0, RegrasMetasProjetos::diasUteis('2026-09-07', '2026-09-13', $cal));
        $meio = RegrasMetasProjetos::calendario(['horas_dia' => 6, 'dias' => RegrasMetasProjetos::MODELOS_CAL['SEG_SEX_SAB12']['dias']]);
        $this->assertSame(5.5, RegrasMetasProjetos::diasUteis('2026-09-07', '2026-09-13', $meio));
        $this->assertSame(33.0, RegrasMetasProjetos::horasPrevistas('2026-09-07', '2026-09-13', $meio));
        $this->assertSame('SEG_SEX_SAB12', RegrasMetasProjetos::modeloDe($meio));

        // semana começa à segunda; trimestre em Jan/Abr/Jul/Out
        $sem = RegrasMetasProjetos::periodosDaEscala('2026-09-09', '2026-09-20', 'semana');
        $this->assertSame([['2026-09-09', '2026-09-13'], ['2026-09-14', '2026-09-20']], array_map(fn ($c) => [$c['de'], $c['ate']], $sem));
        $tri = RegrasMetasProjetos::periodosDaEscala('2026-08-15', '2026-11-02', 'trimestre');
        $this->assertSame(['T3 2026', 'T4 2026'], array_column($tri, 'rotulo'));

        // 100 m² de 1 (terça) a 14 de Setembro (10 dias úteis) por semana: 4 / 5 / 1 dias úteis → 40 / 50 / 10, soma fecha
        $cols = RegrasMetasProjetos::periodosDaEscala('2026-09-01', '2026-09-14', 'semana');
        $r = RegrasMetasProjetos::repartirNoTempo(100, '2026-09-01', '2026-09-14', $cols, $cal);
        $this->assertSame([40.0, 50.0, 10.0], $r['por_coluna']);
        $this->assertSame(100.0, $r['dentro']);
        // 3 partes de 10/3 com arredondamento cumulativo
        $d = RegrasMetasProjetos::periodosDaEscala('2026-09-07', '2026-09-09', 'dia');
        $this->assertSame(10.0, array_sum(RegrasMetasProjetos::repartirNoTempo(10, '2026-09-07', '2026-09-09', $d, $cal)['por_coluna']));

        $ritmo = RegrasMetasProjetos::ritmo(100, 'm²', '2026-09-07', '2026-09-18', $cal);
        $this->assertSame(10.0, $ritmo['dias']);
        $this->assertSame(10.0, $ritmo['dia']);
        $this->assertSame(50.0, $ritmo['semana']);
        $this->assertSame(1.25, $ritmo['hora']);
        $this->assertSame('m2', RegrasMetasProjetos::chaveUnidade('Metros quadrados'));
        $this->assertSame('m2', RegrasMetasProjetos::chaveUnidade('m²'));
        $this->assertSame('pecas', RegrasMetasProjetos::chaveUnidade('Peças'));
    }

    #[Test]
    public function calendario_do_projecto_validado_no_servidor(): void
    {
        $p = $this->ids['p'];
        $this->putJson("/api/projetos/{$p}", ['calendario' => ['horas_dia' => 30, 'modelo' => 'SEG_SEX']], $this->s)->assertStatus(422);
        $this->putJson("/api/projetos/{$p}", ['calendario' => ['horas_dia' => 8, 'dias' => [0, 0, 0, 0, 0, 0, 0]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CALENDARIO_INVALIDO');
        $this->putJson("/api/projetos/{$p}", ['calendario' => ['horas_dia' => 7.5, 'modelo' => 'SEG_SEX_SAB12']], $this->s)->assertOk()
            ->assertJsonPath('dados.calendario.horas_dia', 7.5)->assertJsonPath('dados.calendario.dias.6', 0.5);
        $this->assertSame('SEG_SEX_SAB12', $this->getJson("/api/projetos/{$p}/wbs", $this->s)->json('dados.calendario.modelo'));
    }

    #[Test]
    public function meta_na_tarefa_medicao_com_historico_e_conclusao_automatica(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Betonar', 'meta_quantidade' => 100], $s)->assertStatus(422)->assertJsonPath('codigo', 'META_SEM_UNIDADE');
        $t = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Betonar laje', 'meta_unidade' => 'm²', 'meta_quantidade' => 100, 'atribuido_a_id' => $this->ids['m_ana'],
            'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30'], $s)->assertCreated()->assertJsonPath('dados.meta_quantidade', '100.000')->json('dados.id');
        // a % manual é recusada numa tarefa com meta
        $this->postJson("/api/projetos/{$p}/tarefas/{$t}/execucao", ['percentagem_execucao' => 50], $s)->assertStatus(422)->assertJsonPath('codigo', 'TAREFA_COM_META');

        $registar = fn (array $d, ?array $sess = null) => $this->postJson("/api/projetos/{$p}/tarefas/{$t}/medicoes", $d, $sess ?? $s);
        $registar(['quantidade' => 0])->assertStatus(422)->assertJsonPath('codigo', 'QUANTIDADE_INVALIDA');
        $registar(['quantidade' => 10, 'data' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonPath('codigo', 'DATA_FUTURA');
        $registar(['quantidade' => 40, 'data' => '2026-09-05', 'nota' => 'Frente A'])->assertCreated()->assertJsonPath('dados.pct', 40)
            ->assertJsonPath('dados.tarefa.estado', 'EM_CURSO');
        $registar(['quantidade' => -50, 'data' => '2026-09-06'])->assertStatus(422)->assertJsonPath('codigo', 'REALIZADO_NEGATIVO');
        $registar(['quantidade' => -5, 'data' => '2026-09-06', 'nota' => 'correcção'])->assertCreated()->assertJsonPath('dados.realizado', 35.0);
        // sem permissão de execução: 403 antes de validar
        $registar(['quantidade' => 1], $this->sessao(['projectos_carteira_view']))->assertForbidden();
        $r = $registar(['quantidade' => 65, 'data' => '2026-09-20'])->assertCreated()->json('dados');
        $this->assertSame(100, $r['pct']);
        $this->assertSame('CONCLUIDA', $r['tarefa']['estado']);
        $tarefa = $this->naEmpresa(fn () => TarefaProjeto::query()->find($t));
        $this->assertSame('2026-09-20', $tarefa->concluida_em->toDateString());
        $this->assertSame([40.0, -5.0, 65.0], array_map(fn ($x) => (float) $x['q'], $tarefa->medicoes()));
        $this->assertSame('Frente A', $tarefa->medicoes()[0]['nota']);
        $this->assertGreaterThanOrEqual(3, $this->naEmpresa(fn () => LogAtividadeProjeto::query()->where('projeto_id', $p)->where('acao', 'Execução por quantidade')->count()));

        // reeditar a tarefa concluída mantém a data de conclusão; sair de CONCLUIDA retira-a
        $this->putJson("/api/projetos/{$p}/tarefas/{$t}", ['nome' => 'Betonar laje piso 1'], $s)->assertOk();
        $this->assertSame('2026-09-20', $this->naEmpresa(fn () => TarefaProjeto::query()->find($t)->concluida_em?->toDateString()));
        $this->putJson("/api/projetos/{$p}/tarefas/{$t}", ['estado' => 'EM_CURSO', 'meta_realizado' => 90], $s)->assertOk()->assertJsonPath('dados.concluida_em', null)
            ->assertJsonPath('dados.percentagem_execucao', '90.0000');
        $this->assertSame(1, $this->naEmpresa(fn () => LogAtividadeProjeto::query()->where('projeto_id', $p)->where('acao', 'Acerto do realizado')->count()));
    }

    #[Test]
    public function subtarefas_com_meta_sobem_ao_pai_e_progresso_ponderado(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $m = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Fase 1', 'data_inicio' => '2026-09-01', 'data' => '2026-09-30', 'meta_unidade' => 'm²', 'meta_quantidade' => 1200], $s)
            ->assertCreated()->assertJsonPath('dados.meta_unidade', 'm²')->assertJsonPath('dados.avisos', [])->json('dados.id');
        $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'X', 'data_inicio' => '2026-10-01', 'data' => '2026-09-01'], $s)->assertStatus(422)->assertJsonPath('codigo', 'DATAS_INVALIDAS');
        $pai = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Pavimento', 'marco_projeto_id' => $m], $s)->json('dados.id');
        $a = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Zona A', 'tarefa_pai_id' => $pai, 'meta_unidade' => 'm²', 'meta_quantidade' => 900], $s)->json('dados.id');
        $b = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Zona B', 'tarefa_pai_id' => $pai, 'meta_unidade' => 'm²', 'meta_quantidade' => 100], $s)->json('dados.id');
        $this->postJson("/api/projetos/{$p}/tarefas/{$a}/medicoes", ['quantidade' => 900, 'data' => '2026-09-10'], $s)->assertCreated();
        $this->postJson("/api/projetos/{$p}/tarefas/{$b}/medicoes", ['quantidade' => 10, 'data' => '2026-09-10'], $s)->assertCreated();
        // no pai não se regista: a quantidade sobe das subtarefas
        $this->putJson("/api/projetos/{$p}/tarefas/{$pai}", ['meta_unidade' => 'm²', 'meta_quantidade' => 1000], $s)->assertOk();
        $this->postJson("/api/projetos/{$p}/tarefas/{$pai}/medicoes", ['quantidade' => 1], $s)->assertStatus(422)->assertJsonPath('codigo', 'META_NAS_SUBTAREFAS');

        $w = $this->getJson("/api/projetos/{$p}/wbs", $s)->assertOk()->json('dados');
        $g = $w['grupos'][0];
        // ponderado: (900 + 10) / 1000 = 91 % (a média simples daria (100 + 10) / 2 = 55 %)
        $this->assertSame(91, $g['tarefas'][0]['execucao']);
        $this->assertSame(['meta' => 1000.0, 'feito' => 910.0, 'unidade' => 'm²', 'dos_filhos' => true], $g['tarefas'][0]['meta']);
        $this->assertFalse($g['tarefas'][0]['mede_se']);
        $this->assertSame(91, $g['execucao']);
        $this->assertSame('Faltam repartir 200 m² pelas tarefas.', $g['meta']['aviso']);
        $this->assertSame([$a, $b], array_column($g['tarefas'][0]['subtarefas'], 'id'));

        // folha de medição: só as tarefas que se medem (sem o pai), e o lote grava linha a linha
        $folha = $this->getJson("/api/projetos/{$p}/medicoes/folha", $s)->assertOk()->json('dados');
        $this->assertSame([$a, $b], array_column($folha, 'id'));
        $this->assertSame(90.0, $folha[1]['falta']);
        $lote = $this->postJson("/api/projetos/{$p}/medicoes/lote", ['data' => '2026-09-12', 'nota' => 'Auto 1', 'linhas' => [
            ['tarefa_id' => $b, 'quantidade' => 30], ['tarefa_id' => $pai, 'quantidade' => 5], ['tarefa_id' => 999999, 'quantidade' => 1]]], $s)->assertOk()->json('dados');
        $this->assertSame(1, $lote['gravadas']);
        $this->assertSame(['META_NAS_SUBTAREFAS', 'TAREFA_INEXISTENTE'], array_column($lote['falhadas'], 'codigo'));
        $this->assertSame('Auto 1', $this->naEmpresa(fn () => TarefaProjeto::query()->find($b)->medicoes()[1]['nota']));
    }

    #[Test]
    public function assistente_tarefas_por_quantidade_reparte_meta_e_prazo(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $m = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Fase 1', 'data_inicio' => '2026-09-01', 'data' => '2026-09-30'], $s)->json('dados.id');
        $no = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Equipa de pavimento'], $s)->assertCreated()->json('dados.id');
        $base = ['nome' => 'Pavimentação', 'unidade' => 'm²', 'total' => 1200, 'marco_projeto_id' => $m, 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30', 'repartir_prazo' => true];
        $this->postJson("/api/projetos/{$p}/tarefas/por-quantidade", $base + ['partes' => [['quantidade' => 600, 'responsavel' => 'm:999999']]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'RESPONSAVEL_INVALIDO');
        $this->postJson("/api/projetos/{$p}/tarefas/por-quantidade", array_merge($base, ['data_fim' => '2026-10-15', 'partes' => [['quantidade' => 1200]]]), $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DATAS_FORA_DO_NIVEL');
        $ids = $this->postJson("/api/projetos/{$p}/tarefas/por-quantidade", $base + ['partes' => [
            ['quantidade' => 400, 'responsavel' => "m:{$this->ids['m_ana']}"], ['quantidade' => 400, 'responsavel' => "n:{$no}"], ['quantidade' => 400]]], $s)
            ->assertCreated()->json('dados.tarefas');
        $ts = $this->naEmpresa(fn () => TarefaProjeto::query()->whereIn('id', $ids)->orderBy('id')->get());
        $this->assertSame(['Pavimentação 1/3', 'Pavimentação 2/3', 'Pavimentação 3/3'], $ts->pluck('nome')->all());
        // 30 dias em 3 partes sequenciais de 10
        $this->assertSame([['2026-09-01', '2026-09-10'], ['2026-09-11', '2026-09-20'], ['2026-09-21', '2026-09-30']],
            $ts->map(fn ($t) => [$t->data_inicio->toDateString(), $t->data_fim->toDateString()])->all());
        $this->assertSame([$this->ids['m_ana'], null, null], $ts->pluck('atribuido_a_id')->all());
        $this->assertSame([$ids[1]], $this->naEmpresa(fn () => NoOrganigramaProjeto::query()->find($no)->tarefas));
        $this->assertSame(1200.0, $ts->sum(fn ($t) => (float) $t->meta_quantidade));
        $this->postJson("/api/projetos/{$p}/tarefas/por-quantidade", $base + ['partes' => [['quantidade' => 1]]], $this->sessao(['proj_execucao']))->assertForbidden();
    }

    #[Test]
    public function isolamento_por_empresa_nas_medicoes(): void
    {
        $t = $this->postJson("/api/projetos/{$this->ids['p']}/tarefas", ['nome' => 'T', 'meta_unidade' => 'un', 'meta_quantidade' => 5], $this->s)->json('dados.id');
        $outra = $this->criarEmpresa(['nif' => '5417000062']);
        $s2 = $this->sessao(['projectos_carteira_view', 'proj_gerir', 'proj_execucao'], $outra);
        $this->postJson("/api/projetos/{$this->ids['p']}/tarefas/{$t}/medicoes", ['quantidade' => 1], $s2)->assertNotFound();
        $this->getJson("/api/projetos/{$this->ids['p']}/medicoes/folha", $s2)->assertNotFound();
    }
}
