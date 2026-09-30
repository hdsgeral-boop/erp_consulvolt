<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Services\Tesouraria\ServicoMeiosPagamento;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Tesouraria (parte 1): pendentes, pagamentos/recebimentos, integração, estorno, ligação às facturas, meios de pagamento. */
final class TesourariaTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private Terceiro $fornecedor;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3111' => 'Clientes', '3211' => 'Fornecedores', '3452' => 'IVA liquidado', '3451' => 'IVA dedutível', '4311' => 'Banco BAI',
                '4511' => 'Caixa', '611' => 'Vendas', '752' => 'Serviços'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente A', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            $this->fornecedor = Terceiro::create(['nome' => 'Fornecedor B', 'nif' => '5000000002', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211']);
            Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611', 'conta_iva_liquidado' => '3452']);
            Produto::create(['codigo' => 'S1', 'nome' => 'Serviço', 'taxa_imposto' => 14, 'movimenta_stock' => false, 'conta_custo' => '752', 'conta_iva_dedutivel' => '3451']);
        });
        $this->s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar', 'compras_faturacao_view', 'compras_fact_registar',
            'compras_fact_contabilizar', 'teso_gestao_pagamentos_view', 'teso_doc_emitir', 'teso_doc_eliminar', 'teso_integrar', 'teso_desintegrar',
            'teso_meios_pagamento_view', 'teso_meios_gerir']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function produto(string $codigo): int
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::query()->where('codigo', $codigo)->value('id'));
    }

    /** FT de 2 850,00 contabilizada → [id, n.º] */
    private function factura(): array
    {
        $r = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto('P1'), 'quantidade' => 2.5]]], $this->s)->assertCreated();
        $this->postJson('/api/vendas/documentos/'.$r->json('dados.id').'/contabilizar', [], $this->s)->assertOk();

        return [$r->json('dados.id'), $r->json('dados.numero_documento')];
    }

    private function documento(string $tipo, array $linhas, string $conta = '4311'): TestResponse
    {
        return $this->postJson('/api/tesouraria/documentos', ['tipo' => $tipo, 'data_documento' => $this->hoje, 'conta_financeira' => $conta,
            'descricao' => 'Liquidação de documentos', 'referencia' => 'TRF-001', 'linhas' => $linhas], $this->s);
    }

    private function lancamento(?string $numeroLan): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)->orderBy('id')->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor} {$l->numero_documento}")->all());
    }

    #[Test]
    public function recebimento_liquida_a_factura_actualiza_o_pago_e_reverte_por_estorno(): void
    {
        [$ft, $numero] = $this->factura();
        $p = $this->getJson('/api/tesouraria/pendentes?natureza=A_RECEBER', $this->s)->assertOk()
            ->assertJsonPath('dados.0.numero_documento', $numero)->assertJsonPath('dados.0.saldo', '2850.00')->assertJsonPath('dados.0.venda_id', $ft)
            ->assertJsonPath('dados.0.liquidar_a', 'C');
        $linha = fn (string $v) => [['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => $v, 'terceiro_id' => $this->cliente->id, 'venda_id' => $ft]];

        $ano = substr($this->hoje, 0, 4);
        $doc = $this->documento('RECEBIMENTO', $linha('1000'))->assertCreated()->assertJsonPath('dados.numero_documento', "REC A{$ano}/1")
            ->assertJsonPath('dados.estado', 'PENDENTE')->assertJsonPath('dados.valor_total', '1000.00')->assertJsonPath('dados.linhas.0.numero_documento', $numero)->json('dados.id');
        // o documento por integrar já conta como "em liquidação": não se paga duas vezes
        $this->getJson('/api/tesouraria/pendentes', $this->s)->assertJsonPath('dados.0.saldo', '1850.00')->assertJsonPath('dados.0.em_liquidacao', '1000.00');
        $this->documento('RECEBIMENTO', $linha('2000'))->assertStatus(422)->assertJsonPath('codigo', 'VALOR_SUPERIOR_EM_ABERTO');

        $i = $this->postJson("/api/tesouraria/documentos/{$doc}/integrar", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'INTEGRADO');
        $this->assertSame(['D 4311 1000.00 '."REC A{$ano}/1", 'C 3111 1000.00 '.$numero], $this->lancamento($i->json('dados.numero_lan_contabilizacao')));
        $this->assertStringStartsWith('BD', $i->json('dados.numero_lan_contabilizacao'));
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '1000.00')->assertJsonPath('dados.estado', 'PARCIAL');
        $this->putJson("/api/tesouraria/documentos/{$doc}", ['tipo' => 'RECEBIMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4311',
            'descricao' => 'Alterar integrado', 'linhas' => $linha('10')], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_NAO_EDITAVEL');
        $this->postJson("/api/tesouraria/documentos/{$doc}/anular", ['motivo' => 'Duplicado'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_NAO_ANULAVEL');

        // reconciliado com o banco: não se desintegra (o legado procurava um prefixo que nunca existia)
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $i->json('dados.numero_lan_contabilizacao'))
            ->where('codigo_conta', '4311')->update(['reconciliacao_codigo' => 'REC-20260930-TEST']));
        $this->postJson("/api/tesouraria/documentos/{$doc}/desintegrar", ['motivo' => 'Valor errado'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTO_RECONCILIADO');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('reconciliacao_codigo', 'REC-20260930-TEST')->update(['reconciliacao_codigo' => null]));

        $this->postJson("/api/tesouraria/documentos/{$doc}/desintegrar", ['motivo' => 'Valor errado'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'PENDENTE');
        $this->getJson("/api/vendas/documentos/{$ft}", $this->s)->assertJsonPath('dados.valor_pago', '0.00')->assertJsonPath('dados.estado', 'PENDENTE');
        $this->postJson("/api/tesouraria/documentos/{$doc}/anular", ['motivo' => 'Valor errado'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $this->getJson('/api/tesouraria/pendentes', $this->s)->assertJsonPath('dados.0.saldo', '2850.00');
    }

    #[Test]
    public function pagamento_de_factura_de_fornecedor_actualiza_o_estado(): void
    {
        $f = $this->postJson('/api/compras/faturas', ['fornecedor_id' => $this->fornecedor->id, 'numero_fatura' => 'F-77', 'data' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto('S1'), 'quantidade' => 1, 'preco_unitario' => 10000, 'taxa_imposto' => 14]]], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $this->s)->assertOk();
        $this->getJson('/api/tesouraria/pendentes?natureza=A_PAGAR', $this->s)->assertJsonPath('dados.0.saldo', '11400.00')->assertJsonPath('dados.0.fatura_compra_id', $f)
            ->assertJsonPath('dados.0.liquidar_a', 'D');
        $pagar = fn (string $v) => [['codigo_conta' => '3211', 'tipo_dc' => 'D', 'valor' => $v, 'terceiro_id' => $this->fornecedor->id, 'fatura_compra_id' => $f]];

        $d1 = $this->documento('PAGAMENTO', $pagar('4000'))->assertCreated()->json('dados.id');
        $this->postJson("/api/tesouraria/documentos/{$d1}/integrar", [], $this->s)->assertOk();
        $this->getJson("/api/compras/faturas/{$f}", $this->s)->assertJsonPath('dados.estado', 'PARCIAL');
        $d2 = $this->documento('PAGAMENTO', $pagar('7400'), '4511')->assertCreated()->json('dados.id');
        $i2 = $this->postJson("/api/tesouraria/documentos/{$d2}/integrar", [], $this->s)->assertOk();
        $this->assertStringStartsWith('CX', $i2->json('dados.numero_lan_contabilizacao'));
        $this->getJson("/api/compras/faturas/{$f}", $this->s)->assertJsonPath('dados.estado', 'PAGO');
        $this->getJson('/api/tesouraria/pendentes?natureza=A_PAGAR', $this->s)->assertJsonCount(0, 'dados');
        // factura paga não se descontabiliza
        $this->postJson("/api/compras/faturas/{$f}/descontabilizar", ['motivo' => 'Erro de conta'], $this->sessao(['compras_descontab']))->assertStatus(422)
            ->assertJsonPath('codigo', 'FATURA_COM_PAGAMENTOS');
        $this->postJson("/api/tesouraria/documentos/{$d2}/desintegrar", ['motivo' => 'Pagamento devolvido'], $this->s)->assertOk();
        $this->getJson("/api/compras/faturas/{$f}", $this->s)->assertJsonPath('dados.estado', 'PARCIAL');
    }

    #[Test]
    public function validacoes_do_documento_e_permissoes(): void
    {
        $linha = [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 100]];
        $this->documento('PAGAMENTO', $linha, '611')->assertStatus(422)->assertJsonPath('codigo', 'CONTA_FINANCEIRA_INVALIDA');
        $this->documento('RECEBIMENTO', $linha)->assertStatus(422)->assertJsonPath('codigo', 'SENTIDO_INVALIDO');
        $this->documento('PAGAMENTO', [['codigo_conta' => '9999', 'tipo_dc' => 'D', 'valor' => 100]])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        $this->postJson('/api/tesouraria/documentos', ['tipo' => 'PAGAMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4311', 'descricao' => 'abc',
            'linhas' => $linha], $this->s)->assertStatus(422);   // descrição curta
        // pagamento avulso (sem documento em aberto) é permitido: despesa directa
        $d = $this->documento('PAGAMENTO', $linha)->assertCreated()->json('dados.id');
        $this->postJson("/api/tesouraria/documentos/{$d}/integrar", [], $this->sessao(['teso_gestao_pagamentos_view', 'teso_doc_emitir']))->assertForbidden();   // segregação
        $this->postJson("/api/tesouraria/documentos/{$d}/integrar", [], $this->s)->assertOk();
    }

    #[Test]
    public function meios_de_pagamento_com_iban_validado_e_um_so_predefinido(): void
    {
        $bban = '004000001234567890123';
        $numerico = $bban.'1024'.'00';   // A=10, O=24
        $iban = 'AO'.str_pad((string) (98 - (int) bcmod($numerico, '97')), 2, '0', STR_PAD_LEFT).$bban;
        $this->assertTrue(ServicoMeiosPagamento::ibanValido($iban));

        $this->postJson('/api/tesouraria/meios-pagamento', ['nome' => 'BAI', 'codigo_conta' => '4311', 'iban' => 'AO06'.substr($bban, 0, 20).'9'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'IBAN_INVALIDO');
        $this->postJson('/api/tesouraria/meios-pagamento', ['nome' => 'Caixa', 'codigo_conta' => '611'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_FINANCEIRA_INVALIDA');
        $a = $this->postJson('/api/tesouraria/meios-pagamento', ['nome' => 'BAI', 'codigo_conta' => '4311', 'iban' => $iban, 'swift' => 'BAIPAOLU', 'predefinido' => true], $this->s)
            ->assertCreated()->assertJsonPath('dados.codigo_moeda', 'AOA')->json('dados.id');
        $b = $this->postJson('/api/tesouraria/meios-pagamento', ['nome' => 'Caixa', 'codigo_conta' => '4511', 'predefinido' => true], $this->s)->assertCreated()->json('dados.id');
        $lista = collect($this->getJson('/api/tesouraria/meios-pagamento', $this->s)->json('dados'))->keyBy('id');
        $this->assertFalse($lista[$a]['predefinido']);
        $this->assertTrue($lista[$b]['predefinido']);
        $this->deleteJson("/api/tesouraria/meios-pagamento/{$b}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MEIO_PREDEFINIDO');
        $this->deleteJson("/api/tesouraria/meios-pagamento/{$a}", [], $this->s)->assertOk();
    }
}
