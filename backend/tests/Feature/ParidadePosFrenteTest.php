<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Services\Logistica\ServicoStock;
use App\Services\Vendas\ServicoConfigVendas;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade POS (RELATORIO.md 3.1.5, 3.1.9, 3.3.2, 3.3.5): stock no armazém do terminal para os cartões da frente de caixa,
 * aviso com TODAS as sessões abertas desde dias anteriores (qualquer terminal e operador — antes só as do próprio
 * operador, por isso T05-2026-0002 não aparecia) e série de cada terminal. Dados fictícios.
 */
final class ParidadePosFrenteTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000088']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV', '489' => 'Transitória numerário',
                '4511' => 'Caixa'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['s'] = Produto::create(['codigo' => 'S1', 'nome' => 'Serviço', 'preco_unitario' => 500, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => false, 'e_servico' => true])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
        });
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function terminal(array $s, string $codigo): int
    {
        return $this->postJson('/api/pos/terminais', ['codigo' => $codigo, 'nome' => "Loja {$codigo}", 'armazem_id' => $this->ids['a'], 'meios_pagamento' => [
            ['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511'],
        ]], $s)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function aviso_mostra_todas_as_sessoes_antigas_de_qualquer_operador(): void
    {
        $admin = $this->sessao(['pos_view', 'pos_venda', 'pos_fecho', 'pos_terminais_gerir']);
        $outro = $this->sessao(['pos_view', 'pos_venda']);
        $t1 = $this->terminal($admin, 'T01');
        $t5 = $this->terminal($admin, 'T05');
        $t2 = $this->terminal($admin, 'T02');
        $s1 = $this->postJson("/api/pos/terminais/{$t1}/sessoes", ['fundo_maneio' => 0], $admin)->assertCreated()->json('dados.id');
        $s5 = $this->postJson("/api/pos/terminais/{$t5}/sessoes", ['fundo_maneio' => 0], $outro)->assertCreated()->json('dados.id');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => SessaoPOS::query()->whereIn('id', [$s1, $s5])->update(['aberto_em' => now()->subDays(3)]));

        $r = $this->getJson('/api/pos/frente/sessoes-antigas', $admin)->assertOk();
        $this->assertSame([$s1, $s5], array_column($r->json('dados.sessoes'), 'id'));
        $this->assertStringStartsWith('Existem 2 sessões de caixa abertas desde dias anteriores:', $r->json('dados.aviso'));

        // ao abrir outra caixa, o aviso inclui a sessão do OUTRO operador (antes só as do próprio)
        $aviso = $this->postJson("/api/pos/terminais/{$t2}/sessoes", ['fundo_maneio' => 0], $admin)->assertCreated()->json('mensagem');
        $codigo5 = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => SessaoPOS::query()->find($s5)->codigo_sessao);
        $this->assertStringContainsString($codigo5, (string) $aviso);
    }

    #[Test]
    public function stock_do_armazem_do_terminal_e_serie_de_cada_terminal(): void
    {
        $s = $this->sessao(['pos_view', 'pos_venda', 'pos_terminais_gerir']);
        $t = $this->terminal($s, 'T-01');
        $stock = $this->getJson("/api/pos/frente/terminais/{$t}/stock", $s)->assertOk()->json('dados');
        $this->assertSame($this->ids['a'], $stock['armazem_id']);
        $this->assertSame('10.000', $stock['stock'][(string) $this->ids['p']]);
        $this->assertArrayNotHasKey((string) $this->ids['s'], $stock['stock']);   // serviço: sem stock

        $ano = now()->year;
        $this->assertSame(['serie' => "FR T01{$ano}", 'prevista' => true], $this->getJson('/api/pos/frente/series', $s)->assertOk()->json('dados.'.$t));
    }

    #[Test]
    public function exige_permissao_do_pos(): void
    {
        $sem = $this->sessao(['vendas_produtos_view']);
        $this->getJson('/api/pos/frente/sessoes-antigas', $sem)->assertForbidden();
        $this->getJson('/api/pos/frente/series', $sem)->assertForbidden();
    }
}
