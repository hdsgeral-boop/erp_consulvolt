<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Services\Logistica\ServicoStock;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F1b do POS: consulta/reimpressão das vendas da sessão, atalho de nota de crédito (bloqueado com a sessão integrada, Q17),
 * suprimento e sangria (numerário esperado, Z, prestação de contas e lançamentos), com números calculados à mão.
 * Produto P1: 1 140 Kz com IVA 14 % (base 1 000), custo 600. Fundo de maneio 5 000.
 */
final class POSFrenteF1bTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000081']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV', '489' => 'Transitória numerário',
                '4511' => 'Caixa', '6881' => 'Sobras', '7881' => 'Quebras', '3791' => 'Operadores'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto Um', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111', 'e_servico' => false])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
        });
        $this->s = $this->sessao(['pos_view', 'pos_venda', 'pos_fecho', 'pos_terminais_gerir', 'pos_movimento_caixa', 'pos_integrar', 'pos_descontabilizar']);
        $this->putJson('/api/pos/definicoes', ['conta_sobra' => '6881', 'conta_quebra' => '7881', 'conta_operador' => '3791', 'tolerancia_desvio' => 5], $this->s)->assertOk();
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'T01', 'nome' => 'Loja 1', 'armazem_id' => $this->ids['a'], 'fundo_maneio_padrao' => 5000,
            'meios_pagamento' => [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511']]], $this->s)
            ->assertCreated()->json('dados.id');
        $this->ids['sessao'] = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", [], $this->s)->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function sid(): int
    {
        return $this->ids['sessao'];
    }

    private function vender(float $valor = 1140): TestResponse
    {
        return $this->postJson("/api/pos/sessoes/{$this->sid()}/vendas", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]],
            'pagamentos' => [['meio_id' => 'pm_num', 'valor' => $valor]]], $this->s);
    }

    private function movimento(string $tipo, float $valor, string $motivo = 'Troco para a caixa', ?array $s = null): TestResponse
    {
        return $this->postJson("/api/pos/sessoes/{$this->sid()}/movimentos", ['tipo' => $tipo, 'valor' => $valor, 'motivo' => $motivo], $s ?? $this->s);
    }

    private function linhas(): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('tipo_origem', 'POS')->whereNull('estorno_de_id')
            ->whereNull('estornado_por_id')->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->sort()->values()->all());
    }

    #[Test]
    public function suprimento_e_sangria_entram_no_esperado_no_z_na_prestacao_e_nos_lancamentos(): void
    {
        $this->vender()->assertCreated();
        $this->movimento('SUPRIMENTO', 2000)->assertCreated()->assertJsonPath('dados.estado', 'ATIVO');
        // esperado = 5 000 (fundo) + 1 140 (vendas) + 2 000 (suprimento) = 8 140
        $this->getJson("/api/pos/sessoes/{$this->sid()}/relatorio-x", $this->s)->assertOk()->assertJsonPath('dados.numerario_esperado', '8140.00');
        $this->movimento('SANGRIA', 3000)->assertCreated();
        // 8 140 − 3 000 = 5 140
        $x = $this->getJson("/api/pos/sessoes/{$this->sid()}/relatorio-x", $this->s)->assertOk()->json('dados');
        $this->assertSame(['2000.00', '3000.00', '5140.00', '1140.00'], [$x['suprimentos'], $x['sangrias'], $x['numerario_esperado'], $x['vendas_numerario']]);
        $this->getJson("/api/pos/sessoes/{$this->sid()}/movimentos", $this->s)->assertOk()->assertJsonCount(2, 'dados.movimentos')->assertJsonPath('dados.sangrias', '3000.00');

        $this->postJson("/api/pos/sessoes/{$this->sid()}/fechar", ['numerario_contado' => 5140], $this->s)->assertOk()
            ->assertJsonPath('dados.numerario_esperado', '5140.00')->assertJsonPath('dados.desvio', '0.00')->assertJsonPath('dados.estado_desvio', 'SEM_DESVIO');
        // fechada: já não se registam nem anulam movimentos
        $this->movimento('SUPRIMENTO', 10)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_NAO_ABERTA');

        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertOk();
        $l = $this->linhas();
        foreach (['D 489 2000.00', 'C 4511 2000.00', 'D 4511 3000.00', 'C 489 3000.00', 'D 489 1140.00'] as $esperada) {
            $this->assertContains($esperada, $l);
        }
        // a transitória do numerário fecha: débitos 1 140 + 2 000, créditos 3 000; falta prestar 1 140 + 2 000 − 3 000 = 140
        $g = $this->sessao(['pos_prestacao_view', 'pos_prestar', 'pos_relatorios_view']);
        $itens = collect($this->getJson("/api/pos/sessoes/{$this->sid()}/prestacao", $g)->assertOk()->json('dados.itens'))->keyBy('chave_item');
        $this->assertSame('140.00', $itens['NUM:pm_num']['valor']);

        // estorno: descontabilizar anula também os lançamentos dos movimentos
        $this->postJson("/api/pos/sessoes/{$this->sid()}/descontabilizar", ['motivo' => 'Correcção'], $this->s)->assertOk();
        $this->assertSame([], $this->linhas());
    }

    #[Test]
    public function validacoes_permissao_excesso_e_anulacao(): void
    {
        $this->movimento('SANGRIA', 5001)->assertStatus(422)->assertJsonPath('codigo', 'SANGRIA_EXCEDE_NUMERARIO');   // esperado 5 000
        $this->movimento('SANGRIA', 100, 'ab')->assertStatus(422);
        $this->movimento('SUPRIMENTO', 0)->assertStatus(422);
        $this->movimento('SUPRIMENTO', 100, 'Troco', $this->sessao(['pos_venda', 'pos_view']))->assertForbidden();

        $id = $this->movimento('SANGRIA', 1000, 'Depósito no cofre')->assertCreated()->json('dados.id');
        $this->getJson("/api/pos/sessoes/{$this->sid()}/relatorio-x", $this->s)->assertJsonPath('dados.numerario_esperado', '4000.00');
        $this->postJson("/api/pos/sessoes/{$this->sid()}/movimentos/{$id}/anular", ['motivo' => 'Engano'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $this->getJson("/api/pos/sessoes/{$this->sid()}/relatorio-x", $this->s)->assertJsonPath('dados.numerario_esperado', '5000.00');
        $this->postJson("/api/pos/sessoes/{$this->sid()}/movimentos/{$id}/anular", ['motivo' => 'Outra vez'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MOVIMENTO_JA_ANULADO');
    }

    #[Test]
    public function consulta_das_vendas_da_sessao_e_atalho_de_nota_de_credito(): void
    {
        $vid = $this->vender()->assertCreated()->json('dados.id');
        $lista = $this->getJson("/api/pos/sessoes/{$this->sid()}/vendas-consulta", $this->s)->assertOk()->json('dados');
        $this->assertCount(1, $lista);
        $this->assertSame([true, '1140.00', 'Consumidor Final'], [$lista[0]['nc_permitida'], $lista[0]['saldo_creditavel'], $lista[0]['cliente_nome']]);
        $this->getJson("/api/pos/sessoes/{$this->sid()}/vendas-consulta?q=ZZZ", $this->s)->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson("/api/pos/sessoes/{$this->sid()}/vendas-consulta/{$vid}", $this->s)->assertOk()->assertJsonCount(1, 'dados.itens_venda');
        $this->getJson("/api/pos/sessoes/{$this->sid()}/vendas-consulta/99999999", $this->s)->assertNotFound();

        // sessão integrada: atalho desactivado e a NC é recusada pelo servidor
        $this->postJson("/api/pos/sessoes/{$this->sid()}/fechar", ['numerario_contado' => 6140], $this->s)->assertOk();
        $this->postJson("/api/pos/sessoes/{$this->sid()}/contabilizar", [], $this->s)->assertOk();
        $l = $this->getJson("/api/pos/sessoes/{$this->sid()}/vendas-consulta", $this->s)->json('dados.0');
        $this->assertFalse($l['nc_permitida']);
        $this->assertStringContainsString('já está integrada', $l['nc_motivo_bloqueio']);
        $vendas = $this->sessao(['vendas_fat_emitir', 'vendas_faturacao_view']);
        $cliente = $this->getJson("/api/pos/sessoes/{$this->sid()}/vendas-consulta", $this->s)->json('dados.0.cliente_id');
        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NC', 'cliente_id' => $cliente, 'venda_origem_id' => $vid, 'motivo_nota_credito' => 'Devolução',
            'data_emissao' => now()->toDateString(), 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]], $vendas)
            ->assertStatus(422)->assertJsonPath('codigo', 'NC_SESSAO_INTEGRADA');

        // depois de descontabilizar, a NC já passa a validação da sessão
        $this->postJson("/api/pos/sessoes/{$this->sid()}/descontabilizar", ['motivo' => 'Para emitir NC'], $this->s)->assertOk();
        $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'NC', 'cliente_id' => $cliente, 'venda_origem_id' => $vid, 'motivo_nota_credito' => 'Devolução',
            'data_emissao' => now()->toDateString(), 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]], $vendas)->assertCreated();
        $n = $this->getJson("/api/pos/sessoes/{$this->sid()}/vendas-consulta", $this->s)->json('dados.0');
        $this->assertSame(['1140.00', '0.00', false], [$n['creditado'], $n['saldo_creditavel'], $n['nc_permitida']]);
    }
}
