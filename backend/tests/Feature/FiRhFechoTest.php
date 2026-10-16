<?php

namespace Tests\Feature;

use App\Models\CargoFuncao;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\EfectividadeAssiduidade;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Models\PostoTrabalho;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Services\RH\ServicoDocumentosRH;
use App\Services\RH\ServicoProdutividade;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fecho da Versão Actual — RH (ADR-071; caderno 04_rh_salarios.md #4, #7, #8, #10, #11, #13): funções com objectivo geral e
 * responsabilidades, minuta do contrato (Lei 12/23), produtividade com base «prémio» e explicação do cálculo, produtividade em
 * massa nos contratos, modelo Excel da efectividade e faltas acumuladas no ano. Dados fictícios.
 */
final class FiRhFechoTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private array $ids = [];

    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $prod = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de produtividade', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $mk = fn (string $n, string $nif, string $estado = 'ACTIVO') => Colaborador::create(['nome_completo' => $n, 'nif' => $nif, 'estado' => $estado, 'tipo_organizacao_id' => $org,
                'dias_uteis_mes' => 22, 'data_admissao' => '2020-01-01'])->id;
            $this->ids = ['org' => $org, 'base' => $base, 'prod' => $prod, 'ana' => $mk('Ana Fictícia', '000123456LA041'), 'rui' => $mk('Rui Fictício', '900000002LA002'),
                'eva' => $mk('Eva Fictícia', '900000003LA003'), 'ze' => $mk('Zé Inactivo', '900000004LA004', 'INACTIVO')];
            $ct = fn (int $c, string $estado = 'ACTIVO', array $extra = []) => ContratoTrabalho::create(['colaborador_id' => $c, 'estado' => $estado, 'dias_contrato_mes' => 22,
                'horas_por_dia' => 8, 'data_inicio' => '2020-01-01', 'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 300000]]] + $extra)->id;
            $this->ids += ['ct_ana' => $ct($this->ids['ana'], 'ACTIVO', ['horario' => ['entrada' => '08:00', 'saida' => '17:00', 'periodos' => 2, 'saida_intervalo' => '12:00',
                'entrada_intervalo' => '13:00', 'dias' => [1, 2, 3, 4, 5]]]), 'ct_rui' => $ct($this->ids['rui']), 'ct_ze' => $ct($this->ids['ze'], 'INACTIVO')];
            $this->ids['obj'] = ItemProdutividadeRH::create(['codigo' => 'OBJ', 'descricao' => 'Objectivo comercial', 'metrica' => 'OBJECTIVO', 'unidade' => '%', 'preco_unitario' => 50000,
                'infotipo_salarial_id' => $prod, 'minimo' => 80, 'maximo' => 120, 'ativo' => true])->id;
            $this->ids['pec'] = ItemProdutividadeRH::create(['codigo' => 'PEC', 'descricao' => 'Peças', 'metrica' => 'QUANTIDADE', 'unidade' => 'peças', 'preco_unitario' => 100,
                'infotipo_salarial_id' => $prod, 'ativo' => true])->id;
            $this->ids['velho'] = ItemProdutividadeRH::create(['codigo' => 'VELHO', 'descricao' => 'Desactivado', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'preco_unitario' => 1,
                'infotipo_salarial_id' => $prod, 'ativo' => false])->id;
        });
        $this->ids['outro_colab'] = app(ContextoEmpresa::class)->executarComo($this->outra->id, fn () => Colaborador::create(['nome_completo' => 'De Outra Empresa', 'nif' => 'X1',
            'estado' => 'ACTIVO', 'tipo_organizacao_id' => TipoOrganizacaoRH::create(['nome' => 'Org'])->id])->id);
        $this->s = [
            'rh' => $this->sessao(['funcoes_view', 'rh_funcoes_gerir', 'rh_funcao_del', 'est_estrutura_view', 'est_editar', 'est_eliminar', 'contratos_view', 'contratos_new',
                'contratos_terminate', 'rh_produtividade_view', 'rh_prod_config', 'rh_prod_registar', 'rh_prod_periodo', 'rh_assiduidade_view', 'rh_assid_registar', 'rh_assid_config',
                'config_empresas_gerir', 'rh_ferias_edit']),
            'ver' => $this->sessao(['contratos_view', 'rh_produtividade_view', 'rh_assiduidade_view', 'funcoes_view']),
        ];
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    // ───────────── #10 Funções ─────────────

    #[Test]
    public function funcoes_com_descritivo_cargos_que_as_usam_e_bloqueio_ao_eliminar(): void
    {
        $f = $this->postJson('/api/rh/cargos', ['nome' => 'Técnico Fictício', 'objetivo_geral' => 'Apoiar a direcção.', 'responsabilidades' => "Tratar o expediente;\nOrganizar o arquivo."],
            $this->s['rh'])->assertCreated()->assertJsonPath('dados.objetivo_geral', 'Apoiar a direcção.')->json('dados');
        $this->assertSame($f['responsabilidades'], $f['descricao']);   // o `descricao` espelha as responsabilidades

        $un = $this->em(fn () => UnidadeOrganica::create(['nome' => 'Departamento Fictício'])->id);
        // um cargo novo da estrutura orgânica tem de ter função
        $this->postJson('/api/rh/estrutura/postos', ['unidade_organica_id' => $un, 'titulo' => 'Sem função'], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'POSTO_SEM_FUNCAO');
        $p = $this->postJson('/api/rh/estrutura/postos', ['unidade_organica_id' => $un, 'cargo_funcao_id' => $f['id'], 'titulo' => 'Técnico de arquivo'], $this->s['rh'])
            ->assertCreated()->json('dados.id');
        // um cargo antigo sem função continua editável
        $antigo = $this->em(fn () => PostoTrabalho::create(['unidade_organica_id' => $un, 'titulo' => 'Antigo', 'vagas' => 1])->id);
        $this->putJson("/api/rh/estrutura/postos/{$antigo}", ['unidade_organica_id' => $un, 'titulo' => 'Antigo renomeado'], $this->s['rh'])->assertOk();

        $lista = collect($this->getJson('/api/rh/cargos', $this->s['ver'])->assertOk()->json('dados'))->keyBy('id');
        $this->assertSame(['Departamento Fictício — Técnico de arquivo'], $lista[$f['id']]['cargos_estrutura']);

        $this->deleteJson("/api/rh/cargos/{$f['id']}", [], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO')
            ->assertJsonPath('erros.cargos', ['Departamento Fictício — Técnico de arquivo']);
        $this->deleteJson("/api/rh/estrutura/postos/{$p}", [], $this->s['rh'])->assertOk();
        $this->deleteJson("/api/rh/cargos/{$f['id']}", [], $this->s['rh'])->assertOk();
    }

    #[Test]
    public function funcoes_template_e_importacao_com_as_tres_colunas(): void
    {
        $r = $this->get('/api/rh/paridade/cadastros/funcoes/modelo', $this->s['rh'])->assertOk();
        $folha = IOFactory::load($r->baseResponse->getFile()->getPathname())->getSheet(0)->toArray();
        $this->assertSame(['Nome', 'Objectivo Geral do Cargo', 'Principais Responsabilidades'], $folha[0]);

        $csv = "Nome,Objectivo Geral do Cargo,Principais Responsabilidades\nMotorista Fictício,Transportar pessoas,Conduzir;Cuidar da viatura\n";
        $this->post('/api/rh/paridade/cadastros/funcoes/importar', ['ficheiro' => UploadedFile::fake()->createWithContent('f.csv', $csv)], $this->s['rh'])
            ->assertOk()->assertJsonPath('dados.criados', 1);
        // ficheiro do template antigo: a «Descrição» vale como responsabilidades
        $this->post('/api/rh/paridade/cadastros/funcoes/importar', ['ficheiro' => UploadedFile::fake()->createWithContent('g.csv', "Nome,Descrição\nGuarda Fictício,Vigiar\n")],
            $this->s['rh'])->assertOk();
        $c = $this->em(fn () => CargoFuncao::query()->whereIn('nome', ['Motorista Fictício', 'Guarda Fictício'])->get()->keyBy('nome'));
        $this->assertSame(['Transportar pessoas', 'Conduzir;Cuidar da viatura'], [$c['Motorista Fictício']->objetivo_geral, $c['Motorista Fictício']->responsabilidades]);
        $this->assertSame('Vigiar', $c['Guarda Fictício']->responsabilidades);
    }

    // ───────────── #8 Minuta do contrato ─────────────

    #[Test]
    public function contrato_com_motivo_do_termo_periodo_experimental_e_subsidios_minimos(): void
    {
        $base = ['colaborador_id' => $this->ids['eva'], 'data_inicio' => '2026-01-01', 'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 200000]]];
        // a termo: no máximo 30 dias de período experimental (art. 18.º/3)
        $this->postJson('/api/rh/contratos', $base + ['data_fim' => '2026-12-31', 'periodo_experimental_dias' => 60], $this->s['rh'])
            ->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_EXPERIMENTAL_INVALIDO');
        $id = $this->postJson('/api/rh/contratos', $base + ['data_fim' => '2026-12-31', 'periodo_experimental_dias' => 30, 'motivo_termo' => '  Acréscimo temporário de actividade  '],
            $this->s['rh'])->assertCreated()->assertJsonPath('dados.motivo_termo', 'Acréscimo temporário de actividade')->assertJsonPath('dados.periodo_experimental_dias', 30)->json('dados.id');
        // editar sem os campos mantém-nos; enviar vazio limpa
        $this->putJson("/api/rh/contratos/{$id}", $base + ['data_fim' => '2026-12-31'], $this->s['rh'])->assertOk()->assertJsonPath('dados.periodo_experimental_dias', 30);
        $this->putJson("/api/rh/contratos/{$id}", $base + ['data_fim' => '2026-12-31', 'motivo_termo' => '', 'periodo_experimental_dias' => null], $this->s['rh'])->assertOk()
            ->assertJsonPath('dados.motivo_termo', null)->assertJsonPath('dados.periodo_experimental_dias', null);
        $this->putJson("/api/rh/contratos/{$id}", $base + ['data_fim' => '2026-12-31', 'periodo_experimental_dias' => 500], $this->s['rh'])->assertStatus(422);

        // subsídios de férias e de Natal: 50 % por omissão, nunca abaixo do mínimo legal
        $this->getJson('/api/rh/configuracao', $this->s['ver'])->assertOk()->assertJsonPath('dados.subsidio_ferias_pct', 50)->assertJsonPath('dados.subsidio_natal_pct', 50);
        $this->putJson('/api/rh/configuracao', ['subsidio_ferias_pct' => 40], $this->s['rh'])->assertStatus(422);
        $this->putJson('/api/rh/configuracao', ['subsidio_ferias_pct' => 100, 'subsidio_natal_pct' => 60], $this->s['rh'])->assertOk()->assertJsonPath('dados.subsidio_ferias_pct', 100);
        // quem só consulta não grava (verificado antes de validar)
        $this->putJson('/api/rh/configuracao', ['subsidio_ferias_pct' => 'x'], $this->s['ver'])->assertForbidden();
    }

    // ───────────── #11 Produtividade com base «prémio» ─────────────

    #[Test]
    public function produtividade_base_premio_so_no_objectivo_e_explicacao_do_calculo(): void
    {
        $item = ['codigo' => 'OBJ', 'descricao' => 'Objectivo comercial', 'metrica' => 'OBJECTIVO', 'unidade' => '%', 'preco_unitario' => 50000,
            'infotipo_salarial_id' => $this->ids['prod'], 'minimo' => 80, 'maximo' => 120];
        $this->putJson("/api/rh/produtividade/itens/{$this->ids['pec']}", ['codigo' => 'PEC', 'descricao' => 'Peças', 'metrica' => 'QUANTIDADE', 'preco_unitario' => 100,
            'infotipo_salarial_id' => $this->ids['prod'], 'base_calculo' => 'PREMIO'], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'BASE_CALCULO_INVALIDA');
        $this->putJson("/api/rh/produtividade/itens/{$this->ids['obj']}", $item + ['base_calculo' => 'OUTRA'], $this->s['rh'])->assertStatus(422);
        $this->putJson("/api/rh/produtividade/itens/{$this->ids['obj']}", $item + ['base_calculo' => 'PREMIO'], $this->s['rh'])->assertOk()->assertJsonPath('dados.base_calculo', 'PREMIO');

        // 95 % de um prémio de 50 000 → 47 500; 130 % → tecto de 120 % → 60 000; 70 % → abaixo do mínimo → 0
        $obj = $this->em(fn () => ItemProdutividadeRH::query()->findOrFail($this->ids['obj']));
        $this->assertSame('47500.00', ServicoProdutividade::valor($obj, 95, 50000));
        $this->assertSame('60000.00', ServicoProdutividade::valor($obj, 130, 50000));
        $this->assertSame('0.00', ServicoProdutividade::valor($obj, 70, 50000));
        $exp = $this->getJson("/api/rh/produtividade/explicar?item_id={$this->ids['obj']}&quantidade=130", $this->s['rh'])->assertOk()->json('dados');
        $this->assertSame('PREMIO', $exp['base_calculo']);
        $this->assertStringContainsString('prémio a 100%', $exp['formula']);
        $this->assertStringContainsString('(120 ÷ 100)', $exp['conta']);
        $this->assertCount(1, $exp['cortes']);

        // no registo do período: o colaborador tem o item no contrato
        $this->em(fn () => ContratoTrabalho::query()->whereKey($this->ids['ct_ana'])->update(['produtividade' => [['item_id' => $this->ids['obj'], 'preco_unitario' => null]]]));
        $p = $this->postJson('/api/rh/produtividade/periodos', ['mes' => '2025-09', 'data_inicio' => '2025-09-01', 'data_fim' => '2025-09-30'], $this->s['rh'])->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/produtividade/periodos/{$p}/registos", ['colaborador_id' => $this->ids['ana'], 'item_produtividade_id' => $this->ids['obj'], 'quantidade' => 95,
            'data' => '2025-09-30'], $this->s['rh'])->assertCreated()->assertJsonPath('dados.valor', '47500.00');
        $linha = $this->getJson("/api/rh/produtividade/periodos/{$p}/explicacao", $this->s['ver'])->assertOk()->json('dados.0');
        $this->assertSame(['47500.00', '47500.00'], [$linha['valor'], $linha['valor_gravado']]);
        // mudar a base para UNITARIO recalcula os registos do período aberto (95 × 50 000)
        $this->putJson("/api/rh/produtividade/itens/{$this->ids['obj']}", $item + ['base_calculo' => 'UNITARIO'], $this->s['rh'])->assertOk();
        $this->getJson("/api/rh/produtividade/periodos/{$p}/explicacao", $this->s['ver'])->assertJsonPath('dados.0.valor_gravado', '4750000.00');
        $this->getJson('/api/rh/produtividade/metricas', $this->s['ver'])->assertOk()->assertJsonPath('dados.metricas.OBJECTIVO.base_premio', true);
    }

    // ───────────── #13 Produtividade em massa ─────────────

    #[Test]
    public function produtividade_em_massa_acrescentar_substituir_e_retirar(): void
    {
        $url = '/api/rh/contratos/produtividade-massa';
        // sem permissão: 403 antes de validar (corpo vazio)
        $this->postJson($url, [], $this->s['ver'])->assertForbidden();
        // isolamento: colaborador de outra empresa é recusado
        $this->postJson($url, ['colaboradores' => [$this->ids['outro_colab']], 'itens' => [['item_id' => $this->ids['pec']]], 'modo' => 'ACRESCENTAR'], $this->s['rh'])->assertStatus(422);
        $this->postJson($url, ['contratos' => [$this->ids['ct_ana']], 'itens' => [['item_id' => $this->ids['velho']]], 'modo' => 'ACRESCENTAR'], $this->s['rh'])
            ->assertStatus(422)->assertJsonPath('codigo', 'ITEM_PRODUTIVIDADE_INACTIVO');

        $sim = $this->postJson($url, ['colaboradores' => [$this->ids['ana'], $this->ids['rui'], $this->ids['eva'], $this->ids['ze']], 'itens' => [['item_id' => $this->ids['pec']]],
            'modo' => 'ACRESCENTAR', 'simular' => true], $this->s['rh'])->assertOk()->json('dados');
        $this->assertSame(['Ana Fictícia', 'Rui Fictício'], $sim['actualizados']);
        $this->assertSame(['Eva Fictícia'], $sim['sem_contrato']);
        $this->assertSame(['Zé Inactivo'], $sim['inactivos']);
        $this->assertNull($this->em(fn () => ContratoTrabalho::query()->find($this->ids['ct_ana'])->produtividade));   // a simulação não grava

        $this->postJson($url, ['contratos' => [$this->ids['ct_ana'], $this->ids['ct_rui'], $this->ids['ct_ze']], 'itens' => [['item_id' => $this->ids['pec']]], 'modo' => 'ACRESCENTAR'],
            $this->s['rh'])->assertOk()->assertJsonPath('dados.inactivos', ['Zé Inactivo']);
        // acrescentar com preço próprio e «manter» o preço dos que já o têm
        $this->postJson($url, ['contratos' => [$this->ids['ct_ana']], 'itens' => [['item_id' => $this->ids['pec'], 'preco_unitario' => 150], ['item_id' => $this->ids['obj']]],
            'modo' => 'ACRESCENTAR', 'preco_existente' => 'MANTER'], $this->s['rh'])->assertOk();
        $this->assertSame([['item_id' => $this->ids['pec'], 'preco_unitario' => null], ['item_id' => $this->ids['obj'], 'preco_unitario' => null]],
            $this->em(fn () => ContratoTrabalho::query()->find($this->ids['ct_ana'])->produtividade));
        $this->postJson($url, ['contratos' => [$this->ids['ct_ana']], 'itens' => [['item_id' => $this->ids['pec'], 'preco_unitario' => 150]], 'modo' => 'ACRESCENTAR'], $this->s['rh'])->assertOk();
        $this->assertEquals(150, $this->em(fn () => ContratoTrabalho::query()->find($this->ids['ct_ana'])->produtividade[0]['preco_unitario']));
        // substituir a lista
        $this->postJson($url, ['contratos' => [$this->ids['ct_ana']], 'itens' => [['item_id' => $this->ids['obj']]], 'modo' => 'SUBSTITUIR'], $this->s['rh'])->assertOk();
        $this->assertSame([['item_id' => $this->ids['obj'], 'preco_unitario' => null]], $this->em(fn () => ContratoTrabalho::query()->find($this->ids['ct_ana'])->produtividade));
        // retirar (o mesmo pedido outra vez fica «sem alteração»)
        $this->postJson($url, ['contratos' => [$this->ids['ct_ana']], 'itens' => [['item_id' => $this->ids['obj']]], 'modo' => 'RETIRAR'], $this->s['rh'])->assertOk()
            ->assertJsonPath('dados.actualizados', ['Ana Fictícia']);
        $this->postJson($url, ['contratos' => [$this->ids['ct_ana']], 'itens' => [['item_id' => $this->ids['obj']]], 'modo' => 'RETIRAR'], $this->s['rh'])->assertOk()
            ->assertJsonPath('dados.sem_alteracao', ['Ana Fictícia']);
        $this->assertDatabaseHas('logs_auditoria', ['acao' => 'Aplicar produtividade em massa', 'empresa_id' => $this->empresa->id]);
    }

    // ───────────── #4 Modelo da efectividade e faltas do ano ─────────────

    #[Test]
    public function modelo_da_efectividade_pre_preenchido_com_nif_em_texto(): void
    {
        $this->get('/api/rh/assiduidade/modelo?mes=2025-09', $this->s['ver'])->assertForbidden();
        $this->get('/api/rh/assiduidade/modelo?mes=xx', $this->s['rh'] + ['Accept' => 'application/json'])->assertStatus(422);
        $r = $this->get('/api/rh/assiduidade/modelo?mes=09/2025', $this->s['rh'])->assertOk();
        $livro = IOFactory::load($r->baseResponse->getFile()->getPathname());
        $this->assertSame(['Efectividade', 'Colaboradores', 'Instruções'], $livro->getSheetNames());
        $folha = $livro->getSheet(0);
        $linhas = $folha->toArray(null, false, false, false);
        $this->assertSame(['NIF', 'Colaborador', 'Data', 'Entrada', 'Saida intervalo', 'Entrada intervalo', 'Saida', 'Horas', 'Autorizado', 'Observacoes'], $linhas[0]);
        $ana = array_values(array_filter($linhas, fn ($l) => $l[1] === 'Ana Fictícia'));
        $this->assertCount(22, $ana);   // dias úteis de Setembro de 2025 (segunda a sexta)
        $this->assertSame(['000123456LA041', '2025-09-01', '08:00', '12:00', '13:00', '17:00'], [$ana[0][0], $ana[0][2], $ana[0][3], $ana[0][4], $ana[0][5], $ana[0][6]]);
        $this->assertSame('s', $folha->getCell('A2')->getDataType());
        $this->assertEmpty(array_filter($linhas, fn ($l) => $l[1] === 'Zé Inactivo'));
        $this->assertSame(4, $livro->getSheet(1)->getHighestRow());   // cabeçalho + 3 activos
    }

    #[Test]
    public function faltas_acumuladas_no_ano_e_aviso_do_artigo_230(): void
    {
        // um único registo em Janeiro: Janeiro e Fevereiro ficam com faltas e contam no acumulado de Março
        $this->em(fn () => EfectividadeAssiduidade::create(['colaborador_id' => $this->ids['rui'], 'data' => '2025-01-02', 'horas' => 8, 'origem' => 'MANUAL']));
        $mar = collect($this->getJson('/api/rh/assiduidade/meses/2025-03', $this->s['ver'])->assertOk()->json('dados.linhas'))->firstWhere('colaborador_id', $this->ids['rui']);
        $this->assertGreaterThan(12, $mar['dias_falta_ano']);
        $this->assertGreaterThan($mar['dias_falta'], $mar['dias_falta_ano']);
        $this->assertNotEmpty(array_filter($mar['avisos'], fn ($a) => str_contains($a, 'acima de 12 num ano')));
        $this->assertGreaterThan(0, $this->getJson('/api/rh/assiduidade/meses/2025-03', $this->s['ver'])->json('dados.colaboradores_com_faltas'));
    }

    #[Test]
    public function declaracoes_usam_o_contrato_vigente_mesmo_suspenso(): void
    {
        $this->em(fn () => ContratoTrabalho::query()->whereKey($this->ids['ct_rui'])->update(['estado' => 'SUSPENSO']));
        $c = $this->em(fn () => app(ServicoDocumentosRH::class)->contratoVigente($this->ids['rui']));
        $this->assertSame($this->ids['ct_rui'], $c?->id);
        $this->assertSame('300000.00', $this->em(fn () => app(ServicoDocumentosRH::class)->remuneracaoMensal($this->ids['rui'])));
    }
}
