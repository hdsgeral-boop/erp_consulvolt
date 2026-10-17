<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\TarefaProjeto;
use App\Models\UnidadeNegocio;
use App\Services\RH\ServicoProdutividadeProjetos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 3 (r3_rh_proj): folha de medição por pessoa — executante na medição, tarefas da pessoa (directas e da área do
 * organigrama), modelo Excel com listas de referência, importação com simulação, erros por linha, transacção e sem
 * duplicados; produtividade atribuída ao executante; contactos nos cartões do organigrama só para quem pode. Dados fictícios.
 */
final class R3FolhaMedicaoPessoaTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000391']);
        $this->naEmpresa(function () {
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Obras'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Estaleiro'])->id;
            $this->ids['ana'] = Colaborador::create(['nome_completo' => 'Ana Fictícia', 'nif' => 'NIFANA01', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22,
                'telefone' => '900000001', 'email' => 'ana@exemplo.test'])->id;
            $this->ids['rui'] = Colaborador::create(['nome_completo' => 'Rui Fictício', 'nif' => 'NIFRUI02', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
        });
        $this->s = $this->sessao(['projectos_carteira_view', 'proj_gerir', 'proj_execucao']);
        $p = $this->ids['p'] = $this->postJson('/api/projetos', ['nome' => 'Estrada', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'centro_custo_id' => $this->ids['cc']], $this->s)->assertCreated()->json('dados.id');
        $this->ids['m_ana'] = $this->postJson("/api/projetos/{$p}/equipa/membros", ['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['ana']], $this->s)->assertCreated()->json('dados.id');
        $this->ids['m_rui'] = $this->postJson("/api/projetos/{$p}/equipa/membros", ['tipo' => 'INTERNO', 'colaborador_id' => $this->ids['rui']], $this->s)->assertCreated()->json('dados.id');
        $nova = fn (array $d) => $this->postJson("/api/projetos/{$p}/tarefas", $d + ['meta_unidade' => 'm', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30'], $this->s)
            ->assertCreated()->json('dados.id');
        $this->ids['t_vala'] = $nova(['nome' => 'Vala', 'codigo' => '1.1', 'meta_quantidade' => 100, 'atribuido_a_id' => $this->ids['m_ana']]);
        $this->ids['t_tubo'] = $nova(['nome' => 'Tubagem', 'codigo' => '1.2', 'meta_quantidade' => 50]);
        $this->ids['t_sem'] = $this->postJson("/api/projetos/{$p}/tarefas", ['nome' => 'Sem meta', 'codigo' => '1.3'], $this->s)->assertCreated()->json('dados.id');
        // a tubagem é da área «Canalização», onde está o Rui
        $no = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Canalização'], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/projetos/{$p}/organigrama/alocar", ['membros' => [$this->ids['m_rui']], 'no_organigrama_projeto_id' => $no], $this->s)->assertOk();
        $this->postJson("/api/projetos/{$p}/organigrama/posicoes/{$no}/tarefas", ['tarefas' => [$this->ids['t_tubo']]], $this->s)->assertOk();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $f) {
            @unlink($f);
        }
        parent::tearDown();
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

    /** Ficheiro XLSX com a folha «Medicoes» (cabeçalhos do modelo + linhas). */
    private function ficheiro(array $linhas): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'med').'.xlsx';
        $this->temporarios[] = $caminho;
        $livro = new Spreadsheet;
        $livro->getActiveSheet()->setTitle('Medicoes')->fromArray(array_merge([['Código', 'Tarefa', 'Fase', 'Unidade', 'Meta', 'Realizado', 'Falta', 'Executante', 'Data', 'Quantidade', 'Nota']], $linhas),
            null, 'A1', true);
        (new Xlsx($livro))->save($caminho);

        return new UploadedFile($caminho, 'Folha_Medicao.xlsx', null, null, true);
    }

    private function importar(array $linhas, bool $simular = false, ?array $sessao = null)
    {
        return $this->post("/api/projetos/{$this->ids['p']}/medicoes/importar", ['ficheiro' => $this->ficheiro($linhas), 'simular' => $simular ? '1' : '0'],
            ($sessao ?? $this->s) + ['Accept' => 'application/json']);
    }

    #[Test]
    public function medicao_com_executante_e_folha_com_as_pessoas_de_cada_tarefa(): void
    {
        $p = $this->ids['p'];
        $outro = $this->postJson('/api/projetos', ['nome' => 'Outra', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'], 'centro_custo_id' => $this->ids['cc']], $this->s)->json('dados.id');
        $estranho = $this->postJson("/api/projetos/{$outro}/equipa/membros", ['tipo' => 'LIVRE', 'nome_externo' => 'Externo'], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/projetos/{$p}/tarefas/{$this->ids['t_tubo']}/medicoes", ['quantidade' => 5, 'membro_id' => $estranho], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'EXECUTANTE_INVALIDO');
        $this->postJson("/api/projetos/{$p}/tarefas/{$this->ids['t_tubo']}/medicoes", ['quantidade' => 5, 'data' => '2026-09-10', 'membro_id' => $this->ids['m_rui']], $this->s)->assertCreated();
        $this->postJson("/api/projetos/{$p}/medicoes/lote", ['data' => '2026-09-11', 'linhas' => [['tarefa_id' => $this->ids['t_vala'], 'quantidade' => 7, 'membro_id' => $this->ids['m_ana']]]], $this->s)
            ->assertOk()->assertJsonPath('dados.gravadas', 1);
        $med = $this->naEmpresa(fn () => TarefaProjeto::query()->find($this->ids['t_tubo'])->medicoes());
        $this->assertSame($this->ids['m_rui'], $med[0]['membro_id']);

        $folha = collect($this->getJson("/api/projetos/{$p}/medicoes/folha", $this->s)->assertOk()->json('dados'))->keyBy('id');
        $this->assertFalse($folha->has($this->ids['t_sem']));
        $this->assertSame([$this->ids['m_ana']], $folha[$this->ids['t_vala']]['pessoas']);
        $this->assertSame([$this->ids['m_rui']], $folha[$this->ids['t_tubo']]['pessoas']);
        $this->assertSame($this->ids['m_rui'], $folha[$this->ids['t_tubo']]['medicoes'][0]['membro_id']);

        // a produtividade do RH atribui a quantidade a quem a executou (o Rui), não ao responsável (a tubagem não tem)
        $rec = $this->naEmpresa(fn () => app(ServicoProdutividadeProjetos::class)->recolher('2026-09-01', '2026-09-30', [$p]));
        $porColab = collect($rec['linhas'])->where('tipo', 'QUANTIDADE')->keyBy('colaborador_id');
        $this->assertEquals(5.0, $porColab[$this->ids['rui']]['quantidade']);
        $this->assertEquals(7.0, $porColab[$this->ids['ana']]['quantidade']);
        $this->assertSame([], collect($rec['orfas'])->where('tipo', 'QUANTIDADE')->values()->all());
    }

    #[Test]
    public function modelo_excel_por_pessoa_com_listas_de_referencia(): void
    {
        $p = $this->ids['p'];
        $res = $this->get("/api/projetos/{$p}/medicoes/modelo?membro_id={$this->ids['m_rui']}", $this->s)->assertOk();
        $caminho = $res->baseResponse->getFile()->getPathname();
        $this->temporarios[] = $caminho;
        $livro = IOFactory::load($caminho);
        $this->assertSame(['Medicoes', 'Como_Preencher', 'Tarefas', 'Equipa'], $livro->getSheetNames());
        $folha = $livro->getSheetByName('Medicoes')->toArray();
        $this->assertSame(['Código', 'Tarefa', 'Fase', 'Unidade', 'Meta', 'Realizado', 'Falta', 'Executante', 'Data', 'Quantidade', 'Nota'], $folha[0]);
        $this->assertCount(2, array_filter($folha, fn ($l) => array_filter($l, fn ($v) => $v !== null && $v !== '')));   // cabeçalho + a tubagem do Rui
        $this->assertSame(['1.2', 'Tubagem'], [$folha[1][0], $folha[1][1]]);
        $this->assertSame('Rui Fictício', $folha[1][7]);
        $this->assertSame(DataValidation::TYPE_LIST, $livro->getSheetByName('Medicoes')->getCell('H2')->getDataValidation()->getType());
        $this->assertCount(3, $livro->getSheetByName('Equipa')->toArray());

        // todas as tarefas medíveis sem pessoa; pessoa de outra equipa recusada
        $todas = $this->get("/api/projetos/{$p}/medicoes/modelo", $this->s)->assertOk();
        $this->temporarios[] = $todas->baseResponse->getFile()->getPathname();
        $this->assertSame('Ana Fictícia', IOFactory::load($todas->baseResponse->getFile()->getPathname())->getSheetByName('Medicoes')->getCell('H2')->getValue());
        $this->getJson("/api/projetos/{$p}/medicoes/modelo?membro_id=999999", $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXECUTANTE_INVALIDO');
    }

    #[Test]
    public function importacao_simula_valida_linha_a_linha_grava_tudo_ou_nada_e_nao_duplica(): void
    {
        $p = $this->ids['p'];
        $ok = [
            ['1.1', '', '', '', '', '', '', '', '10/09/2026', '12,5', 'Frente A'],
            ['', 'Tubagem', '', '', '', '', '', 'NIFRUI02', '2026-09-12', 4, ''],
            ['1.2', '', '', '', '', '', '', '', '', '', ''],                               // sem quantidade: ignorada
            ['1.1', '', '', '', '', '', '', '', '10/09/2026', '12,5', 'Frente A'],         // repetida no ficheiro
        ];
        $erros = [
            ['9.9', '', '', '', '', '', '', '', '', 3, ''],
            ['1.3', '', '', '', '', '', '', '', '', 3, ''],
            ['1.1', '', '', '', '', '', '', 'Desconhecido', '', 3, ''],
            ['1.1', '', '', '', '', '', '', '', '31/12/2099', 3, ''],
            ['1.1', '', '', '', '', '', '', '', '', 'abc', ''],
        ];
        $this->importar(array_merge($ok, $erros))->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_ERROS')
            ->assertJsonPath('erros.rejeitadas.0', ['linha' => 6, 'motivo' => 'tarefa «9.9» não encontrada no projecto'])
            ->assertJsonPath('erros.rejeitadas.1.linha', 7)->assertJsonPath('erros.rejeitadas.2.linha', 8)->assertJsonPath('erros.rejeitadas.3.motivo', 'a data da medição não pode ser futura')
            ->assertJsonPath('erros.rejeitadas.4.linha', 10);
        $this->assertSame([[], []], $this->naEmpresa(fn () => [TarefaProjeto::query()->find($this->ids['t_vala'])->medicoes(), TarefaProjeto::query()->find($this->ids['t_tubo'])->medicoes()]));

        // simulação: relatório sem gravar
        $sim = $this->importar($ok, true)->assertOk();
        $this->assertSame([true, 2, 1, 0], [$sim->json('dados.simulacao'), $sim->json('dados.criados'), $sim->json('dados.repetidos'), $sim->json('dados.ignorados')]);
        $this->assertSame([], $this->naEmpresa(fn () => TarefaProjeto::query()->find($this->ids['t_vala'])->medicoes()));

        // a correcção negativa que deixaria o realizado < 0 falha ao gravar e desfaz as outras linhas
        $this->importar([['1.1', '', '', '', '', '', '', '', '', 5, ''], ['1.2', '', '', '', '', '', '', '', '', -9, '']])->assertStatus(422)
            ->assertJsonPath('erros.linhas.0', ['linha' => 3, 'motivo' => 'A correcção deixaria o realizado negativo.']);
        $this->assertSame([], $this->naEmpresa(fn () => TarefaProjeto::query()->find($this->ids['t_vala'])->medicoes()));

        $imp = $this->importar($ok)->assertOk();
        $this->assertSame([2, 1], [$imp->json('dados.criados'), $imp->json('dados.repetidos')]);
        $this->assertSame(['Ana Fictícia' => 1, 'Rui Fictício' => 1], $imp->json('dados.por_pessoa'));
        [$vala, $tubo] = $this->naEmpresa(fn () => [TarefaProjeto::query()->find($this->ids['t_vala']), TarefaProjeto::query()->find($this->ids['t_tubo'])]);
        $this->assertSame('12.500', $vala->meta_realizado);
        $this->assertSame(['q' => 12.5, 'data' => '2026-09-10', 'nota' => 'Frente A', 'membro_id' => $this->ids['m_ana']],
            array_intersect_key($vala->medicoes()[0], array_flip(['q', 'data', 'nota', 'membro_id'])));
        $this->assertSame($this->ids['m_rui'], $tubo->medicoes()[0]['membro_id']);

        // importar de novo o mesmo ficheiro não duplica
        $outra = $this->importar($ok)->assertOk();
        $this->assertSame([0, 2], [$outra->json('dados.criados'), $outra->json('dados.ignorados')]);
        $this->assertCount(1, $this->naEmpresa(fn () => TarefaProjeto::query()->find($this->ids['t_vala'])->medicoes()));

        // sem permissão de execução: 403; outra empresa não vê o projecto
        $this->importar($ok, false, $this->sessao(['projectos_carteira_view']))->assertForbidden();
        $alheia = $this->criarEmpresa(['nif' => '5417000392']);
        $this->importar($ok, false, $this->sessao(['projectos_carteira_view', 'proj_execucao'], $alheia))->assertNotFound();
    }

    #[Test]
    public function contactos_nos_cartoes_do_organigrama_so_para_quem_pode(): void
    {
        $p = $this->ids['p'];
        $gestor = collect($this->getJson("/api/projetos/{$p}/organigrama", $this->s)->assertOk()->json('dados.sem_posicao'))->firstWhere('id', $this->ids['m_ana']);
        $this->assertSame(['900000001', 'ana@exemplo.test'], [$gestor['telefone'], $gestor['email']]);
        $leitor = collect($this->getJson("/api/projetos/{$p}/organigrama", $this->sessao(['projectos_carteira_view']))->assertOk()->json('dados.sem_posicao'))->firstWhere('id', $this->ids['m_ana']);
        $this->assertArrayNotHasKey('telefone', $leitor);
        $this->assertArrayNotHasKey('email', $leitor);
    }

    #[Test]
    public function actividade_do_projecto_com_filtros_e_configuracao_so_para_quem_gere(): void
    {
        $p = $this->ids['p'];
        $this->postJson("/api/projetos/{$p}/tarefas/{$this->ids['t_vala']}/medicoes", ['quantidade' => 3, 'data' => '2026-09-10', 'nota' => '50%_teste'], $this->s)->assertCreated();
        $todas = $this->getJson("/api/projetos/{$p}/atividade", $this->s)->assertOk();
        $this->assertGreaterThan(1, count($todas->json('dados')));
        $med = $this->getJson("/api/projetos/{$p}/atividade?acao=".urlencode('Execução por quantidade'), $this->s)->assertOk()->json('dados');
        $this->assertCount(1, $med);
        $this->assertStringContainsString('Vala', $med[0]['detalhes']);
        // o texto da pesquisa é literal (% e _ não são curingas)
        $this->assertCount(0, $this->getJson("/api/projetos/{$p}/atividade?pesquisa=".urlencode('%_x'), $this->s)->assertOk()->json('dados'));
        $vala = $this->getJson("/api/projetos/{$p}/atividade?pesquisa=vala", $this->s)->assertOk()->json('dados');
        $this->assertNotEmpty($vala);
        $this->assertLessThan(count($todas->json('dados')), count($vala));
        $this->assertCount(0, $this->getJson("/api/projetos/{$p}/atividade?de=2099-01-01", $this->s)->assertOk()->json('dados'));
        $this->getJson("/api/projetos/{$p}/atividade?de=2026-10-02&ate=2026-10-01", $this->s)->assertStatus(422);
        $f = $this->getJson("/api/projetos/{$p}/atividade/filtros", $this->s)->assertOk();
        $this->assertContains('Execução por quantidade', $f->json('dados.acoes'));

        $leitor = $this->sessao(['projectos_carteira_view']);
        $this->getJson('/api/projetos/configuracao', $leitor)->assertOk()->assertJsonPath('dados.produto_faturacao_id', null);
        $this->putJson('/api/projetos/configuracao', ['produto_faturacao_id' => null], $leitor)->assertForbidden();
    }
}
