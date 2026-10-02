<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\StockArmazem;
use App\Services\Logistica\ServicoStock;
use App\Services\POS\ServicoVendasPOS;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POS parte 1 (ADR-047): terminais e meios de pagamento, sessões (abertura, X, Z), vendas com preços com IVA, desconto,
 * troco, stock do armazém do terminal, integração da sessão com CMV e desvios de caixa (automáticos e deliberados).
 * Produto P1: 1 140 Kz com IVA 14 % (base 1 000), custo médio 600, 10 em stock.
 */
final class POSTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    private array $meios;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000001']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV', '487' => 'Transitória transferências',
                '488' => 'Transitória TPA', '489' => 'Transitória numerário', '4511' => 'Caixa', '43101' => 'Banco', '6881' => 'Sobras de caixa', '7881' => 'Quebras de caixa',
                '3791' => 'Operadores', '7671' => 'Comissões TPA'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
        });
        $this->meios = [
            ['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511'],
            ['id' => 'pm_tpa', 'tipo' => 'TPA', 'nome' => 'Multicaixa', 'conta_transitoria' => '488', 'conta_liquidacao' => '43101', 'comissao_pct' => 1, 'conta_comissao' => '7671'],
            ['id' => 'pm_trf', 'tipo' => 'TRANSFERENCIA', 'nome' => 'Transferência', 'conta_transitoria' => '487', 'conta_liquidacao' => '43101'],
        ];
        $this->s = $this->sessao(['pos_view', 'pos_venda', 'pos_desconto', 'pos_fecho', 'pos_integrar', 'pos_descontabilizar', 'pos_terminais_gerir', 'pos_desvio_deliberar']);
        $this->putJson('/api/pos/definicoes', ['conta_sobra' => '6881', 'conta_quebra' => '7881', 'conta_operador' => '3791', 'tolerancia_desvio' => 5], $this->s)->assertOk();
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'T01', 'nome' => 'Loja 1', 'armazem_id' => $this->ids['a'], 'fundo_maneio_padrao' => 5000,
            'meios_pagamento' => $this->meios], $this->s)->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function lancamentos(string $origem): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('tipo_origem', $origem)->whereNull('estorno_de_id')
            ->whereNull('estornado_por_id')->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->sort()->values()->all());
    }

    private function vender(int $sessao, array $pagamentos, float $q = 1, array $extra = [], ?array $s = null)
    {
        return $this->postJson("/api/pos/sessoes/{$sessao}/vendas", $extra + ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => $q]], 'pagamentos' => $pagamentos], $s ?? $this->s);
    }

    #[Test]
    public function terminais_validam_contas_e_copiam_meios(): void
    {
        $s = $this->s;
        $mau = $this->meios;
        $mau[0]['conta_liquidacao'] = '43101';
        $this->postJson('/api/pos/terminais', ['codigo' => 'T02', 'nome' => 'X', 'meios_pagamento' => $mau], $s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLASSE_INVALIDA');
        $mau = $this->meios;
        $mau[1]['conta_transitoria'] = '489';
        $this->postJson('/api/pos/terminais', ['codigo' => 'T02', 'nome' => 'X', 'meios_pagamento' => $mau], $s)->assertStatus(422)->assertJsonPath('codigo', 'TRANSITORIA_REPETIDA');
        $mau = $this->meios;
        unset($mau[1]['conta_comissao']);
        $this->postJson('/api/pos/terminais', ['codigo' => 'T02', 'nome' => 'X', 'meios_pagamento' => $mau], $s)->assertStatus(422)->assertJsonPath('codigo', 'COMISSAO_SEM_CONTA');
        $this->postJson('/api/pos/terminais', ['codigo' => 't01', 'nome' => 'X'], $s)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');

        // T02 com os meios padrão (sem contas: não abre sessão); SUBSTITUIR reaproveita os ids por tipo
        $t2 = $this->postJson('/api/pos/terminais', ['codigo' => 'T02', 'nome' => 'Loja 2'], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/pos/terminais/{$t2}/sessoes", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'TERMINAL_SEM_MEIOS');
        $m = $this->postJson("/api/pos/terminais/{$t2}/copiar-meios", ['terminal_origem_id' => $this->ids['t'], 'modo' => 'SUBSTITUIR'], $s)->assertOk()->json('dados.meios_pagamento');
        $this->assertSame(['pm_num', 'pm_tpa', 'pm_trf'], array_column($m, 'id'));
        $this->assertSame(['T01', 'T01', 'T01'], array_column($m, 'copiado_de'));
        // ACRESCENTAR repetiria as transitórias
        $this->postJson("/api/pos/terminais/{$t2}/copiar-meios", ['terminal_origem_id' => $this->ids['t'], 'modo' => 'ACRESCENTAR'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'TRANSITORIA_REPETIDA');

        // com sessão aberta: não se desactiva; com movimento: o código não muda e não se elimina
        $sessao = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/pos/terminais/{$this->ids['t']}/ativo", ['ativo' => false], $s)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_ABERTA');
        $this->putJson("/api/pos/terminais/{$this->ids['t']}", ['codigo' => 'T09'], $s)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_BLOQUEADO');
        $this->deleteJson("/api/pos/terminais/{$this->ids['t']}", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'TERMINAL_COM_MOVIMENTO');
        $this->deleteJson("/api/pos/terminais/{$t2}", [], $s)->assertOk();
        $this->assertNotNull($sessao);
    }

    #[Test]
    public function operador_aparece_pelo_nome_completo(): void
    {
        $u = $this->criarUtilizador(['nome_completo' => 'Operadora Demo', 'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'all' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
        $sessao = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $s)->assertCreated()->json('dados.id');
        $this->assertSame('Operadora Demo', DB::table('sessoes_pos')->where('id', $sessao)->value('nome_operador'));
        $this->vender($sessao, [['meio_id' => 'pm_num', 'valor' => 1140]], 1, [], $s)->assertCreated();
        $this->assertSame('Operadora Demo', DB::table('vendas')->where('sessao_pos_id', $sessao)->value('pos_operador'));
    }

    #[Test]
    public function venda_fecho_z_integracao_com_cmv_e_desvio_automatico(): void
    {
        $s = $this->s;
        $sessao = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $s)->assertCreated()
            ->assertJsonPath('dados.codigo_sessao', sprintf('T01-%d-0001', now()->year))->assertJsonPath('dados.fundo_maneio_abertura', '5000.00')->json('dados.id');
        $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_JA_ABERTA');

        // 2 × 1 140 com 10 % de desconto = 2 052 (base 1 800 + IVA 252); TPA 1 000 + numerário 1 100 → troco 48
        $v1 = $this->vender($sessao, [['meio_id' => 'pm_tpa', 'valor' => 1000], ['meio_id' => 'pm_num', 'valor' => 1100]], 2, ['percentagem_desconto' => 10])->assertCreated()->json('dados');
        $this->assertSame(['1800.00', '252.00', '2052.00', '228.00', '48.00'], [$v1['total_liquido'], $v1['total_imposto'], $v1['total_bruto'], $v1['desconto'], $v1['pos_troco']]);
        $this->assertSame(sprintf('FR T01%d/1', now()->year), $v1['numero_documento']);
        $this->assertSame([['pm_tpa', '1000.00'], ['pm_num', '1052.00']], array_map(fn ($p) => [$p['meio_id'], $p['valor']], $v1['pos_pagamentos']));
        $this->assertSame('900.00', $v1['itens_venda'][0]['preco_unitario']);
        $v2 = $this->vender($sessao, [['meio_id' => 'pm_trf', 'valor' => 1140, 'referencia' => 'TRF-77']])->assertCreated()->json('dados');
        $this->assertSame('7.000', (string) app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => StockArmazem::query()->where('armazem_id', $this->ids['a'])->where('produto_id', $this->ids['p'])->value('quantidade_stock')));

        // a venda POS não se contabiliza sozinha
        $this->postJson("/api/vendas/documentos/{$v1['id']}/contabilizar", [], $this->sessao(['vendas_fat_contabilizar']))->assertStatus(422)->assertJsonPath('codigo', 'VENDA_POS');

        $x = $this->getJson("/api/pos/sessoes/{$sessao}/relatorio-x", $s)->assertOk()->json('dados');
        $this->assertSame(['3192.00', '1052.00', '6052.00', 2], [$x['total_vendas'], $x['vendas_numerario'], $x['numerario_esperado'], $x['numero_vendas']]);

        // fecho: falta o talão do TPA; contados 6 050 → desvio −2 dentro da tolerância (5) → falta automática
        $this->postJson("/api/pos/sessoes/{$sessao}/fechar", ['numerario_contado' => 6050], $s)->assertStatus(422)->assertJsonPath('codigo', 'FECHO_TPA_EM_FALTA');
        $z = $this->postJson("/api/pos/sessoes/{$sessao}/fechar", ['contagens' => ['5000' => 1, '1000' => 1, '50' => 1],
            'fechos_tpa' => [['meio_id' => 'pm_tpa', 'valor_talao' => 1000, 'operacoes_talao' => 1, 'referencia_lote' => 'L1']]], $s)->assertOk()->json('dados');
        $this->assertSame([sprintf('Z-T01-%d-0001', now()->year), '6050.00', '-2.00', 'DELIBERADO', 'FALTA_CUSTO', true],
            [$z['numero_z'], $z['numerario_contado'], $z['desvio'], $z['estado_desvio'], $z['deliberacao']['decisao'], $z['deliberacao']['automatica']]);
        $this->vender($sessao, [['meio_id' => 'pm_num', 'valor' => 1140]])->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_NAO_ABERTA');

        // integração: D transitórias / C proveitos + IVA, CMV 3 × 600; o desvio automático no seu lançamento
        $this->postJson("/api/pos/sessoes/{$sessao}/contabilizar", [], $s)->assertOk()->assertJsonPath('dados.estado_contabilizacao', 'CONTABILIZADA');
        $this->assertSame(['C 2611 1800.00', 'C 3452 392.00', 'C 611 2800.00', 'D 487 1140.00', 'D 488 1000.00', 'D 489 1052.00', 'D 7111 1800.00'], $this->lancamentos('POS'));
        $this->assertSame(['C 489 2.00', 'D 7881 2.00'], $this->lancamentos('POS_DESVIO'));
        $this->postJson("/api/pos/sessoes/{$sessao}/contabilizar", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_JA_INTEGRADA');
        $this->postJson("/api/pos/sessoes/{$sessao}/deliberacao/anular", ['motivo' => 'x'], $s)->assertStatus(422)->assertJsonPath('codigo', 'DELIBERACAO_AUTOMATICA');

        // descontabilizar = estorno de ambos; reintegrar volta a lançar tudo
        $this->postJson("/api/pos/sessoes/{$sessao}/descontabilizar", ['motivo' => 'Correcção'], $s)->assertOk()->assertJsonPath('dados.estado_contabilizacao', 'PENDENTE');
        $this->assertSame([], $this->lancamentos('POS'));
        $this->assertSame([], $this->lancamentos('POS_DESVIO'));
        $this->getJson("/api/pos/sessoes/{$sessao}", $s)->assertOk()->assertJsonPath('dados.vendas.0.contabilizado', false);
        $this->postJson("/api/pos/sessoes/{$sessao}/contabilizar", [], $s)->assertOk();
        $this->assertCount(7, $this->lancamentos('POS'));
        $this->assertSame(['C 489 2.00', 'D 7881 2.00'], $this->lancamentos('POS_DESVIO'));
        $this->assertNotNull($v2);
    }

    #[Test]
    public function pagamentos_stock_e_desvio_deliberado_com_segregacao(): void
    {
        $s = $this->s;
        $sessao = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", ['fundo_maneio' => 0], $s)->assertCreated()->json('dados.id');
        $this->vender($sessao, [['meio_id' => 'pm_num', 'valor' => 1000]])->assertStatus(422)->assertJsonPath('codigo', 'PAGAMENTO_INSUFICIENTE');
        $this->vender($sessao, [['meio_id' => 'pm_tpa', 'valor' => 1200]])->assertStatus(422)->assertJsonPath('codigo', 'PAGAMENTO_EXCEDE');
        $this->vender($sessao, [['meio_id' => 'pm_trf', 'valor' => 1140]])->assertStatus(422)->assertJsonPath('codigo', 'COMPROVATIVO_EM_FALTA');
        $this->vender($sessao, [['meio_id' => 'pm_num', 'valor' => 20000]], 11)->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE');
        $this->vender($sessao, [['meio_id' => 'pm_xxx', 'valor' => 1140]])->assertStatus(422)->assertJsonPath('codigo', 'MEIO_INVALIDO');
        $caixa = $this->sessao(['pos_venda']);
        $this->vender($sessao, [['meio_id' => 'pm_num', 'valor' => 1140]], 1, ['percentagem_desconto' => 5], $caixa)->assertForbidden();
        $this->vender($sessao, [['meio_id' => 'pm_num', 'valor' => 1140]], 1, [], $caixa)->assertCreated()->assertJsonPath('dados.pos_troco', '0.00');

        // contados 1 000 de 1 140 esperados → falta de 140, acima da tolerância: justificação obrigatória, fica PENDENTE
        $this->postJson("/api/pos/sessoes/{$sessao}/fechar", ['numerario_contado' => 1000], $s)->assertStatus(422)->assertJsonPath('codigo', 'JUSTIFICACAO_OBRIGATORIA');
        $this->postJson("/api/pos/sessoes/{$sessao}/fechar", ['numerario_contado' => 1000, 'justificacao' => 'Falta por apurar'], $s)->assertOk()
            ->assertJsonPath('dados.estado_desvio', 'PENDENTE')->assertJsonPath('dados.desvio', '-140.00');

        // quem abriu a sessão não delibera; outro utilizador delibera depois de integrada
        $this->postJson("/api/pos/sessoes/{$sessao}/deliberacao", ['decisao' => 'FALTA_OPERADOR'], $s)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_NAO_INTEGRADA');
        $this->postJson("/api/pos/sessoes/{$sessao}/contabilizar", [], $s)->assertOk();
        $this->postJson("/api/pos/sessoes/{$sessao}/deliberacao", ['decisao' => 'FALTA_OPERADOR'], $s)->assertStatus(403)->assertJsonPath('codigo', 'SEGREGACAO_FUNCOES');
        $gestor = $this->sessao(['pos_desvio_deliberar']);
        $this->postJson("/api/pos/sessoes/{$sessao}/deliberacao", ['decisao' => 'SOBRA_PROVEITO'], $gestor)->assertStatus(422)->assertJsonPath('codigo', 'DECISAO_INVALIDA');
        $this->postJson("/api/pos/sessoes/{$sessao}/deliberacao", ['decisao' => 'FALTA_OPERADOR', 'nota' => 'Responsabilidade do operador'], $gestor)->assertOk()
            ->assertJsonPath('dados.estado_desvio', 'DELIBERADO')->assertJsonPath('dados.deliberacao.conta', '3791');
        $this->assertSame(['C 489 140.00', 'D 3791 140.00'], $this->lancamentos('POS_DESVIO'));
        $this->postJson("/api/pos/sessoes/{$sessao}/descontabilizar", ['motivo' => 'x'], $s)->assertStatus(422)->assertJsonPath('codigo', 'DELIBERACAO_COM_LANCAMENTO');

        // anular a deliberação = estorno; o desvio volta a pendente
        $this->postJson("/api/pos/sessoes/{$sessao}/deliberacao/anular", ['motivo' => 'Revisto'], $gestor)->assertOk()->assertJsonPath('dados.estado_desvio', 'PENDENTE')
            ->assertJsonPath('dados.deliberacao_cancelada.decisao', 'FALTA_OPERADOR');
        $this->assertSame([], $this->lancamentos('POS_DESVIO'));
    }

    #[Test]
    public function preco_com_mais_de_duas_casas_e_recusado_e_normalizado_no_servico(): void
    {
        // E-VEN-1: a API recusa preços com mais de 2 casas; o serviço (chamado por hotel e lavandaria) normaliza-os
        // a 2 casas antes de validar os pagamentos, para que o total cobrado seja o do documento
        $sessao = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
        $this->vender($sessao, [['meio_id' => 'pm_num', 'valor' => 100]], 3, ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 3, 'preco_unitario' => 10.005]]])
            ->assertStatus(422)->assertJsonValidationErrors('linhas.0.preco_unitario', 'erros');
        // 3 × 10,01 = 30,03 com IVA (sem normalizar, 3 × 10,005 dava 30,01 nos pagamentos e o documento ficava com 30,03)
        $v = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoVendasPOS::class)->vender(
            SessaoPOS::query()->findOrFail($sessao),
            ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 3, 'preco_unitario' => '10.005']], 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 100]]]));
        $this->assertSame(['30.03', '69.97', '0.00'], [(string) $v->total_bruto, (string) $v->pos_troco, (string) $v->desconto]);
        $this->assertSame('30.03', $v->pos_pagamentos[0]['valor']);
    }
}
