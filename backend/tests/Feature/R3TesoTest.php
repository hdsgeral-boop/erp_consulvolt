<?php

namespace Tests\Feature;

use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\ReciboVenda;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 3 — Tesouraria (r3_teso): recebimentos de Vendas na Tesouraria (espelho do recibo, uma só fonte de lançamento),
 * reconciliação dos recibos anteriores, sobras 68 / quebras 78 e importação de movimentos na Folha de Caixa. Dados fictícios.
 */
final class R3TesoTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private array $s;

    private string $hoje;

    private const PERMISSOES = ['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar', 'vendas_recibos', 'vendas_fat_unpost',
        'teso_gestao_pagamentos_view', 'teso_contab_integracao_view', 'teso_contab_historico_view', 'teso_doc_emitir', 'teso_doc_eliminar',
        'teso_integrar', 'teso_desintegrar', 'teso_folha_caixa_view', 'teso_caixa_operar', 'teso_caixa_fechar', 'teso_caixa_contabilizar'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000123']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['3111', 'Clientes'], ['3452', 'IVA liquidado'], ['4311', 'Banco fictício'], ['4511', 'Caixa fictícia'], ['611', 'Vendas'],
                ['62221', 'Material'], ['6881', 'Outros proveitos'], ['7881', 'Outros custos']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            NotaDemonstracao::create(['codigo' => '19', 'descricao' => 'Nota fictícia']);
            $this->cliente = Terceiro::create(['nome' => 'Cliente Fictício', 'nif' => '5000000031', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            $this->produto = Produto::create(['codigo' => 'PZ', 'nome' => 'Produto Z', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]);
        });
        $this->s = $this->sessao(self::PERMISSOES);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id, 'nome_utilizador' => 'op'.count($permissoes).random_int(1000, 9999)]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function emEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    /** FT de 2 280,00 contabilizada (ou FR, com a conta de disponibilidade). */
    private function factura(string $tipo = 'FT'): int
    {
        $id = $this->postJson('/api/vendas/documentos', ['tipo_documento' => $tipo, 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 2]]] + ($tipo === 'FR' ? ['conta_disponibilidade' => '4311'] : []), $this->s)
            ->assertCreated()->json('dados.id');
        $tipo === 'FT' && $this->postJson("/api/vendas/documentos/{$id}/contabilizar", [], $this->s)->assertOk();

        return $id;
    }

    private function recibo(int $ft, string $montante, bool $contabilizar): array
    {
        return $this->postJson('/api/vendas/recibos', ['cliente_id' => $this->cliente->id, 'data' => $this->hoje, 'codigo_conta' => '4311',
            'meio_pagamento' => 'TRANSFERENCIA', 'contabilizar' => $contabilizar, 'alocacoes' => [['venda_id' => $ft, 'montante' => $montante]]], $this->s)
            ->assertCreated()->json('dados');
    }

    private function espelho(int $recibo): ?DocumentoTesouraria
    {
        return $this->emEmpresa(fn () => DocumentoTesouraria::query()->where('recibo_venda_id', $recibo)->first());
    }

    #[Test]
    public function o_recibo_gera_o_recebimento_na_tesouraria_e_a_integracao_contabiliza_o_recibo_uma_so_vez(): void
    {
        $ft = $this->factura();
        $recibo = $this->recibo($ft, '1000', false);
        $doc = $this->espelho($recibo['id']);
        $this->assertNotNull($doc, 'o recibo gera o recebimento de tesouraria');
        $this->assertSame(['RECEBIMENTO', 'PENDENTE', '4311', '1000.00', $recibo['numero_recibo']],
            [$doc->tipo, $doc->estado, $doc->conta_financeira, (string) $doc->valor_total, $doc->numero_documento]);

        // aparece na lista de Recebimentos da Tesouraria, com a contrapartida de cliente e o n.º da factura
        $numeroFt = $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->json('dados.numero_documento');
        $lista = $this->getJson('/api/tesouraria/documentos?tipo=RECEBIMENTO&por_pagina=1', $this->s)->assertOk();
        $lista->assertJsonPath('dados.0.id', $doc->id)->assertJsonPath('dados.0.recibo_venda_id', $recibo['id']);
        $linhas = $this->getJson("/api/tesouraria/documentos/{$doc->id}", $this->s)->assertOk()->json('dados.linhas');
        $this->assertSame([['3111', 'C', '1000.00', $numeroFt, null]], array_map(fn ($l) => [$l['codigo_conta'], $l['tipo_dc'], $l['valor'], $l['numero_documento'], $l['venda_id']], $linhas));

        // editar/anular na Tesouraria: recusado (faz-se no recibo)
        $this->postJson("/api/tesouraria/documentos/{$doc->id}/anular", ['motivo' => 'Tentativa na tesouraria'], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'DOCUMENTO_RECIBO_VENDA');
        $this->putJson("/api/tesouraria/documentos/{$doc->id}", ['tipo' => 'RECEBIMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4311',
            'descricao' => 'Alterado', 'linhas' => [['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => '10']]], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'DOCUMENTO_RECIBO_VENDA');

        // integrar na Tesouraria = contabilizar o recibo (o lançamento é o do recibo; nenhum segundo lançamento)
        $this->postJson("/api/tesouraria/documentos/{$doc->id}/integrar", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'INTEGRADO');
        $r = $this->emEmpresa(fn () => ReciboVenda::query()->findOrFail($recibo['id']));
        $this->assertTrue($r->contabilizado);
        $doc = $this->espelho($recibo['id']);
        $this->assertSame($r->numero_lan_contabilizacao, $doc->numero_lan_contabilizacao);
        $this->assertSame(1, $this->emEmpresa(fn () => LancamentoContabil::query()->where('codigo_conta', '4311')->where('tipo_dc', 'D')->count()),
            'uma só linha no banco: a do recibo');
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '1000.00');   // não conta duas vezes

        // desintegrar = descontabilizar o recibo (estorno); depois anular o recibo anula o recebimento
        $this->postJson("/api/tesouraria/documentos/{$doc->id}/desintegrar", ['motivo' => 'Erro de banco fictício'], $this->s)->assertOk()
            ->assertJsonPath('dados.estado', 'PENDENTE');
        $this->assertFalse($this->emEmpresa(fn () => ReciboVenda::query()->findOrFail($recibo['id']))->contabilizado);
        $this->postJson("/api/vendas/recibos/{$recibo['id']}/anular", ['motivo' => 'Recibo emitido por engano'], $this->s)->assertOk();
        $doc = $this->espelho($recibo['id']);
        $this->assertSame('ANULADO', $doc->estado);
        $this->assertSame('Recibo emitido por engano', $doc->motivo_anulacao);
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '0.00');
    }

    #[Test]
    public function recibo_contabilizado_na_emissao_fica_integrado_e_a_factura_recibo_tambem_tem_recebimento(): void
    {
        $ft = $this->factura();
        $recibo = $this->recibo($ft, '2280', true);
        $doc = $this->espelho($recibo['id']);
        $this->assertSame('INTEGRADO', $doc->estado);
        $this->assertNotNull($doc->numero_lan_contabilizacao);

        $fr = $this->factura('FR');
        $rfr = $this->emEmpresa(fn () => ReciboVenda::query()->where('venda_origem_id', $fr)->firstOrFail());
        $dfr = $this->espelho($rfr->id);
        $this->assertSame(['PENDENTE', '2280.00'], [$dfr->estado, (string) $dfr->valor_total]);
        // o recebimento da FR integra-se com a própria FR
        $this->postJson("/api/tesouraria/documentos/{$dfr->id}/integrar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RECIBO_DE_FR');
        $this->postJson("/api/vendas/documentos/{$fr}/contabilizar", [], $this->s)->assertOk();
        $this->assertSame('INTEGRADO', $this->espelho($rfr->id)->estado);
    }

    #[Test]
    public function reconciliacao_dos_recibos_anteriores_e_idempotente_e_simula_sem_gravar(): void
    {
        $ft = $this->factura();
        $recibo = $this->recibo($ft, '500', true);
        // recibo anterior à ligação: sem documento de tesouraria
        $this->emEmpresa(function () use ($recibo) {
            $id = DocumentoTesouraria::query()->where('recibo_venda_id', $recibo['id'])->value('id');
            DB::table('itens_documento_tesouraria')->where('documento_tesouraria_id', $id)->delete();
            DB::table('documentos_tesouraria')->where('id', $id)->delete();
        });

        $this->artisan('erp:tesouraria:reconciliar-recibos', ['--simular' => true, '--empresa' => $this->empresa->id])->assertSuccessful();
        $this->assertNull($this->espelho($recibo['id']), 'a simulação não grava');

        $this->artisan('erp:tesouraria:reconciliar-recibos', ['--empresa' => $this->empresa->id])->assertSuccessful();
        $doc = $this->espelho($recibo['id']);
        $this->assertSame('INTEGRADO', $doc->estado);
        $this->artisan('erp:tesouraria:reconciliar-recibos', ['--empresa' => $this->empresa->id])->assertSuccessful();
        $this->assertSame(1, $this->emEmpresa(fn () => DocumentoTesouraria::query()->where('recibo_venda_id', $recibo['id'])->count()), 'idempotente');
    }

    #[Test]
    public function sobras_so_em_contas_68_e_quebras_so_em_78(): void
    {
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['caixa_sobras' => '7881']], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'CONTA_PREFIXO_INVALIDO');
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['caixa_quebras' => '6881']], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'CONTA_PREFIXO_INVALIDO');
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['caixa_sobras' => '6881', 'caixa_quebras' => '7881']], $this->s)->assertOk()
            ->assertJsonPath('dados.caixa_sobras.prefixos', ['68'])->assertJsonPath('dados.caixa_quebras.prefixos', ['78'])
            ->assertJsonPath('dados.diferencas_cambio_favoraveis.prefixos', null);
    }

    #[Test]
    public function abrir_sessao_so_em_contas_45(): void
    {
        $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4311', 'data' => $this->hoje], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_NAO_CAIXA');
        $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje], $this->s)->assertCreated();
    }

    private function xlsx(array $linhas): UploadedFile
    {
        $livro = new Spreadsheet;
        $livro->getActiveSheet()->fromArray(array_merge([['Data Doc (YYYY-MM-DD)', 'Tipo (REC/PAG)', 'Nº Doc', 'Referência', 'Descrição', 'Valor',
            'Conta Contrapartida', 'NIF Terceiro', 'Nota Demonstração', 'Nota Fluxo Caixa', 'URL']], $linhas), null, 'A1', true);
        $caminho = tempnam(sys_get_temp_dir(), 'r3cx').'.xlsx';
        (new Xlsx($livro))->save($caminho);

        return new UploadedFile($caminho, 'movimentos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importar(int $sessao, array $linhas, bool $simular, ?array $s = null): TestResponse
    {
        return $this->post("/api/tesouraria/caixa/sessoes/{$sessao}/importar", ['ficheiro' => $this->xlsx($linhas), 'simular' => $simular ? '1' : '0'],
            ($s ?? $this->s) + ['Accept' => 'application/json']);
    }

    #[Test]
    public function importacao_de_movimentos_na_folha_de_caixa_valida_linha_a_linha_e_e_tudo_ou_nada(): void
    {
        $this->get('/api/tesouraria/caixa/modelo-importacao', $this->s)->assertOk()->assertDownload('Template_Importacao_Caixa.xlsx');
        $sessao = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje], $this->s)->assertCreated()->json('dados.id');
        $boa = [$this->hoje, 'REC', 'FT 9/1', '', 'Recebimento fictício', '1500,50', '3111', '5000000031', '19', '', 'https://exemplo.ao/doc'];
        $saida = [$this->hoje, 'PAG', 'VD 1', '', 'Compra fictícia', 200, '62221', '', '', '', ''];
        $mas = [
            [$this->hoje, 'XYZ', '', '', 'Tipo errado', 10, '62221', '', '', '', ''],
            [$this->hoje, 'PAG', '', '', 'Contrapartida de caixa', 10, '4511', '', '', '', ''],
            [$this->hoje, 'PAG', '', '', 'NIF inexistente', 10, '62221', '5999999999', '', '', ''],
            [$this->hoje, 'PAG', '', '', 'Nota inexistente', 10, '62221', '', '77', '', ''],
        ];

        // simulação com erros: nada gravado e os erros vêm por linha
        $r = $this->importar($sessao, array_merge([$boa, $saida], $mas), true)->assertOk();
        $r->assertJsonPath('dados.simulacao', true)->assertJsonPath('dados.importado', false)->assertJsonCount(2, 'dados.validas')->assertJsonCount(4, 'dados.erros');
        $this->assertSame([4, 5, 6, 7], array_column($r->json('dados.erros'), 'linha'));
        // importação real com erros: tudo ou nada
        $this->importar($sessao, array_merge([$boa], $mas), false)->assertOk()->assertJsonPath('dados.importado', false);
        $this->getJson("/api/tesouraria/caixa/sessoes/{$sessao}", $this->s)->assertJsonCount(0, 'dados.movimentos');

        // só linhas boas: importa na sessão aberta, com terceiro, nota, URL e origem IMPORTACAO
        $this->importar($sessao, [$boa, $saida], false)->assertOk()->assertJsonPath('dados.importado', true)
            ->assertJsonPath('dados.total_entradas', '1500.50')->assertJsonPath('dados.total_saidas', '200.00');
        $m = $this->getJson("/api/tesouraria/caixa/sessoes/{$sessao}", $this->s)->assertJsonCount(2, 'dados.movimentos')->json('dados.movimentos.0');
        $this->assertSame(['REC', '4511', '3111', $this->cliente->id, 'IMPORTACAO', 'https://exemplo.ao/doc'],
            [$m['tipo'], $m['conta_debito'], $m['conta_credito'], $m['terceiro_id'], $m['tipo_origem'], $m['url_documento']]);
        $this->assertNotNull($m['nota_demonstracao_id']);

        // autorização antes da validação; sessão fechada recusa
        $this->importar($sessao, [$boa], true, $this->sessao(['teso_folha_caixa_view']))->assertForbidden();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$sessao}/fechar", ['saldo_fisico' => '1300.50', 'data' => $this->hoje], $this->s)->assertOk();
        $this->importar($sessao, [$boa], true)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_NAO_ABERTA');
    }

    #[Test]
    public function isolamento_por_empresa_na_importacao(): void
    {
        $outra = $this->criarEmpresa(['nif' => '5417000999']);
        $sessao = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje], $this->s)->assertCreated()->json('dados.id');
        $this->importar($sessao, [[$this->hoje, 'PAG', '', '', 'Outra empresa', 10, '62221', '', '', '', '']], true, $this->sessao(self::PERMISSOES, $outra))
            ->assertNotFound();
    }
}
