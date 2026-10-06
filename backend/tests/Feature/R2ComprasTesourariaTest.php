<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\CotacaoCompra;
use App\Models\LancamentoContabil;
use App\Models\LinhaExtratoBancario;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Services\Compras\ServicoProcessoCompras;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Logistica\ServicoStock;
use App\Services\Tesouraria\ServicoConfigTesouraria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Compras, Armazém e Tesouraria — ronda 2 (R2-G3): decisões 15 (stock negativo → CMV), 16 (câmbio da adjudicação),
 * 17 (factura com projecto), 19 (6621/7621), 21 (arredondamento do banco), 9 (câmbio manual); M-17 (IVA da proposta e
 * da encomenda), A-12 (importação de tesouraria) e M-08 (reconciliação: rascunhos, detalhe, histórico e edição do extracto).
 */
final class R2ComprasTesourariaTest extends TestCase
{
    private int $empresa;

    private Terceiro $fornecedor;

    private Terceiro $cliente;

    private Produto $artigo;

    private Produto $servico;

    private string $hoje;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa()->id;
        app(ContextoEmpresa::class)->executarComo($this->empresa, function () {
            foreach (['3111' => 'Clientes', '3211' => 'Fornecedores', '3281' => 'Compras em trânsito', '211' => 'Compras', '261' => 'Mercadorias', '711' => 'CMV',
                '3451' => 'IVA dedutível', '752' => 'Serviços', '4311' => 'Banco Kz', '6621' => 'Diferenças de câmbio favoráveis', '7621' => 'Diferenças de câmbio desfavoráveis'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            PlanoConta::create(['codigo' => '4312', 'descricao' => 'Banco USD', 'tipo' => 'M', 'codigo_moeda' => 'USD']);
            $this->fornecedor = Terceiro::create(['nome' => 'Fornecedor R2', 'nif' => '5000000401', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211', 'conta_compra_transitoria' => '3281']);
            $this->cliente = Terceiro::create(['nome' => 'Cliente R2', 'nif' => '5000000402', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            $this->artigo = Produto::create(['codigo' => 'A1', 'nome' => 'Cimento', 'preco_unitario' => 150, 'taxa_imposto' => 14, 'movimenta_stock' => true,
                'conta_compra' => '211', 'conta_inventario' => '261', 'conta_custo' => '711', 'conta_iva_dedutivel' => '3451']);
            $this->servico = Produto::create(['codigo' => 'S1', 'nome' => 'Transporte', 'taxa_imposto' => 14, 'movimenta_stock' => false,
                'conta_custo' => '752', 'conta_iva_dedutivel' => '3451']);
            TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => now()->subDays(10)->toDateString(), 'taxa' => '900']);
        });
        $this->s = $this->sessao(['compras_pedidos_view', 'compras_ped_criar', 'compras_prospeccao_view', 'compras_new_proposal', 'compras_encomendas_view', 'compras_enc_criar',
            'compras_faturacao_view', 'compras_fact_registar', 'compras_fact_contabilizar', 'teso_gestao_pagamentos_view', 'teso_doc_emitir', 'teso_doc_eliminar',
            'teso_integrar', 'teso_desintegrar', 'teso_gestao_conciliacao_view', 'teso_conc_importar', 'teso_conc_confirmar', 'teso_conc_anular']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa];
    }

    private function emEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa, $f);
    }

    private function linhas(?string $numeroLan): array
    {
        return $this->emEmpresa(fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)->orderBy('id')->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}".($l->projeto_id ? " P{$l->projeto_id}" : ''))->all());
    }

    #[Test]
    public function entrada_sobre_stock_negativo_leva_a_diferenca_ao_cmv(): void
    {
        $r = $this->emEmpresa(function () {
            $a = Armazem::create(['nome' => 'Central'])->id;
            $st = app(ServicoStock::class);
            $st->entrada($this->artigo->id, $a, '5', '100', $this->hoje, 'Stock inicial');
            $st->saida($this->artigo->id, $a, '8', null, $this->hoje, 'Venda a descoberto', null, null, true);

            return $st->entrada($this->artigo->id, $a, '10', '130', $this->hoje, 'Compra');
        });
        // 3 unidades vendidas a 100 custavam 130: 90 para o CMV; o stock fica 7 × 130 = 910 (= 500 − 800 + 1 300 − 90)
        $this->assertSame('90.00', $r['acerto_cmv']);
        $this->assertSame([['codigo_conta' => '711', 'tipo_dc' => 'D', 'valor' => '90.00'], ['codigo_conta' => '261', 'tipo_dc' => 'C', 'valor' => '90.00']],
            ServicoStock::linhasAcertoCmv($r['acerto_cmv'], '711', '261'));
        $this->assertSame([], ServicoStock::linhasAcertoCmv('0.00', '711', '261'));
        $this->assertSame('-30.00', $this->emEmpresa(function () {
            $a = Armazem::create(['nome' => 'Secundário'])->id;
            $p = Produto::create(['codigo' => 'A2', 'nome' => 'Areia', 'movimenta_stock' => true, 'conta_custo' => '711', 'conta_inventario' => '261']);
            app(ServicoStock::class)->entrada($p->id, $a, '1', '100', $this->hoje, 'Inicial');
            app(ServicoStock::class)->saida($p->id, $a, '2', null, $this->hoje, 'Venda', null, null, true);

            return app(ServicoStock::class)->entrada($p->id, $a, '5', '70', $this->hoje, 'Compra mais barata')['acerto_cmv'];
        }));
    }

    #[Test]
    public function adjudicacao_avalia_a_proposta_em_moeda_ao_cambio_da_data_da_adjudicacao(): void
    {
        $this->emEmpresa(function () {
            TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => $this->hoje, 'taxa' => '1000']);
            $c = (new CotacaoCompra)->forceFill(['codigo_moeda' => 'USD', 'taxa_cambio' => '900', 'taxa_cambio_manual' => false, 'montante_total' => '90000.00', 'montante_total_moeda' => '100.00']);
            $this->assertSame('100000.00', app(ServicoProcessoCompras::class)->valorNaAdjudicacao($c, $this->empresa, $this->hoje));
            $c->taxa_cambio_manual = true;   // câmbio manual: mantém-se o da proposta
            $this->assertSame('90000.00', app(ServicoProcessoCompras::class)->valorNaAdjudicacao($c, $this->empresa, $this->hoje));
        });
    }

    #[Test]
    public function factura_com_projecto_usa_a_conta_do_produto_e_imputa_ao_projecto(): void
    {
        $p = $this->emEmpresa(fn () => Projeto::create(['codigo' => 'PRJ-R2', 'nome' => 'Obra R2'])->id);
        $f = $this->postJson('/api/compras/faturas', ['fornecedor_id' => $this->fornecedor->id, 'numero_fatura' => 'F-PRJ-1', 'data' => $this->hoje, 'projeto_id' => $p,
            'linhas' => [['produto_id' => $this->servico->id, 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_imposto' => 14]]], $this->s)->assertCreated()->json('dados.id');
        $lan = $this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $this->s)->assertOk()->json('dados.numero_lan_contabilizacao');
        $this->assertSame(["D 752 1000.00 P{$p}", "D 3451 140.00 P{$p}", "C 3211 1140.00 P{$p}"], $this->linhas($lan));
    }

    #[Test]
    public function iva_da_proposta_e_da_encomenda_corrige_se_antes_de_adjudicar_ou_facturar(): void
    {
        $pedido = $this->postJson('/api/compras/pedidos', ['nome_requerente' => 'Obra', 'data' => $this->hoje,
            'linhas' => [['produto_id' => $this->servico->id, 'quantidade' => 2, 'preco_unitario' => 100]]], $this->s)->json('dados.id');
        $this->emEmpresa(fn () => DB::table('pedidos_compra')->where('id', $pedido)->update(['estado' => 'APROVADO']));
        $linhaPedido = $this->getJson("/api/compras/pedidos/{$pedido}", $this->s)->json('dados.linhas.0.id');
        $prop = $this->postJson('/api/compras/propostas', ['pedido_compra_id' => $pedido, 'fornecedor_id' => $this->fornecedor->id, 'referencia' => 'P-1', 'data' => $this->hoje,
            'linhas' => [['item_pedido_id' => $linhaPedido, 'preco_unitario' => 500, 'taxa_imposto' => 14]]], $this->s)->assertCreated()->json('dados');
        $this->assertSame('140.00', $prop['total_imposto']);

        $this->putJson("/api/compras/propostas/{$prop['id']}/iva", ['linhas' => [['item_id' => $prop['linhas'][0]['id'], 'taxa_imposto' => 13]]], $this->s)
            ->assertStatus(422);   // taxa ilegal
        $r = $this->putJson("/api/compras/propostas/{$prop['id']}/iva", ['linhas' => [['item_id' => $prop['linhas'][0]['id'], 'taxa_imposto' => 7]]], $this->s)->assertOk();
        $r->assertJsonPath('dados.total_imposto', '70.00')->assertJsonPath('dados.total_com_imposto', '1070.00')->assertJsonPath('dados.linhas.0.imposto_kz', '70.00');
        $this->putJson("/api/compras/propostas/{$prop['id']}/iva", ['linhas' => [['item_id' => $prop['linhas'][0]['id'], 'taxa_imposto' => 7]]], $this->sessao(['compras_prospeccao_view']))
            ->assertForbidden();
    }

    #[Test]
    public function documento_em_moeda_com_varias_linhas_acerta_o_arredondamento_do_banco_na_ultima_linha(): void
    {
        $this->emEmpresa(fn () => TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => $this->hoje, 'taxa' => '900.555']));
        $linha = ['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 10.01];
        $d = $this->postJson('/api/tesouraria/documentos', ['tipo' => 'PAGAMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4312', 'descricao' => 'Três serviços em USD',
            'linhas' => [$linha, $linha, $linha]], $this->s)->assertCreated()->assertJsonPath('dados.valor_total', '27043.67')->json('dados.id');
        $lan = $this->postJson("/api/tesouraria/documentos/{$d}/integrar", [], $this->s)->assertOk()->json('dados.numero_lan_contabilizacao');
        // banco = arred(30,03 × 900,555) = 27 043,67; as duas primeiras linhas 9 014,56 e a última acertada para 9 014,55
        $this->assertSame(['C 4312 27043.67', 'D 752 9014.56', 'D 752 9014.56', 'D 752 9014.55'], $this->linhas($lan));
    }

    #[Test]
    public function cambio_manual_na_tesouraria_e_nas_compras_respeita_a_tolerancia(): void
    {
        $this->postJson('/api/tesouraria/documentos', ['tipo' => 'PAGAMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4312', 'descricao' => 'Câmbio manual',
            'taxa_cambio' => 1000, 'linhas' => [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 10]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CAMBIO_FORA_TOLERANCIA');
        $this->postJson('/api/tesouraria/documentos', ['tipo' => 'PAGAMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4312', 'descricao' => 'Câmbio manual',
            'taxa_cambio' => 920, 'linhas' => [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 10]]], $this->s)->assertCreated()->assertJsonPath('dados.valor_total', '9200.00');
        $this->postJson('/api/compras/faturas', ['fornecedor_id' => $this->fornecedor->id, 'numero_fatura' => 'F-USD-1', 'data' => $this->hoje, 'codigo_moeda' => 'USD', 'taxa_cambio' => 1100,
            'linhas' => [['produto_id' => $this->servico->id, 'quantidade' => 1, 'preco_unitario' => 10, 'taxa_imposto' => 0]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CAMBIO_FORA_TOLERANCIA');
    }

    #[Test]
    public function preenche_6621_7621_onde_as_contas_existem_e_sinaliza_as_restantes(): void
    {
        $outra = $this->criarEmpresa()->id;
        $r = ServicoConfigTesouraria::preencherDiferencasCambio();
        $this->assertContains($outra, $r['empresas_sem_contas']);
        $this->assertNotContains($this->empresa, $r['empresas_sem_contas']);
        $this->assertSame(['diferencas_cambio_desfavoraveis' => '7621', 'diferencas_cambio_favoraveis' => '6621'],
            DB::table('configuracoes_contabeis_tesouraria')->where('empresa_id', $this->empresa)->orderBy('chave')->pluck('codigo_conta', 'chave')->all());
        $this->assertSame(2, DB::table('configuracoes_contabeis_compras')->where('empresa_id', $this->empresa)->whereIn('codigo_conta', ['6621', '7621'])->count());
        // idempotente e não substitui configurações existentes
        DB::table('configuracoes_contabeis_tesouraria')->where('empresa_id', $this->empresa)->where('chave', 'diferencas_cambio_favoraveis')->update(['codigo_conta' => '4311']);
        $this->assertSame(0, ServicoConfigTesouraria::preencherDiferencasCambio($this->empresa)['preenchidas']);
        $this->assertSame('4311', DB::table('configuracoes_contabeis_tesouraria')->where('empresa_id', $this->empresa)->where('chave', 'diferencas_cambio_favoraveis')->value('codigo_conta'));
        $this->getJson('/api/sistema/validacoes/config_diferencas_cambio_em_falta', $this->sessao(['config_manutencao_view']))->assertOk()->assertJsonCount(0, 'dados.linhas');
    }

    #[Test]
    public function importa_documentos_de_tesouraria_com_o_modelo_do_legado(): void
    {
        $this->get('/api/tesouraria/documentos/modelo-importacao', $this->s)->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $cab = ['Data Movimento', 'Tipo Doc', 'Conta Disponibilidades', 'Referência Doc', 'Descrição Geral', 'Conta Contrapartida', 'NIF Terceiro',
            'Nº Documento Origem', 'Data Documento Origem', 'Nota Demonstração', 'Nota Fluxo Caixa', 'Unidade Negocio', 'Centro Custo', 'Descrição Linha', 'Valor', 'URL', 'DÉBITO/CRÉDITO'];
        $dia = now()->format('d/m/Y');
        $livro = new Spreadsheet;
        $livro->getActiveSheet()->fromArray([$cab,
            [$dia, 'PAGAMENTO', '4311', 'CH-1', 'Serviços de Maio', '752', '', '', '', '', '', '', '', 'Transporte', '1000', 'https://exemplo.ao/f1.pdf', 'D'],
            [$dia, 'PAGAMENTO', '4311', 'CH-1', 'Serviços de Maio', '752', '', '', '', '', '', '', '', 'Carga', '500,50', '', 'D'],
            [$dia, 'RECEBIMENTO', '4311', 'TRF-9', 'Adiantamento', '3111', '5000000402', '', '', '', '', '', '', '', '2000', '', 'C'],
            [$dia, 'PAGAMENTO', '4311', 'CH-2', 'Erro', '752', '9999999999', '', '', '', '', '', '', '', '10', '', 'D'],
        ]);
        $caminho = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($livro))->save($caminho);
        $ficheiro = fn () => new UploadedFile($caminho, 'importacao.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $sim = $this->post('/api/tesouraria/documentos/importar', ['ficheiro' => $ficheiro()], $this->s)->assertOk()->json('dados');
        $this->assertTrue($sim['simulacao']);
        $this->assertSame([4, 2, 2], [$sim['linhas_lidas'], $sim['documentos'], count($sim['gravados'])]);
        $this->assertStringContainsString('NIF 9999999999 não registado', $sim['erros'][0]['mensagem']);
        $this->assertSame(0, $this->emEmpresa(fn () => DB::table('documentos_tesouraria')->where('empresa_id', $this->empresa)->count()));

        $real = $this->post('/api/tesouraria/documentos/importar', ['ficheiro' => $ficheiro(), 'simular' => '0'], $this->s)->assertOk()->json('dados');
        $this->assertSame(['1500.50', '2000.00'], array_column($real['gravados'], 'valor_total'));
        $doc = DB::table('documentos_tesouraria')->where('empresa_id', $this->empresa)->where('referencia', 'CH-1')->first();
        $this->assertSame(['PENDENTE', true, 'https://exemplo.ao/f1.pdf'], [$doc->estado, (bool) $doc->importado, $doc->url_documento]);
        $this->assertSame(2, DB::table('itens_documento_tesouraria')->where('documento_tesouraria_id', $doc->id)->count());

        // anular em lote
        $ids = array_column($real['gravados'], 'id');
        $this->postJson('/api/tesouraria/documentos/anular', ['ids' => $ids, 'motivo' => 'Importação de teste'], $this->s)->assertOk()->assertJsonCount(2, 'dados.ok');
        @unlink($caminho);
    }

    #[Test]
    public function importa_produtos_e_categorias_com_o_modelo_do_legado(): void
    {
        $s = $this->sessao(['vendas_produtos_view', 'vendas_produtos_gerir']);
        $this->get('/api/logistica/importacao/produtos/modelo', $s)->assertOk();
        $this->get('/api/logistica/importacao/categorias/modelo', $s)->assertOk();
        $this->get('/api/logistica/importacao/produtos/modelo', $this->s)->assertForbidden();

        $livro = new Spreadsheet;
        $livro->getActiveSheet()->fromArray([
            ['Codigo', 'Nome', 'Preco_Unitario', 'Taxa_IVA', 'Categoria', 'Stock_Atual', 'Gerir_Stock', 'Conta_Venda_Receita', 'Conta_Custo', 'Conta_IVA', 'Conta_Compras', 'Conta_Existencias'],
            ['P900', 'Água 1.5L', '500', '14', 'Bebidas', '10', 'SIM', '', '711', '', '211', '261'],
            ['P901', 'Sumo', '700,50', '13', 'Bebidas', '', 'NAO', '', '', '', '', ''],
            ['A1', 'Cimento (já existe)', '160', '14', '', '', 'SIM', '', '', '', '', ''],
            ['P902', 'Conta errada', '100', '14', '', '', 'NAO', '9999', '', '', '', ''],
        ]);
        $caminho = tempnam(sys_get_temp_dir(), 'prod').'.xlsx';
        (new Xlsx($livro))->save($caminho);
        $ficheiro = fn () => new UploadedFile($caminho, 'produtos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $sim = $this->post('/api/logistica/importacao/produtos', ['ficheiro' => $ficheiro()], $s)->assertOk()->json('dados');
        $this->assertSame([4, 1, 3], [$sim['linhas_lidas'], $sim['criados'] - 1, count($sim['erros'])]);   // P900 + categoria Bebidas
        $this->assertStringContainsString('Stock_Atual ignorado', $sim['avisos'][0]);
        $this->assertFalse($this->emEmpresa(fn () => Produto::query()->where('codigo', 'P900')->exists()));

        $this->post('/api/logistica/importacao/produtos', ['ficheiro' => $ficheiro(), 'simular' => '0', 'actualizar_existentes' => '1'], $s)->assertOk()
            ->assertJsonPath('dados.actualizados', 1);
        $p = $this->emEmpresa(fn () => Produto::query()->where('codigo', 'P900')->with('categoriaProduto')->first());
        $this->assertSame(['500.00', '14.00', true, '711', 'Bebidas'], [(string) $p->preco_unitario, number_format((float) $p->taxa_imposto, 2), (bool) $p->movimenta_stock,
            $p->conta_custo, $p->categoriaProduto?->nome]);
        $this->assertSame('160.00', (string) $this->emEmpresa(fn () => Produto::query()->where('codigo', 'A1')->value('preco_unitario')));
        @unlink($caminho);
    }

    #[Test]
    public function reconciliacao_rascunhos_detalhe_historico_e_edicao_do_extracto(): void
    {
        [$linha, $lan] = $this->emEmpresa(function () {
            $l = LinhaExtratoBancario::create(['codigo_conta' => '4311', 'data' => $this->hoje, 'referencia' => 'X', 'valor' => '100.00', 'tipo_dc' => 'C', 'estado' => 'PENDENTE']);
            $id = DB::table('diarios_contabeis')->insertGetId(['empresa_id' => $this->empresa, 'codigo' => 'BD', 'nome' => 'Bancos']);
            app(ServicoLancamentos::class)->criar(['diario_id' => $id, 'data_documento' => $this->hoje, 'numero_documento' => 'REC-1',
                'descricao' => 'Recebimento', 'linhas' => [['codigo_conta' => '4311', 'tipo_dc' => 'D', 'valor' => '150.00'], ['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => '150.00',
                    'terceiro_id' => $this->cliente->id]]]);

            return [$l->id, LancamentoContabil::query()->where('codigo_conta', '4311')->value('id')];
        });
        // editar a linha do extracto (valor mal lido)
        $this->putJson("/api/tesouraria/extrato/{$linha}", ['valor' => 150, 'referencia' => 'REC-1'], $this->s)->assertOk()->assertJsonPath('dados.valor', '150.00');
        $this->putJson("/api/tesouraria/extrato/{$linha}", ['valor' => -1], $this->s)->assertStatus(422);

        // rascunho
        $grupos = [['extrato' => [$linha], 'lancamentos' => [$lan]]];
        $r = $this->postJson('/api/tesouraria/reconciliacao/rascunhos', ['codigo_conta' => '4311', 'grupos' => $grupos, 'observacoes' => 'Meio caminho'], $this->s)->assertCreated()->json('dados');
        $this->getJson('/api/tesouraria/reconciliacao/rascunhos?codigo_conta=4311', $this->s)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.grupos.0.extrato.0', $linha);
        $this->postJson('/api/tesouraria/reconciliacao/rascunhos', ['codigo_conta' => '4311', 'grupos' => [['extrato' => [999999], 'lancamentos' => [$lan]]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'RASCUNHO_INVALIDO');

        // confirmar, detalhe, histórico; a linha reconciliada já não se edita
        $codigo = $this->postJson('/api/tesouraria/reconciliacao', ['codigo_conta' => '4311', 'grupos' => $grupos], $this->s)->assertCreated()->json('dados.reconciliacao_codigo');
        $this->deleteJson("/api/tesouraria/reconciliacao/rascunhos/{$r['id']}", [], $this->s)->assertOk();
        $this->getJson("/api/tesouraria/reconciliacao/{$codigo}/detalhe", $this->s)->assertOk()->assertJsonPath('dados.grupos.0.extrato.0.id', $linha)
            ->assertJsonPath('dados.grupos.0.lancamentos.0.id', $lan);
        $this->putJson("/api/tesouraria/extrato/{$linha}", ['descricao' => 'x'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LINHA_RECONCILIADA');
        $this->postJson("/api/tesouraria/reconciliacao/{$codigo}/anular", ['motivo' => 'Emparelhamento errado'], $this->s)->assertOk();
        $this->getJson('/api/tesouraria/reconciliacao/historico?codigo_conta=4311&estado=ANULADA', $this->s)->assertOk()->assertJsonCount(1, 'dados')
            ->assertJsonPath('dados.0.motivo_anulacao', 'Emparelhamento errado');
    }
}
