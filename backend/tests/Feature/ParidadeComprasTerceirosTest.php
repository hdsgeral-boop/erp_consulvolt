<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Paridade dos Terceiros (RELATORIO.md 4.4.2, 4.4.3 e 5.6.1): universo dos clientes, «Editar em massa» e «Importar». */
final class ParidadeComprasTerceirosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['31121' => 'Clientes nacionais', '31122' => 'Clientes estrangeiros', '3211' => 'Fornecedores', '3212' => 'Fornecedores 2', '3281' => 'Compras em trânsito'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
        });
        $this->s = $this->sessao(['vendas_clientes_view', 'vendas_clientes_gerir', 'compras_fornecedores_view', 'compras_forn_gerir']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function terceiro(string $nome, string $tipo, string $conta, ?string $nif = null): Terceiro
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Terceiro::create(['nome' => $nome, 'nif' => $nif, 'tipo' => $tipo, 'codigo_conta' => $conta]));
    }

    #[Test]
    public function lista_de_clientes_inclui_quem_tem_documentos_de_venda(): void
    {
        $this->terceiro('Cliente A', Terceiro::CLIENTE, '31121', '100000001');
        $forn = $this->terceiro('Fornecedor com vendas', Terceiro::FORNECEDOR, '3211', '100000002');
        $this->terceiro('Fornecedor sem vendas', Terceiro::FORNECEDOR, '3211', '100000003');
        $colab = $this->terceiro('Colaborador', Terceiro::COLABORADOR, '3211', '100000004');
        foreach ([$forn->id, $colab->id] as $i => $cliente) {
            DB::table('vendas')->insert(['empresa_id' => $this->empresa->id, 'cliente_id' => $cliente, 'tipo_documento' => 'FT', 'numero_documento' => "FT T/{$i}",
                'data_emissao' => now(), 'total_bruto' => '100.00', 'valor_pendente' => '0.00', 'estado' => 'PAGO']);
        }

        $r = $this->getJson('/api/terceiros?papel=CLIENTE', $this->s)->assertOk()->assertJsonPath('metadados.paginacao.total', 2);
        $nomes = collect($r->json('dados'))->pluck('nome')->all();
        $this->assertSame(['Cliente A', 'Fornecedor com vendas'], $nomes);
        $this->assertTrue($r->json('dados.1.tem_documentos_venda'));
        $this->assertFalse($r->json('dados.0.tem_documentos_venda'));
        $this->assertFalse($r->json('dados.1.e_cliente'));
        // a lista de fornecedores não muda
        $this->getJson('/api/terceiros?papel=FORNECEDOR', $this->s)->assertJsonPath('metadados.paginacao.total', 2)->assertJsonMissingPath('dados.0.tem_documentos_venda');
        // gravar a ficha a partir da lista de clientes dá-lhe o papel de cliente
        // Fase A: a ficha de cliente exige a conta de cliente (classe 31); a de fornecedor mantém-se
        $this->putJson("/api/terceiros/{$forn->id}", ['papel' => 'CLIENTE', 'nome' => 'Fornecedor com vendas'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLIENTE_OBRIGATORIA');
        $this->putJson("/api/terceiros/{$forn->id}", ['papel' => 'CLIENTE', 'nome' => 'Fornecedor com vendas', 'conta_cliente' => '31121'], $this->s)->assertOk()
            ->assertJsonPath('dados.tipo', 'CLIENTE_FORNECEDOR')->assertJsonPath('dados.conta_cliente', '31121')->assertJsonPath('dados.conta_fornecedor', '3211');
    }

    #[Test]
    public function edicao_em_massa_de_fornecedores_e_clientes(): void
    {
        $a = $this->terceiro('F1', Terceiro::FORNECEDOR, '3211', '200000001');
        $b = $this->terceiro('F2', Terceiro::FORNECEDOR, '3211', '200000002');
        $this->postJson('/api/terceiros/edicao-massa', ['papel' => 'FORNECEDOR', 'ids' => [$a->id, $b->id], 'codigo_conta' => '3212', 'conta_compra_transitoria' => '3281'], $this->s)
            ->assertOk()->assertJsonPath('dados.actualizados', 2);
        // Fase A: a «conta de terceiro» dos fornecedores é a conta de fornecedor (o codigo_conta antigo fica como estava)
        $this->getJson("/api/terceiros/{$a->id}", $this->s)->assertJsonPath('dados.conta_fornecedor', '3212')->assertJsonPath('dados.conta_compra_transitoria', '3281');
        $this->postJson('/api/terceiros/edicao-massa', ['papel' => 'FORNECEDOR', 'ids' => [$a->id], 'retirar_conta_transitoria' => true], $this->s)->assertOk();
        $this->getJson("/api/terceiros/{$a->id}", $this->s)->assertJsonPath('dados.conta_compra_transitoria', null)->assertJsonPath('dados.conta_fornecedor', '3212');

        // conta inexistente, nada a alterar, ids de outra empresa
        $this->postJson('/api/terceiros/edicao-massa', ['papel' => 'FORNECEDOR', 'ids' => [$a->id], 'codigo_conta' => '9999'], $this->s)->assertStatus(422);
        $this->postJson('/api/terceiros/edicao-massa', ['papel' => 'FORNECEDOR', 'ids' => [$a->id]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_ALTERACOES');
        $outra = $this->criarEmpresa();
        $alheio = app(ContextoEmpresa::class)->executarComo($outra->id, fn () => Terceiro::create(['nome' => 'Alheio', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '31121']));
        $this->postJson('/api/terceiros/edicao-massa', ['papel' => 'CLIENTE', 'ids' => [$alheio->id], 'codigo_conta' => '31122'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'TERCEIRO_INEXISTENTE');
        $this->assertSame('31121', app(ContextoEmpresa::class)->executarComo($outra->id, fn () => Terceiro::query()->find($alheio->id)->codigo_conta));

        // permissão por papel
        $soClientes = $this->sessao(['vendas_clientes_view', 'vendas_clientes_gerir']);
        $this->postJson('/api/terceiros/edicao-massa', ['papel' => 'FORNECEDOR', 'ids' => [$a->id], 'codigo_conta' => '3211'], $soClientes)->assertForbidden();
        $c = $this->terceiro('C1', Terceiro::CLIENTE, '31121');
        $this->postJson('/api/terceiros/edicao-massa', ['papel' => 'CLIENTE', 'ids' => [$c->id], 'codigo_conta' => '31122'], $soClientes)->assertOk();
    }

    #[Test]
    public function importacao_de_clientes_simula_e_grava(): void
    {
        $this->terceiro('Existente', Terceiro::CLIENTE, '31121', '300000001');
        $livro = new Spreadsheet;
        $livro->getActiveSheet()->fromArray([
            ['NIF', 'Nome', 'Endereco', 'Email', 'Conta_Contábil'],
            ['300000002', 'Novo Cliente', 'Rua Teste 1', 'novo@exemplo.test', '31121'],
            ['300000002', 'Novo Cliente (repetido)', '', '', '31121'],
            ['300000001', 'Existente Actualizado', '', '', '31122'],
            ['300000003', 'Sem Conta', '', '', ''],
            ['300000004', 'Conta Errada', '', '', '9999'],
        ]);
        $caminho = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($livro))->save($caminho);
        $ficheiro = fn () => new UploadedFile($caminho, 'clientes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->post('/api/terceiros/importacao', ['ficheiro' => $ficheiro()], $this->s + ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('dados.simulacao', true)->assertJsonPath('dados.criados', 1)->assertJsonPath('dados.ignorados', 1)
            ->assertJsonPath('dados.repetidos', 1)->assertJsonCount(2, 'dados.erros');
        $this->getJson('/api/terceiros?papel=CLIENTE&pesquisa=Novo', $this->s)->assertJsonPath('metadados.paginacao.total', 0);

        $this->post('/api/terceiros/importacao', ['ficheiro' => $ficheiro(), 'simular' => '0', 'actualizar_existentes' => '1'], $this->s + ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('dados.criados', 1)->assertJsonPath('dados.actualizados', 1);
        $this->getJson('/api/terceiros?papel=CLIENTE&pesquisa=Novo', $this->s)->assertJsonPath('dados.0.endereco', 'Rua Teste 1')->assertJsonPath('dados.0.conta_cliente', '31121');
        $this->getJson('/api/terceiros?papel=CLIENTE&pesquisa=300000001', $this->s)->assertJsonPath('dados.0.nome', 'Existente Actualizado');
        @unlink($caminho);

        $this->get('/api/terceiros/importacao/modelo', $this->s)->assertOk();
        $this->get('/api/terceiros/importacao/modelo', $this->sessao(['vendas_clientes_view']))->assertForbidden();
    }
}
