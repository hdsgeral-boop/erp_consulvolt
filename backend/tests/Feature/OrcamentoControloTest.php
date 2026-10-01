<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\EncomendaCompra;
use App\Models\ItemCompra;
use App\Models\LancamentoContabil;
use App\Models\LinhaOrcamento;
use App\Models\LogAlertaOrcamental;
use App\Models\OrcamentoAnual;
use App\Models\PedidoExtrapolacaoOrcamento;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\RubricaOrcamental;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Orcamento\ServicoControloOrcamental;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Orçamento parte 2 (ADR-045): controlo orçamental nos documentos. Orçamento aprovado do ano: serviços (75*) 1 200
 * com APROVACAO acima de 100 % (aviso 80 %); pessoal (72*) 600 com BLOQUEAR; financeiros (76*) com BLOQUEAR mas sem
 * dotação. Realizado inicial: 800 em serviços.
 */
final class OrcamentoControloTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s = [];

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['752' => 'Serviços', '7211' => 'Remunerações', '7611' => 'Juros', '3211' => 'Fornecedores', '3451' => 'IVA dedutível', '4311' => 'Banco'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['diario'] = DiarioContabil::create(['codigo' => 'DG', 'nome' => 'Diário geral'])->id;
            $this->ids['fornecedor'] = Terceiro::create(['nome' => 'Fornecedor', 'nif' => '5000000099', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211'])->id;
            $this->ids['servico'] = Produto::create(['codigo' => 'S1', 'nome' => 'Transporte', 'preco_unitario' => 0, 'taxa_imposto' => 0, 'movimenta_stock' => false,
                'conta_custo' => '752', 'conta_iva_dedutivel' => '3451', 'codigo_isencao_fe' => 'M00'])->id;
            $ctrl = fn (string $modo, float $aviso = 80) => ['modo' => $modo, 'aviso_pct' => $aviso, 'limite_pct' => 100, 'base' => 'ANO'];
            $r = [
                'C04' => RubricaOrcamental::create(['tipo' => 'EXPLORACAO', 'codigo' => 'C04', 'nome' => 'Serviços', 'natureza' => 'CUSTO', 'contas' => [['codigo' => '75', 'prefixo' => true]], 'ativo' => true, 'controlo' => $ctrl('APROVACAO')]),
                'C02' => RubricaOrcamental::create(['tipo' => 'EXPLORACAO', 'codigo' => 'C02', 'nome' => 'Pessoal', 'natureza' => 'CUSTO', 'contas' => [['codigo' => '72', 'prefixo' => true]], 'ativo' => true, 'controlo' => $ctrl('BLOQUEAR')]),
                'C05' => RubricaOrcamental::create(['tipo' => 'EXPLORACAO', 'codigo' => 'C05', 'nome' => 'Financeiros', 'natureza' => 'CUSTO', 'contas' => [['codigo' => '76', 'prefixo' => true]], 'ativo' => true, 'controlo' => $ctrl('BLOQUEAR')]),
            ];
            $o = OrcamentoAnual::create(['ano' => (int) now()->format('Y'), 'tipo' => 'EXPLORACAO', 'nome' => 'Orçamento', 'versao' => 1, 'estado' => 'APROVADO']);
            LinhaOrcamento::create(['orcamento_anual_id' => $o->id, 'rubrica_orcamental_id' => $r['C04']->id, 'valores' => array_fill(0, 12, 100), 'total' => 1200]);
            LinhaOrcamento::create(['orcamento_anual_id' => $o->id, 'rubrica_orcamental_id' => $r['C02']->id, 'valores' => array_fill(0, 12, 50), 'total' => 600]);
            app(ServicoLancamentos::class)->criar(['diario_id' => $this->ids['diario'], 'data_documento' => $this->hoje, 'numero_documento' => 'INI', 'descricao' => 'Serviços',
                'linhas' => [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 800], ['codigo_conta' => '4311', 'tipo_dc' => 'C', 'valor' => 800]]]);
            $this->ids += ['orcamento' => $o->id, 'C04' => $r['C04']->id, 'C02' => $r['C02']->id];
        });
        $perm = ['lancamentos_post', 'compras_faturacao_view', 'compras_fact_registar', 'orc_alertas_view', 'orc_controlo_view'];
        $this->s['a'] = $this->sessao($perm);
        $this->s['aprovador'] = $this->sessao([...$perm, 'orc_aprovar_excesso']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function lancamento(string $conta, float $valor, array $s)
    {
        return $this->postJson('/api/contabilidade/lancamentos', ['diario_id' => $this->ids['diario'], 'data_documento' => $this->hoje, 'numero_documento' => 'MAN', 'descricao' => 'Manual',
            'linhas' => [['codigo_conta' => $conta, 'tipo_dc' => 'D', 'valor' => $valor], ['codigo_conta' => '4311', 'tipo_dc' => 'C', 'valor' => $valor]]], $s);
    }

    private function fatura(string $numero, float $valor, array $s, array $extra = [])
    {
        return $this->postJson('/api/compras/faturas', $extra + ['fornecedor_id' => $this->ids['fornecedor'], 'numero_fatura' => $numero, 'data' => $this->hoje,
            'linhas' => [['produto_id' => $this->ids['servico'], 'quantidade' => 1, 'preco_unitario' => $valor, 'taxa_imposto' => 0]]], $s);
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    #[Test]
    public function bloqueio_aviso_pedido_e_aprovacao_de_excesso(): void
    {
        $a = $this->s['a'];
        // pessoal: 700 > 600 com BLOQUEAR → recusado; o alerta fica registado apesar de o lançamento ser desfeito
        $this->lancamento('7211', 700, $a)->assertStatus(422)->assertJsonPath('codigo', 'ORCAMENTO_BLOQUEADO');
        $this->assertSame(0, $this->em(fn () => LancamentoContabil::query()->where('codigo_conta', '7211')->count()));
        $this->assertSame('BLOQUEADO', $this->em(fn () => LogAlertaOrcamental::query()->latest('id')->value('acao')));

        // serviços: 800 + 200 = 1 000 de 1 200 (83 %) → aviso, grava
        $this->lancamento('752', 200, $a)->assertCreated();
        $this->assertSame('CONTINUOU', $this->em(fn () => LogAlertaOrcamental::query()->latest('id')->value('acao')));

        // factura de 300: 1 300 / 1 200 → exige aprovação; pede-se, outro aprova, volta a gravar e o pedido fica utilizado
        $this->fatura('F-1', 300, $a)->assertStatus(422)->assertJsonPath('codigo', 'ORCAMENTO_EXIGE_APROVACAO')
            ->assertJsonPath('erros.chave_documento', "FATURA_FORNECEDOR|{$this->ids['fornecedor']}/F-1");
        $doc = ['tipo' => 'EXPLORACAO', 'origem' => 'FATURA_FORNECEDOR', 'documento' => "{$this->ids['fornecedor']}/F-1", 'data' => $this->hoje,
            'linhas' => [['codigo_conta' => '752', 'valor' => 300]]];
        $this->postJson('/api/orcamento/verificar', $doc, $a)->assertOk()->assertJsonPath('dados.0.estado', 'APROVACAO')->assertJsonPath('dados.0.percentagem', 108.33);
        $this->postJson('/api/orcamento/pedidos-excesso', $doc + ['motivo' => 'Sem permissão de documentos'], $this->sessao(['dashboard_view']))->assertForbidden();
        $p = $this->postJson('/api/orcamento/pedidos-excesso', $doc + ['motivo' => 'Transporte urgente de material'], $a)->assertCreated()->json('dados.0');
        $this->assertEquals([1200, 1000, 300, 100], [$p['valor_orcado'], $p['valor_consumido'], $p['valor'], $p['valor_excesso']]);
        $this->postJson("/api/orcamento/pedidos-excesso/{$p['id']}/decidir", ['decisao' => 'APROVADO'], $a)->assertStatus(403);   // sem permissão
        $this->postJson("/api/orcamento/pedidos-excesso/{$p['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['aprovador'])->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $this->fatura('F-1', 300, $a)->assertCreated();
        $this->assertSame('UTILIZADO', $this->em(fn () => PedidoExtrapolacaoOrcamento::query()->find($p['id'])->estado));

        // aprovar no acto (quem tem a permissão, com motivo); a factura por contabilizar conta como compromisso
        $this->fatura('F-2', 50, $this->s['aprovador'], ['orcamento' => ['aprovar_excesso' => true]])->assertStatus(422)->assertJsonPath('codigo', 'MOTIVO_EM_FALTA');
        $this->fatura('F-2', 50, $this->s['aprovador'], ['orcamento' => ['aprovar_excesso' => true, 'motivo' => 'Serviço já prestado']])->assertCreated();
        $this->assertTrue($this->em(fn () => PedidoExtrapolacaoOrcamento::query()->where('documento', "{$this->ids['fornecedor']}/F-2")->value('autoaprovado')));
        $this->fatura('F-3', 10, $a, ['orcamento' => ['aprovar_excesso' => true, 'motivo' => 'Sem permissão']])->assertStatus(422)->assertJsonPath('codigo', 'ORCAMENTO_EXIGE_APROVACAO');

        // monitor: 1 000 realizado + 350 em facturas por contabilizar = 1 350 → excedido
        $m = collect($this->getJson('/api/orcamento/monitor?ano='.now()->format('Y'), $a)->assertOk()->json('dados'))->keyBy('rubrica_orcamental_id');
        $this->assertEquals([1350, 350, -150, 'EXCEDIDO'], [$m[$this->ids['C04']]['consumido'], $m[$this->ids['C04']]['compromissos'], $m[$this->ids['C04']]['disponivel'],
            $m[$this->ids['C04']]['estado']]);

        // afinação (ADR-064): dinheiro em texto com 2 casas; pedidos e alertas com a rubrica e o orçamento por nome
        $this->assertSame(['1350.00', '350.00', '-150.00'], [$m[$this->ids['C04']]['consumido'], $m[$this->ids['C04']]['compromissos'], $m[$this->ids['C04']]['disponivel']]);
        $this->assertSame('300.00', $this->postJson('/api/orcamento/verificar', array_replace($doc, ['documento' => 'X-9']), $a)->assertOk()->json('dados.0.documento'));
        $pe = collect($this->getJson('/api/orcamento/pedidos-excesso', $this->s['aprovador'])->assertOk()->json('dados'))->firstWhere('id', $p['id']);
        $this->assertSame(['1200.00', '100.00', $this->ids['C04']], [$pe['valor_orcado'], $pe['valor_excesso'], $pe['rubrica']['id']]);
        $this->assertNotEmpty($pe['rubrica']['nome']);
        $this->assertSame((int) now()->format('Y'), $pe['orcamento']['ano']);
        $al = $this->getJson('/api/orcamento/alertas', $a)->assertOk()->json('dados.0');
        $this->assertArrayHasKey('rubrica', $al);
        $this->assertArrayHasKey('orcamento', $al);

        // rubrica sem dotação no orçamento: aviso, não bloqueio (o legado bloqueava qualquer gasto)
        $this->lancamento('7611', 10, $a)->assertCreated();
        $this->assertSame('SEM_DOTACAO', $this->em(fn () => LogAlertaOrcamental::query()->latest('id')->value('estado')));
    }

    #[Test]
    public function compromisso_de_encomenda_pela_parte_por_facturar_e_anulacao(): void
    {
        $this->em(function () {
            $e = EncomendaCompra::create(['numero_encomenda' => 'EC 1', 'fornecedor_id' => $this->ids['fornecedor'], 'data' => now(), 'estado' => 'EM_PROCESSAMENTO', 'montante_total' => 1000]);
            ItemCompra::create(['tipo_documento_origem' => 'ENCOMENDA', 'encomenda_compra_id' => $e->id, 'produto_id' => $this->ids['servico'], 'quantidade' => 10,
                'quantidade_faturada' => 4, 'preco_unitario' => 100, 'total' => 1000, 'total_kz' => 1000]);
            $c = app(ServicoControloOrcamental::class);
            $this->assertEquals([['752', 600.0]], array_map(fn ($x) => [$x['codigo_conta'], $x['valor']], $c->compromissos('EXPLORACAO', (int) now()->format('Y'))));   // 6 de 10 por facturar
            $e->update(['estado' => 'ANULADA']);
            $this->assertSame([], $c->compromissos('EXPLORACAO', (int) now()->format('Y')));
        });
    }
}
