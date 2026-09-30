<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Services\Tesouraria\ServicoReconciliacaoBancaria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Tesouraria (parte 2): reconciliação bancária, folha de caixa, conferência de caixa, libertação de compensações. */
final class TesourariaOperacoesTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3111' => 'Clientes', '3452' => 'IVA liquidado', '4311' => 'Banco BAI', '4511' => 'Caixa sede', '611' => 'Vendas', '752' => 'Serviços',
                '6881' => 'Outros proveitos', '7881' => 'Outros custos'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente A', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611', 'conta_iva_liquidado' => '3452']);
        });
        $this->s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar', 'teso_gestao_pagamentos_view', 'teso_doc_emitir',
            'teso_integrar', 'teso_desintegrar', 'teso_gestao_conciliacao_view', 'teso_conc_importar', 'teso_conc_confirmar', 'teso_conc_anular',
            'teso_folha_caixa_view', 'teso_caixa_operar', 'teso_caixa_fechar', 'teso_caixa_contabilizar', 'teso_caixa_eliminar',
            'teso_gestao_conferencia_view', 'teso_conf_registar', 'teso_conf_assinar', 'teso_conf_reabrir']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function doc(string $tipo, string $conta, string $valor, string $contaFin = '4311'): int
    {
        $d = $this->postJson('/api/tesouraria/documentos', ['tipo' => $tipo, 'data_documento' => $this->hoje, 'conta_financeira' => $contaFin,
            'descricao' => 'Movimento bancário', 'linhas' => [['codigo_conta' => $conta, 'tipo_dc' => $tipo === 'PAGAMENTO' ? 'D' : 'C', 'valor' => $valor]]], $this->s)
            ->assertCreated()->json('dados.id');
        $this->postJson("/api/tesouraria/documentos/{$d}/integrar", [], $this->s)->assertOk();

        return $d;
    }

    private function linhaBanco(int $doc): int
    {
        $lan = $this->getJson("/api/tesouraria/documentos/{$doc}", $this->s)->json('dados.numero_lan_contabilizacao');

        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $lan)->where('codigo_conta', '4311')->value('id'));
    }

    private function lancamento(string $numeroLan): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)->orderBy('id')->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
    }

    private function importar(string $csv): TestResponse
    {
        return $this->post('/api/tesouraria/extrato/importar', ['codigo_conta' => '4311', 'ficheiro' => UploadedFile::fake()->createWithContent('extrato.csv', $csv)],
            $this->s + ['Accept' => 'application/json']);
    }

    #[Test]
    public function leitura_de_numeros_e_datas_do_extracto(): void
    {
        $this->assertSame(1500.5, ServicoReconciliacaoBancaria::numero('1.500,50'));
        $this->assertSame(1500.5, ServicoReconciliacaoBancaria::numero('1,500.50'));
        $this->assertSame(-200.0, ServicoReconciliacaoBancaria::numero('-200'));
        $this->assertSame('2026-09-30', ServicoReconciliacaoBancaria::data('30/09/2026'));
        $this->assertSame('2026-09-30', ServicoReconciliacaoBancaria::data('2026-09-30 00:00:00'));
        $this->assertSame('2026-09-30', ServicoReconciliacaoBancaria::data(46295));   // data Excel
        $this->assertNull(ServicoReconciliacaoBancaria::data('31/02/2026'));
    }

    #[Test]
    public function importa_extracto_sugere_confirma_bloqueia_estorno_e_anula(): void
    {
        $rec = $this->doc('RECEBIMENTO', '611', '1140');
        $pag = $this->doc('PAGAMENTO', '752', '500.50');
        $data = now()->format('d/m/Y');
        $csv = "Data;Referencia;Descrição;Debito;Credito\n{$data};TRF1;Recebimento cliente;;1.140,00\n{$data};CHQ9;Pagamento;500,50;\n{$data};COM1;Comissão bancária;25,00;\n";
        $this->importar($csv)->assertCreated()->assertJsonPath('dados.importadas', 3)->assertJsonPath('dados.duplicadas', 0);
        $this->importar($csv)->assertCreated()->assertJsonPath('dados.importadas', 0)->assertJsonPath('dados.duplicadas', 3);   // o legado duplicava

        $sug = $this->getJson('/api/tesouraria/reconciliacao/sugestoes?codigo_conta=4311', $this->s)->assertOk()->assertJsonCount(2, 'dados')->json('dados');
        $this->assertSame(['MESMA_DATA', 'MESMA_DATA'], array_column($sug, 'criterio'));
        $mapa = $this->getJson("/api/tesouraria/reconciliacao/mapa?codigo_conta=4311&data={$this->hoje}", $this->s)->assertOk()
            ->assertJsonPath('dados.saldo_diario', '639.50')->assertJsonPath('dados.por_reconciliar_extrato.total', '614.50');

        // grupo desequilibrado recusado
        $this->postJson('/api/tesouraria/reconciliacao', ['codigo_conta' => '4311', 'grupos' => [['extrato' => [$sug[0]['linha_extrato_id']], 'lancamentos' => [$sug[1]['lancamento_id']]]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CORRESPONDENCIA_DESEQUILIBRADA');
        $grupos = array_map(fn ($p) => ['extrato' => [$p['linha_extrato_id']], 'lancamentos' => [$p['lancamento_id']]], $sug);
        $codigo = $this->postJson('/api/tesouraria/reconciliacao', ['codigo_conta' => '4311', 'tipo' => 'AUTOMATICA', 'grupos' => $grupos], $this->s)->assertCreated()
            ->assertJsonPath('dados.estado', 'CONCILIADO_BANCO')->assertJsonPath('dados.valor_total', '1640.50')->json('dados.reconciliacao_codigo');
        $this->assertMatchesRegularExpression('/^REC-\d{8}-\d{4}$/', $codigo);

        // documento reconciliado não se desintegra; anulada a reconciliação, já se desintegra
        $this->postJson("/api/tesouraria/documentos/{$rec}/desintegrar", ['motivo' => 'Erro de valor'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTO_RECONCILIADO');
        $this->getJson("/api/tesouraria/reconciliacao/mapa?codigo_conta=4311&data={$this->hoje}", $this->s)->assertJsonPath('dados.por_reconciliar_diario.total', '0.00')
            ->assertJsonPath('dados.saldo_banco_esperado', '614.50');
        $this->postJson("/api/tesouraria/reconciliacao/{$codigo}/anular", ['motivo' => 'Correspondência errada'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ANULADA');
        $this->getJson('/api/tesouraria/extrato?codigo_conta=4311&estado=PENDENTE', $this->s)->assertJsonCount(3, 'dados');
        $this->postJson("/api/tesouraria/documentos/{$rec}/desintegrar", ['motivo' => 'Erro de valor'], $this->s)->assertOk();
        $this->assertNotNull($pag);
    }

    #[Test]
    public function compensacao_de_terceiros_e_libertada_no_estorno_com_rasto(): void
    {
        $d = $this->doc('RECEBIMENTO', '3111', '300');
        $lan = $this->getJson("/api/tesouraria/documentos/{$d}", $this->s)->json('dados.numero_lan_contabilizacao');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $lan)->where('codigo_conta', '3111')
            ->update(['reconciliacao_codigo' => 'REC_1700000000_1']));
        $this->postJson("/api/tesouraria/documentos/{$d}/desintegrar", ['motivo' => 'Erro de conta'], $this->s)->assertOk();
        $this->assertSame(0, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('reconciliacao_codigo', 'REC_1700000000_1')->count()));
    }

    #[Test]
    public function folha_de_caixa_com_factura_quebra_contabilizacao_e_estorno(): void
    {
        $ft = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::query()->value('id')), 'quantidade' => 2.5]]], $this->s)->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->s)->assertOk();

        $s = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje], $this->s)->assertCreated()
            ->assertJsonPath('dados.estado', 'ABERTA')->assertJsonPath('dados.saldo_abertura', '0.00')->json('dados.id');
        $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_JA_ABERTA');
        $mov = fn (array $d) => $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/movimentos", $d + ['data_documento' => $this->hoje, 'descricao' => 'Movimento'], $this->s);

        $mov(['tipo' => 'REC', 'conta_contrapartida' => '3111', 'valor' => 5000, 'terceiro_id' => $this->cliente->id, 'venda_id' => $ft])
            ->assertStatus(422)->assertJsonPath('codigo', 'VALOR_SUPERIOR_EM_ABERTO');
        $mov(['tipo' => 'REC', 'conta_contrapartida' => '3111', 'valor' => 1000, 'terceiro_id' => $this->cliente->id, 'venda_id' => $ft])->assertCreated();
        $mov(['tipo' => 'PAG', 'conta_contrapartida' => '752', 'valor' => 200])->assertCreated()->assertJsonPath('dados.saldo_sistema', '800.00');
        // o recebimento em caixa por contabilizar já conta: um documento de tesouraria de 2 000 excede o que falta (1 850)
        $this->postJson('/api/tesouraria/documentos', ['tipo' => 'RECEBIMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4311', 'descricao' => 'Recebimento FT',
            'linhas' => [['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => 2000, 'terceiro_id' => $this->cliente->id, 'venda_id' => $ft]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'VALOR_SUPERIOR_EM_ABERTO');

        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/fechar", ['saldo_fisico' => 790, 'data' => $this->hoje], $this->s)->assertOk()
            ->assertJsonPath('dados.estado', 'FECHADA')->assertJsonPath('dados.saldo_fecho', '800.00')->assertJsonPath('dados.diferenca', '-10.00');
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/contabilizar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONFIG_TESOURARIA_EM_FALTA');
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['caixa_sobras' => '6881', 'caixa_quebras' => '7881']], $this->s)->assertOk();
        $c = $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/contabilizar", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'CONTABILIZADA');
        [$lanDia, $lanDif] = $c->json('dados.numeros_lan_contabilizacao');
        $this->assertSame(['D 4511 1000.00', 'C 3111 1000.00', 'D 752 200.00', 'C 4511 200.00'], $this->lancamento($lanDia));
        $this->assertSame(['D 7881 10.00', 'C 4511 10.00'], $this->lancamento($lanDif));
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '1000.00')->assertJsonPath('dados.estado', 'PARCIAL');

        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/descontabilizar", ['motivo' => 'Revisão da folha'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'FECHADA');
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '0.00');
        // a próxima sessão sugere o saldo físico contado
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/contabilizar", [], $this->s)->assertOk();
        $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje], $this->s)->assertCreated()->assertJsonPath('dados.saldo_abertura', '790.00');
    }

    #[Test]
    public function conferencia_de_caixa_com_regularizacao_assinatura_e_reabertura(): void
    {
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['caixa_sobras' => '6881', 'caixa_quebras' => '7881']], $this->s)->assertOk();
        $this->doc('RECEBIMENTO', '611', '2000', '4511');   // saldo do sistema: 2 000
        $c = $this->postJson('/api/tesouraria/conferencias', ['codigo_conta' => '4511', 'data_conferencia' => $this->hoje, 'denominacoes' => ['N1000' => 2, 'M100' => 3]], $this->s)
            ->assertCreated()->assertJsonPath('dados.total_fisico', '2300.00')->assertJsonPath('dados.total_sistema', '2000.00')->assertJsonPath('dados.diferenca', '300.00')->json('dados.id');
        $this->postJson("/api/tesouraria/conferencias/{$c}/finalizar", ['regularizar' => true], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'JUSTIFICACAO_EM_FALTA');
        $this->putJson("/api/tesouraria/conferencias/{$c}", ['codigo_conta' => '4511', 'data_conferencia' => $this->hoje, 'denominacoes' => ['N1000' => 2, 'M100' => 3],
            'justificacao' => 'Recebimento em numerário não registado'], $this->s)->assertOk();
        $f = $this->postJson("/api/tesouraria/conferencias/{$c}/finalizar", ['regularizar' => true], $this->s)->assertOk()->assertJsonPath('dados.estado', 'FINALIZADO');
        $this->assertSame(['D 4511 300.00', 'C 6881 300.00'], $this->lancamento($f->json('dados.referencia_lancamento')));   // sobra → proveito

        $this->postJson("/api/tesouraria/conferencias/{$c}/assinar", [], $this->s)->assertStatus(403)->assertJsonPath('codigo', 'AUTO_ASSINATURA');
        $this->postJson("/api/tesouraria/conferencias/{$c}/assinar", [], $this->sessao(['teso_conf_assinar']))->assertOk();
        $this->postJson("/api/tesouraria/conferencias/{$c}/reabrir", ['motivo' => 'Contagem repetida'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'RASCUNHO')
            ->assertJsonPath('dados.nome_gerente', null);
        $this->assertSame(1, app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => LancamentoContabil::query()->where('numero_lan', $f->json('dados.referencia_lancamento'))->whereNotNull('estornado_por_id')->count() > 0 ? 1 : 0));
    }
}
