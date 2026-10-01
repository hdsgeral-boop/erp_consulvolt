<?php

namespace Tests\Feature;

use App\Models\CategoriaProduto;
use App\Models\Colaborador;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cópias de segurança por empresa (exportar → importar para empresa NOVA/vazia, com todas as referências remapeadas),
 * clonagem da estrutura e centro de migração (modelos, importação transaccional, edição em massa) — ADR-058.
 */
final class SistemaCopiasMigracaoTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000501', 'nome' => 'Origem, Lda']);
        $this->s = $this->sessao(['config_backup', 'config_ferramentas', 'config_empresas_gerir', 'config_migracao_view', 'contab_plano_gerir', 'aux_gerir',
            'rh_infotipos_gerir', 'rh_bancario_gerir', 'rh_funcoes_gerir', 'vendas_produtos_gerir', 'compras_forn_gerir']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function dadosDeOrigem(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3211' => 'Fornecedores', '4311' => 'Banco', '611' => 'Vendas'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['diario'] = DiarioContabil::create(['codigo' => 'BD', 'descricao' => 'Bancos'])->id;
            $this->ids['terceiro'] = Terceiro::create(['nome' => 'Fornecedor', 'nif' => '5000000021', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211'])->id;
            $this->ids['categoria'] = CategoriaProduto::create(['nome' => 'Serviços'])->id;
            $this->ids['produto'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'codigo_conta' => '611', 'categoria_produto_id' => $this->ids['categoria']])->id;
            $this->ids['infotipo'] = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $this->ids['colab'] = Colaborador::create(['nome_completo' => 'Colaborador Um', 'estado' => 'ACTIVO'])->id;
            $this->ids['un_pai'] = UnidadeNegocio::create(['codigo' => 'SEDE', 'nome' => 'Sede', 'colaborador_gestor_id' => $this->ids['colab']])->id;
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'LDA', 'nome' => 'Luanda', 'unidade_negocio_pai_id' => $this->ids['un_pai']])->id;
        });
        DB::table('contratos_trabalho')->insert(['empresa_id' => $this->empresa->id, 'colaborador_id' => $this->ids['colab'],
            'remuneracoes' => json_encode([['infotype_id' => $this->ids['infotipo'], 'value_month' => 150000]])]);
        $this->ids['taxa'] = DB::table('taxas_cambio')->insertGetId(['empresa_id' => $this->empresa->id, 'codigo_moeda' => 'USD', 'data_taxa' => '2026-09-01', 'taxa' => 920]);
        DB::table('documentos_tesouraria')->insert(['empresa_id' => $this->empresa->id, 'estado' => 'PENDENTE', 'taxa_cambio_id' => $this->ids['taxa']]);
        $this->ids['periodo'] = DB::table('periodos_processamento_salarial')->insertGetId(['empresa_id' => $this->empresa->id, 'mes_ano' => '2026-08']);
        DB::table('lancamentos_contabeis')->insert(['empresa_id' => $this->empresa->id, 'diario_id' => $this->ids['diario'], 'terceiro_id' => $this->ids['terceiro'],
            'codigo_conta' => '3211', 'tipo_dc' => 'C', 'valor' => 1000, 'data_documento' => '2026-09-02', 'empresa_origem_id' => $this->empresa->id, 'periodo_id' => $this->ids['periodo']]);
        // referências sem FK (resultados da folha) e polimórficas (documento de origem de um movimento de stock)
        DB::table('resultados_folha_salarial')->insert(['empresa_id' => $this->empresa->id, 'periodo_processamento_salarial_id' => $this->ids['periodo'],
            'colaborador_id' => $this->ids['colab'], 'rubricas' => json_encode([['infotipo_id' => $this->ids['infotipo'], 'valor' => '150000.00']])]);
        $this->ids['guia'] = DB::table('guias_saida')->insertGetId(['empresa_id' => $this->empresa->id]);
        DB::table('movimentos_inventario')->insert([['empresa_id' => $this->empresa->id, 'documento_tipo' => 'GUIA_SAIDA', 'documento_id' => $this->ids['guia']],
            ['empresa_id' => $this->empresa->id, 'documento_tipo' => 'OUTRO', 'documento_id' => 999999]]);
    }

    private function exportar(): string
    {
        $r = $this->get('/api/sistema/copias/exportar', $this->s)->assertOk();
        $caminho = storage_path('app/copias/teste_'.uniqid().'.json');
        copy($r->baseResponse->getFile()->getPathname(), $caminho);
        @unlink($r->baseResponse->getFile()->getPathname());

        return $caminho;
    }

    #[Test]
    public function exporta_e_importa_para_empresa_nova_com_referencias_remapeadas(): void
    {
        $this->dadosDeOrigem();
        $ficheiro = $this->exportar();
        try {
            $copia = json_decode(file_get_contents($ficheiro), true);
            $this->assertSame('erp_copia_empresa', $copia['formato']);
            $porTabela = collect($copia['tabelas'])->mapWithKeys(fn ($t) => [$t['tabela'] => count($t['linhas'])]);
            $this->assertSame(1, $porTabela['lancamentos_contabeis']);
            $this->assertArrayNotHasKey('logs_auditoria', $porTabela->all());

            // cada envio consome (move) o ficheiro carregado: envia-se sempre uma cópia
            $upload = function () use ($ficheiro) {
                $tmp = $ficheiro.'.'.uniqid();
                copy($ficheiro, $tmp);

                return new UploadedFile($tmp, 'copia.json', 'application/json', null, true);
            };
            $this->post('/api/sistema/copias/importar', ['ficheiro' => $upload(), 'nif' => '5417000501'], $this->s + ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonPath('codigo', 'NIF_DUPLICADO');
            $sim = $this->post('/api/sistema/copias/importar', ['ficheiro' => $upload(), 'nif' => '5417000599', 'simular' => true], $this->s + ['Accept' => 'application/json'])->assertOk();
            $this->assertSame(1, $sim->json('dados.por_tabela.contratos_trabalho'));
            $this->assertSame(1, Empresa::query()->count());

            $r = $this->post('/api/sistema/copias/importar', ['ficheiro' => $upload(), 'nif' => '5417000599'], $this->s + ['Accept' => 'application/json'])->assertCreated();
            $nova = (int) $r->json('dados.empresa_destino.id');
            $this->assertStringContainsString('Origem, Lda (Restauro', $r->json('dados.empresa_destino.nome'));

            $infotipo = DB::table('infotipos_salariais')->where('empresa_id', $nova)->value('id');
            $contrato = DB::table('contratos_trabalho')->where('empresa_id', $nova)->first();
            $colab = DB::table('colaboradores')->where('empresa_id', $nova)->value('id');
            $this->assertNotEquals($this->ids['infotipo'], $infotipo);
            $this->assertSame((int) $colab, (int) $contrato->colaborador_id);
            $this->assertSame((int) $infotipo, json_decode($contrato->remuneracoes, true)[0]['infotype_id']);
            $sede = DB::table('unidades_negocio')->where('empresa_id', $nova)->where('codigo', 'SEDE')->first();
            $this->assertSame((int) $colab, (int) $sede->colaborador_gestor_id);
            $this->assertSame((int) $sede->id, (int) DB::table('unidades_negocio')->where('empresa_id', $nova)->where('codigo', 'LDA')->value('unidade_negocio_pai_id'));
            $lanc = DB::table('lancamentos_contabeis')->where('empresa_id', $nova)->first();
            $this->assertSame((int) DB::table('diarios_contabeis')->where('empresa_id', $nova)->value('id'), (int) $lanc->diario_id);
            $this->assertSame($nova, (int) $lanc->empresa_origem_id);
            $taxa = DB::table('taxas_cambio')->where('empresa_id', $nova)->value('id');
            $this->assertSame((int) $taxa, (int) DB::table('documentos_tesouraria')->where('empresa_id', $nova)->value('taxa_cambio_id'));
            $this->assertSame(3, DB::table('plano_contas')->where('empresa_id', $nova)->count());
            $periodo = (int) DB::table('periodos_processamento_salarial')->where('empresa_id', $nova)->value('id');
            $this->assertSame($periodo, (int) $lanc->periodo_id);
            $res = DB::table('resultados_folha_salarial')->where('empresa_id', $nova)->first();
            $this->assertSame([$periodo, (int) $colab, (int) $infotipo], [(int) $res->periodo_processamento_salarial_id, (int) $res->colaborador_id,
                json_decode($res->rubricas, true)[0]['infotipo_id']]);
            $movs = DB::table('movimentos_inventario')->where('empresa_id', $nova)->orderBy('id')->pluck('documento_id')->all();
            $this->assertSame([(int) DB::table('guias_saida')->where('empresa_id', $nova)->value('id'), null], array_map(fn ($v) => $v === null ? null : (int) $v, $movs));
            $this->assertSame(1, $r->json('dados.nulificadas')['movimentos_inventario.documento_id']);
            // a origem fica intacta
            $this->assertSame(1, DB::table('lancamentos_contabeis')->where('empresa_id', $this->empresa->id)->count());

            // nunca por cima de dados: destino com dados é recusado
            $this->post('/api/sistema/copias/importar', ['ficheiro' => $upload(), 'empresa_destino_id' => $this->empresa->id], $this->s + ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonPath('codigo', 'DESTINO_COM_DADOS');
            // ficheiro de outra versão do esquema
            $copia['esquema'] = '2000_01_01_000000_antiga';
            file_put_contents($ficheiro, json_encode($copia));
            $this->post('/api/sistema/copias/importar', ['ficheiro' => $upload(), 'nif' => '5417000598'], $this->s + ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonPath('codigo', 'COPIA_INCOMPATIVEL');
        } finally {
            @unlink($ficheiro);
        }
    }

    #[Test]
    public function clona_so_a_estrutura(): void
    {
        $this->dadosDeOrigem();
        $sim = $this->postJson('/api/sistema/copias/clonar', ['nome' => 'Clone, Lda', 'nif' => '5417000597', 'simular' => true], $this->s)->assertOk();
        $this->assertSame(3, $sim->json('dados.por_tabela.plano_contas'));
        $r = $this->postJson('/api/sistema/copias/clonar', ['nome' => 'Clone, Lda', 'nif' => '5417000597'], $this->s)->assertCreated();
        $nova = (int) $r->json('dados.empresa_destino.id');
        $this->assertSame(3, DB::table('plano_contas')->where('empresa_id', $nova)->count());
        $this->assertSame(1, DB::table('diarios_contabeis')->where('empresa_id', $nova)->count());
        $this->assertSame(1, DB::table('infotipos_salariais')->where('empresa_id', $nova)->count());
        $this->assertSame(0, DB::table('terceiros')->where('empresa_id', $nova)->count());
        $this->assertSame(0, DB::table('lancamentos_contabeis')->where('empresa_id', $nova)->count());
        $this->assertNull(DB::table('unidades_negocio')->where('empresa_id', $nova)->where('codigo', 'SEDE')->value('colaborador_gestor_id'));
        $this->assertSame(['SEDE' => 'nulo'], ['SEDE' => DB::table('unidades_negocio')->where('empresa_id', $nova)->where('codigo', 'SEDE')->value('unidade_negocio_pai_id') ?? 'nulo']);
        $this->postJson('/api/sistema/copias/clonar', ['nome' => 'X', 'nif' => '5417000596'], $this->sessao(['config_ferramentas']))->assertForbidden();
    }

    #[Test]
    public function importacao_em_massa_e_transaccional_com_simulacao_e_relatorio(): void
    {
        $modelos = $this->getJson('/api/sistema/migracao/modelos', $this->sessao(['contab_plano_gerir']))->assertOk()->json('dados');
        $this->assertSame(['plano_contas'], array_column($modelos, 'entidade'));
        $xlsx = $this->get('/api/sistema/migracao/modelos/plano_contas', $this->s)->assertOk();
        $this->assertStringEndsWith('.xlsx', $xlsx->baseResponse->getFile()->getFilename());
        @unlink($xlsx->baseResponse->getFile()->getPathname());

        $linhas = [['Conta' => '31', 'Descrição' => 'Clientes', 'Tipo' => 'T'], ['Conta' => '3111', 'Descrição' => 'Clientes nacionais'],
            ['Conta' => '3111', 'Descrição' => 'Repetida'], ['Conta' => '', 'Descrição' => 'Sem código'], ['Conta' => '4311', 'Descrição' => 'Banco']];
        $sim = $this->postJson('/api/sistema/migracao/importar/plano_contas', ['linhas' => $linhas, 'simular' => true], $this->s)->assertOk();
        $this->assertSame([3, 1, 1, 3], [$sim->json('dados.novos'), $sim->json('dados.repetidos'), count($sim->json('dados.rejeitadas')), $sim->json('dados.criados')]);
        $this->assertSame(0, DB::table('plano_contas')->count());
        $this->postJson('/api/sistema/migracao/importar/plano_contas', ['linhas' => $linhas], $this->s)->assertOk()->assertJsonPath('dados.criados', 3);
        $this->assertSame('T', DB::table('plano_contas')->where('codigo', '31')->value('tipo'));
        $this->postJson('/api/sistema/migracao/importar/plano_contas', ['linhas' => [['Conta' => '3111', 'Descrição' => 'Clientes — nacionais']], 'decisao' => 'ACTUALIZAR'], $this->s)
            ->assertOk()->assertJsonPath('dados.actualizados', 1);
        $this->assertSame('Clientes — nacionais', DB::table('plano_contas')->where('codigo', '3111')->value('descricao'));

        // terceiros: conta obrigatória (ADR-028); uma linha com erro = nada importado
        $terc = [['NIF' => '5000000031', 'Nome' => 'Cliente A', 'Tipo' => 'Cliente', 'Conta' => '3111'], ['NIF' => '5000000032', 'Nome' => 'Sem conta'],
            ['NIF' => '5000000033', 'Nome' => 'Tipo mau', 'Tipo' => 'Estado', 'Conta' => '3111']];
        $erro = $this->postJson('/api/sistema/migracao/importar/terceiros', ['linhas' => $terc], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_ERROS');
        $this->assertSame([3, 4], array_column($erro->json('erros.linhas'), 'linha'));
        $this->assertSame(0, DB::table('terceiros')->count());
        $this->postJson('/api/sistema/migracao/importar/terceiros', ['linhas' => array_slice($terc, 0, 2), 'conta_omissao' => '3111'], $this->s)->assertOk()->assertJsonPath('dados.criados', 2);

        $inf = $this->postJson('/api/sistema/migracao/importar/infotipos', ['linhas' => [['Nome' => 'Subsídio de renda', 'INSS' => 'Sim', 'IRT' => 'Até 30.000 Kz Isento']]], $this->s)->assertOk();
        $this->assertSame(1, $inf->json('dados.criados'));
        $this->assertSame(['conditional_30k', true], [DB::table('infotipos_salariais')->value('irt'), (bool) DB::table('infotipos_salariais')->value('sujeito_inss')]);
        $this->postJson('/api/sistema/migracao/importar/diarios', ['linhas' => [['Código' => 'cp', 'Descrição' => 'Compras']]], $this->s)->assertOk();
        $this->assertSame('CP', DB::table('diarios_contabeis')->value('codigo'));
        $this->postJson('/api/sistema/migracao/importar/bancos', ['linhas' => [['Nome' => 'Banco X', 'Código' => '0040', 'Conta' => '4311']]], $this->s)->assertOk()->assertJsonPath('dados.criados', 1);
        $this->postJson('/api/sistema/migracao/importar/cargos', ['linhas' => [['Nome' => 'Contabilista']]], $this->s)->assertOk()->assertJsonPath('dados.criados', 1);
        $this->postJson('/api/sistema/migracao/importar/cargos', ['linhas' => [['Nome' => 'X']]], $this->sessao(['contab_plano_gerir']))->assertForbidden();
    }

    #[Test]
    public function edicao_em_massa_de_produtos_e_fornecedores(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['611' => 'Vendas', '621' => 'Serviços', '3211' => 'Fornecedores', '32121' => 'Fornecedores nacionais'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['p1'] = Produto::create(['codigo' => 'P1', 'nome' => 'A', 'preco_unitario' => 1000, 'codigo_conta' => '611'])->id;
            $this->ids['p2'] = Produto::create(['codigo' => 'P2', 'nome' => 'B', 'preco_unitario' => 250, 'codigo_conta' => '611'])->id;
            $this->ids['f'] = Terceiro::create(['nome' => 'Fornecedor', 'nif' => '5000000041', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211'])->id;
        });
        $this->postJson('/api/sistema/migracao/edicao-massa/produtos', ['ids' => [$this->ids['p1'], $this->ids['p2']], 'contas' => ['codigo_conta' => '621'],
            'dados' => ['taxa_imposto' => 7], 'preco' => ['modo' => 'PERCENTAGEM', 'valor' => 10]], $this->s)->assertOk()->assertJsonPath('dados.alterados', 2);
        $this->assertSame(['1100.00', '275.00'], DB::table('produtos')->orderBy('codigo')->pluck('preco_unitario')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['621', '621'], DB::table('produtos')->pluck('codigo_conta')->all());
        $this->postJson('/api/sistema/migracao/edicao-massa/produtos', ['ids' => [$this->ids['p1']], 'contas' => ['conta_custo' => '9999']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        $this->postJson('/api/sistema/migracao/edicao-massa/fornecedores', ['ids' => [$this->ids['f']], 'contas' => ['codigo_conta' => '__RETIRAR__']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_OBRIGATORIA');
        $this->postJson('/api/sistema/migracao/edicao-massa/fornecedores', ['ids' => [$this->ids['f']], 'contas' => ['codigo_conta' => '32121'], 'dados' => ['codigo_moeda' => 'USD']], $this->s)
            ->assertOk();
        $this->assertSame(['32121', 'USD'], array_values((array) DB::table('terceiros')->where('id', $this->ids['f'])->first(['codigo_conta', 'codigo_moeda'])));
        $this->postJson('/api/sistema/migracao/edicao-massa/clientes', ['ids' => [$this->ids['f']], 'dados' => ['codigo_moeda' => 'EUR']], $this->sessao(['vendas_clientes_gerir']))
            ->assertStatus(422)->assertJsonPath('codigo', 'REGISTOS_INVALIDOS');
    }
}
