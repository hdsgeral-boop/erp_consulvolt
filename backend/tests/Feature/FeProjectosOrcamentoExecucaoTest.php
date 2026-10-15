<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\Empresa;
use App\Models\FolhaHorasProjeto;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\PeriodoProdutividadeRH;
use App\Models\RegistoProdutividadeRH;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase E (Versão Actual): orçamento mapeado pelas quantidades, organigrama com os valores do ramo, mapa de quantidades
 * (quantidades, horas e valor), carga de trabalho da equipa, separador Execução, horas de membros externos, meses dos
 * autos e ponte Execução → RH Produtividade (recolha, análise, envio idempotente). Dados fictícios.
 */
final class FeProjectosOrcamentoExecucaoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000081']);
        $this->naEmpresa(function () {
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Obras'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Estaleiro'])->id;
            $this->ids['ana'] = Colaborador::create(['nome_completo' => 'Ana Teste', 'nif' => 'A1', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
            $this->ids['rui'] = Colaborador::create(['nome_completo' => 'Rui Teste', 'nif' => 'R1', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
            $this->ids['emp'] = Terceiro::create(['nome' => 'Empreiteiro Teste', 'nif' => '5000000003', 'tipo' => Terceiro::FORNECEDOR])->id;
        });
        $this->s = $this->sessao(['projectos_carteira_view', 'proj_gerir', 'proj_execucao', 'proj_eliminar', 'proj_orcamento_qtd']);
        $p = $this->postJson('/api/projetos', ['nome' => 'Estrada', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'], 'centro_custo_id' => $this->ids['cc']], $this->s)
            ->assertCreated()->json('dados.id');
        $this->ids['p'] = $p;
        $m = fn (array $d) => $this->postJson("/api/projetos/{$p}/equipa/membros", $d, $this->s)->assertCreated()->json('dados.id');
        $this->ids['m_ana'] = $m(['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['ana'], 'horas_alocadas' => 6]);
        $this->ids['m_rui'] = $m(['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['rui'], 'horas_alocadas' => 2]);
        $this->ids['m_emp'] = $m(['tipo' => 'TERCEIRO', 'terceiro_id' => $this->ids['emp'], 'papel' => 'Subempreiteiro']);
        $this->ids['fase'] = $this->postJson("/api/projetos/{$p}/marcos", ['nome' => 'Troço 1', 'data_inicio' => '2026-09-01', 'data' => '2026-09-30'], $this->s)->json('dados.id');
        $t = fn (array $d) => $this->postJson("/api/projetos/{$p}/tarefas", $d + ['marco_projeto_id' => $this->ids['fase']], $this->s)->assertCreated()->json('dados.id');
        // Vala A: 300 m (Ana), 1 a 14 de Setembro; Vala B: 100 m (subempreiteiro), 15 a 30; Sinalização: 10 un sem responsável
        $this->ids['a'] = $t(['nome' => 'Vala A', 'meta_unidade' => 'm', 'meta_quantidade' => 300, 'atribuido_a_id' => $this->ids['m_ana'], 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-14']);
        $this->ids['b'] = $t(['nome' => 'Vala B', 'meta_unidade' => 'm', 'meta_quantidade' => 100, 'atribuido_a_id' => $this->ids['m_emp'], 'data_inicio' => '2026-09-15', 'data_fim' => '2026-09-30']);
        $this->ids['c'] = $t(['nome' => 'Sinalização', 'meta_unidade' => 'un', 'meta_quantidade' => 10, 'data_inicio' => '2026-09-21', 'data_fim' => '2026-09-25']);
        $med = fn (int $id, float $q, string $d) => $this->postJson("/api/projetos/{$p}/tarefas/{$id}/medicoes", ['quantidade' => $q, 'data' => $d], $this->s)->assertCreated();
        $med($this->ids['a'], 100, '2026-09-04');
        $med($this->ids['a'], 200, '2026-09-11');
        $med($this->ids['b'], 50, '2026-09-18');
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function orcamento_mapeado_pelas_quantidades_saldo_e_reparticao(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $l = $this->postJson("/api/projetos/{$p}/orcamento", ['rubrica' => 'SUBCONTRATOS', 'montante' => 1000000], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/projetos/{$p}/orcamento/{$l}/quantidades", ['unidade' => 'm', 'valor' => 100], $this->sessao(['proj_gerir']))->assertForbidden();
        // 600 000 Kz sobre 400 m previstos → 1 500 Kz/m: Vala A 450 000, Vala B 150 000
        $prev = $this->postJson("/api/projetos/{$p}/orcamento/{$l}/quantidades/prever", ['unidade' => 'm', 'valor' => 600000], $s)->assertOk()->json('dados');
        $this->assertSame([1500.0, 400.0, 400000.0], [$prev['preco_unitario'], $prev['quantidade'], $prev['sobra']]);
        $this->postJson("/api/projetos/{$p}/orcamento/{$l}/quantidades", ['unidade' => 'm', 'valor' => 600000], $s)->assertCreated()
            ->assertJsonPath('dados.saldo', 400000.0)->assertJsonPath('dados.linhas.0.valor', 450000.0);
        // só se mapeia o saldo; âmbito por tarefas escolhidas e base EXECUTADO (Vala B: min(50, 100) = 50 m)
        $this->postJson("/api/projetos/{$p}/orcamento/{$l}/quantidades", ['unidade' => 'm', 'valor' => 400001], $s)->assertStatus(422)->assertJsonPath('codigo', 'SALDO_INSUFICIENTE');
        $this->postJson("/api/projetos/{$p}/orcamento/{$l}/quantidades", ['unidade' => 'kg', 'valor' => 10], $s)->assertStatus(422)->assertJsonPath('codigo', 'AMBITO_SEM_QUANTIDADE');
        $this->postJson("/api/projetos/{$p}/orcamento/{$l}/quantidades", ['unidade' => 'm', 'valor' => 100000, 'base' => 'EXECUTADO',
            'escopo' => ['tipo' => 'TAREFAS', 'ids' => [$this->ids['b']]]], $s)->assertCreated()->assertJsonPath('dados.preco_unitario', 2000.0);
        // baixar o montante abaixo do mapeado é recusado
        $this->putJson("/api/projetos/{$p}/orcamento/{$l}", ['montante' => 500000], $s)->assertStatus(422)->assertJsonPath('codigo', 'VALOR_ABAIXO_DO_MAPEADO');

        $r = $this->getJson("/api/projetos/{$p}/orcamento/quantidades", $s)->assertOk()->json('dados');
        $this->assertSame([700000.0, 300000.0, 70], [$r['rubricas'][0]['mapeado'], $r['rubricas'][0]['saldo'], $r['rubricas'][0]['pct_mapeado']]);
        $this->assertSame([['Vala A', 450000.0], ['Vala B', 250000.0]], array_map(fn ($x) => [$x['nome'], $x['valor']], $r['por_tarefa']));
        $this->assertSame([['Ana Teste', 450000.0], ['Empreiteiro Teste', 250000.0]], array_map(fn ($x) => [$x['nome'], $x['valor']], $r['por_pessoa']));
        $this->assertSame([['Troço 1', 700000.0]], array_map(fn ($x) => [$x['nome'], $x['valor']], $r['por_fase']));

        // organigrama: a parte mapeada chega à posição pelas tarefas e a chefia soma as subposições
        $chefe = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Director'], $s)->json('dados.id');
        $equipa = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Equipa A', 'no_pai_id' => $chefe], $s)->json('dados.id');
        $this->postJson("/api/projetos/{$p}/organigrama/posicoes/{$equipa}/tarefas", ['tarefas' => [$this->ids['a']]], $s)->assertOk();
        $org = collect($this->getJson("/api/projetos/{$p}/organigrama", $s)->assertOk()->json('dados.posicoes'))->keyBy('id');
        $this->assertSame(450000.0, $org[$equipa]['valores']['orcamento_qtd']);
        $this->assertSame(0.0, $org[$chefe]['valores']['orcamento_qtd']);
        $this->assertSame(450000.0, $org[$chefe]['valores_ramo']['orcamento_qtd']);
        $this->assertSame(1, $org[$chefe]['valores_ramo']['subposicoes']);

        // retirar devolve ao saldo; o formato antigo (mapa_quantidades) continua a ser lido
        $this->deleteJson("/api/projetos/{$p}/orcamento/{$l}/quantidades/1", [], $s)->assertOk()->assertJsonPath('dados.saldo', 400000.0);
        $antiga = $this->naEmpresa(fn () => LinhaOrcamentoProjeto::create(['projeto_id' => $p, 'rubrica' => 'MATERIAIS', 'montante' => 10000,
            'mapa_quantidades' => ['unidade' => 'un', 'escopo' => ['tipo' => 'UNIDADE'], 'valor' => 10000, 'base' => 'META']])->id);
        $r2 = collect($this->getJson("/api/projetos/{$p}/orcamento/quantidades", $s)->json('dados.rubricas'))->keyBy('linha_id');
        $this->assertTrue($r2[$antiga]['totalmente_mapeada']);
    }

    #[Test]
    public function mapa_de_quantidades_horas_e_valor(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $mapa = $this->getJson("/api/projetos/{$p}/mapa-quantidades?escala=semana&de=2026-09-01&ate=2026-09-30", $s)->assertOk()->json('dados');
        $this->assertCount(5, $mapa['colunas']);
        $metros = collect($mapa['quantidades'])->firstWhere('unidade', 'm');
        // Vala A: 300 m em 10 dias úteis: 4 / 5 / 1 → 120 / 150 / 30; executado 100 (sem. 1) e 200 (sem. 2)
        $linhaA = collect($metros['linhas'])->firstWhere('id', $this->ids['a']);
        $this->assertSame([120.0, 150.0, 30.0, 0.0, 0.0], $linhaA['previsto']);
        $this->assertSame([100.0, 200.0, 0.0, 0.0, 0.0], $linhaA['realizado']);
        $this->assertSame('fase', $metros['linhas'][0]['tipo']);
        $this->assertSame(400.0, $metros['totais']['total_previsto']);
        $this->assertSame(350.0, $metros['totais']['total_realizado']);
        $this->assertSame(['m', 'un'], array_column($mapa['quantidades'], 'unidade'));
        // horas previstas: 8 h × dias úteis de cada tarefa; lançadas pelas folhas
        $this->postJson("/api/projetos/{$p}/horas", ['tarefa_projeto_id' => $this->ids['a'], 'colaborador_id' => $this->ids['ana'], 'data' => '2026-09-02', 'horas' => 6], $s)->assertCreated();
        $h = $this->getJson("/api/projetos/{$p}/mapa-quantidades?escala=mes&de=2026-09-01&ate=2026-09-30", $s)->json('dados.horas');
        $this->assertSame(80.0 + 96.0 + 40.0, $h['totais']['total_previsto']);
        $this->assertSame(6.0, $h['totais']['total_realizado']);
        $this->getJson("/api/projetos/{$p}/mapa-quantidades?escala=dia&de=2020-01-01&ate=2026-09-30", $s)->assertStatus(422)->assertJsonPath('codigo', 'JANELA_EXCESSIVA');
    }

    #[Test]
    public function carga_de_trabalho_com_area_repartida_e_sem_responsavel(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $area = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Sinaleiros'], $s)->json('dados.id');
        $this->postJson("/api/projetos/{$p}/organigrama/alocar", ['membros' => [$this->ids['m_ana'], $this->ids['m_rui']], 'no_organigrama_projeto_id' => $area, 'confirmar_excesso' => true], $s)->assertOk();
        $this->postJson("/api/projetos/{$p}/organigrama/posicoes/{$area}/tarefas", ['tarefas' => [$this->ids['c']]], $s)->assertOk();
        $c = collect($this->getJson("/api/projetos/{$p}/carga-trabalho", $s)->assertOk()->json('dados.grupos'))->keyBy('chave');
        // Sinalização (da área, sem responsável directo): Ana 6 h/dia e Rui 2 h/dia → 75 % / 25 % de 10 un e de 40 h
        $anaSinal = collect($c["m{$this->ids['m_ana']}"]['tarefas'])->firstWhere('tarefa_id', $this->ids['c']);
        $this->assertSame([0.75, 7.5, 30.0], [$anaSinal['quota'], $anaSinal['meta']['quantidade'], $anaSinal['horas_previstas']]);
        $this->assertSame(0.25, collect($c["m{$this->ids['m_rui']}"]['tarefas'])->firstWhere('tarefa_id', $this->ids['c'])['quota']);
        // Ana leva ainda a Vala A inteira (80 h) e o ritmo para a ordem de trabalho
        $valaA = collect($c["m{$this->ids['m_ana']}"]['tarefas'])->firstWhere('tarefa_id', $this->ids['a']);
        $this->assertSame([1.0, 80.0, 30.0], [$valaA['quota'], $valaA['horas_previstas'], $valaA['ritmo']['dia']]);
        $this->assertSame(110.0, $c["m{$this->ids['m_ana']}"]['horas_previstas']);
        $this->assertArrayNotHasKey('sem', $c->all());
    }

    #[Test]
    public function separador_execucao_horas_de_externos_e_meses_dos_autos(): void
    {
        $p = $this->ids['p'];
        $s = $this->s;
        $this->postJson("/api/projetos/{$p}/horas", ['tarefa_projeto_id' => $this->ids['a'], 'colaborador_id' => $this->ids['ana'], 'data' => '2026-09-02', 'horas' => 8], $s)->assertCreated()
            ->assertJsonPath('dados.membro_equipa_projeto_id', $this->ids['m_ana']);
        // membro externo: grava o membro e não o colaborador (não entra no subsídio nem na imputação salarial)
        $f = $this->postJson("/api/projetos/{$p}/horas", ['tarefa_projeto_id' => $this->ids['b'], 'membro_equipa_projeto_id' => $this->ids['m_emp'], 'data' => '2026-09-16', 'horas' => 5], $s)
            ->assertCreated()->assertJsonPath('dados.colaborador_id', null)->json('dados.id');
        $this->assertSame($this->ids['m_emp'], $this->naEmpresa(fn () => FolhaHorasProjeto::query()->find($f)->membro_equipa_projeto_id));
        $this->postJson("/api/projetos/{$p}/horas", ['tarefa_projeto_id' => $this->ids['b'], 'membro_equipa_projeto_id' => 999999, 'data' => '2026-09-16', 'horas' => 5], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MEMBRO_FORA_DA_EQUIPA');
        $this->assertSame('Empreiteiro Teste', collect($this->getJson("/api/projetos/{$p}/horas", $s)->json('dados'))->firstWhere('id', $f)['colaborador_nome']);

        $e = $this->getJson("/api/projetos/{$p}/execucao?de=2026-09-01&ate=2026-09-30", $s)->assertOk()->json('dados');
        $this->assertSame([13.0, 8.0], [$e['cartoes']['horas']['total'], $e['cartoes']['horas']['atribuivel']]);
        $this->assertSame(['m' => [350.0, 300.0]], collect($e['cartoes']['quantidades'])->mapWithKeys(fn ($q) => [$q['unidade'] => [$q['total'], $q['atribuivel']]])->all());
        $this->assertSame([1, 1], [$e['cartoes']['tarefas']['total'], $e['cartoes']['tarefas']['atribuivel']]);
        $this->assertSame(['Ana Teste'], array_column($e['por_colaborador'], 'nome'));
        $this->assertSame(['m' => 300.0], $e['por_colaborador'][0]['quantidades']);
        $motivos = array_column($e['sem_dono'], 'motivo');
        $this->assertContains('o responsável não é colaborador interno (entidade externa)', $motivos);
        $this->assertContains('a folha de horas não aponta a um colaborador interno', $motivos);

        // autos/revisões só nos meses do projecto (Setembro de 2026)
        $this->getJson("/api/projetos/{$p}/revisoes/meses", $s)->assertOk()->assertJsonPath('dados.meses.0.chave', '2026-09')->assertJsonCount(1, 'dados.meses');
        $rev = $this->sessao(['projectos_carteira_view', 'proj_revisao']);
        $this->getJson("/api/projetos/{$p}/revisoes/simulacao?mes=11&ano=2026", $rev)->assertStatus(422)->assertJsonPath('codigo', 'MES_FORA_DO_PROJETO');
        $this->getJson("/api/projetos/{$p}/revisoes/simulacao?mes=9&ano=2026", $rev)->assertOk();
    }

    #[Test]
    public function ponte_para_a_produtividade_do_rh_sem_duplicar(): void
    {
        $p = $this->ids['p'];
        $ids = $this->naEmpresa(function () {
            $inf = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de produtividade', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $metros = ItemProdutividadeRH::create(['codigo' => 'VALA', 'descricao' => 'Vala aberta', 'metrica' => 'QUANTIDADE', 'unidade' => 'metros', 'preco_unitario' => 50,
                'infotipo_salarial_id' => $inf, 'ativo' => true])->id;
            $tarefas = ItemProdutividadeRH::create(['codigo' => 'TAR', 'descricao' => 'Tarefa concluída', 'metrica' => 'TAREFAS', 'unidade' => 'un', 'preco_unitario' => 1000,
                'infotipo_salarial_id' => $inf, 'ativo' => true])->id;
            ContratoTrabalho::create(['colaborador_id' => $this->ids['ana'], 'estado' => 'ACTIVO', 'data_inicio' => '2020-01-01', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8,
                'codigo_moeda' => 'AOA', 'produtividade' => [['item_id' => $metros, 'preco_unitario' => null], ['item_id' => $tarefas, 'preco_unitario' => 1500]]]);
            $per = PeriodoProdutividadeRH::create(['mes' => '2026-09', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30', 'estado' => 'ABERTO'])->id;

            return compact('metros', 'tarefas', 'per');
        });
        $rh = $this->sessao(['rh_produtividade_view', 'rh_prod_registar']);
        $q = '?de=2026-09-01&ate=2026-09-30&projetos[]='.$p;
        $this->getJson("/api/rh/produtividade/projetos/recolha{$q}", $this->sessao(['projectos_carteira_view']))->assertForbidden();
        $rec = $this->getJson("/api/rh/produtividade/projetos/recolha{$q}", $rh)->assertOk()->json('dados');
        $grupos = collect($rec['grupos'])->keyBy('chave');
        // «m» e «metros» são a mesma unidade; o item sugerido é o da mesma métrica e unidade
        $this->assertSame($ids['metros'], $grupos['QUANTIDADE|metros']['item_sugerido_id']);
        $this->assertSame($ids['tarefas'], $grupos['TAREFAS|tarefas']['item_sugerido_id']);
        $this->assertSame([$ids['per']], array_column($rec['periodos'], 'id'));

        $corpo = ['de' => '2026-09-01', 'ate' => '2026-09-30', 'projetos' => [$p], 'periodo_id' => $ids['per'],
            'mapa' => ['QUANTIDADE|metros' => $ids['metros'], 'TAREFAS|tarefas' => $ids['tarefas']]];
        $a = $this->postJson('/api/rh/produtividade/projetos/analisar', $corpo, $rh)->assertOk()->json('dados');
        // Ana: 300 m × 50 = 15 000; 1 tarefa × 1 500 (preço do contrato) = 1 500
        $this->assertSame(16500.0, $a['total']);
        $this->assertSame('2026-09-11', collect($a['validas'])->firstWhere('item.id', $ids['metros'])['data']);
        $this->assertSame(0, RegistoProdutividadeRH::query()->withoutGlobalScopes()->count());
        $this->postJson('/api/rh/produtividade/projetos/enviar', $corpo, $this->sessao(['rh_produtividade_view']))->assertForbidden();
        $this->postJson('/api/rh/produtividade/projetos/enviar', $corpo, $rh)->assertOk()->assertJsonPath('dados.criados', 2)->assertJsonPath('dados.actualizados', 0);
        $reg = $this->naEmpresa(fn () => RegistoProdutividadeRH::query()->where('item_produtividade_id', $ids['metros'])->first());
        $this->assertSame(['PROJECTO', "PRJ|QUANTIDADE|metros|{$this->ids['ana']}|{$ids['metros']}|{$ids['per']}", '15000.00'], [$reg->origem, $reg->origem_chave, $reg->valor]);
        $this->assertSame([$p], $reg->fonte['projetos']);

        // nova medição e reenvio: actualiza em vez de duplicar
        $this->putJson("/api/projetos/{$p}/tarefas/{$this->ids['a']}", ['meta_quantidade' => 400], $this->s)->assertOk();
        $this->postJson("/api/projetos/{$p}/tarefas/{$this->ids['a']}/medicoes", ['quantidade' => 20, 'data' => '2026-09-12'], $this->s)->assertCreated();
        $this->postJson('/api/rh/produtividade/projetos/enviar', $corpo, $rh)->assertOk()->assertJsonPath('dados.criados', 0)->assertJsonPath('dados.actualizados', 2);
        $this->assertSame(2, $this->naEmpresa(fn () => RegistoProdutividadeRH::query()->count()));
        $this->assertSame('320.000', $this->naEmpresa(fn () => RegistoProdutividadeRH::query()->where('item_produtividade_id', $ids['metros'])->value('quantidade')));

        // período fechado: nada se analisa nem envia
        $this->naEmpresa(fn () => PeriodoProdutividadeRH::query()->whereKey($ids['per'])->update(['estado' => 'FECHADO']));
        $this->postJson('/api/rh/produtividade/projetos/analisar', $corpo, $rh)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_FECHADO');
    }
}
