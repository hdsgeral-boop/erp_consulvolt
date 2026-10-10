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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade POS (RELATORIO.md 3.5 e 3.8): pré-visualização da integração contabilística (nada gravado, nem a numeração),
 * integração de várias sessões seleccionadas e prazo de levantamento na lista das ordens da lavandaria.
 * Dados fictícios: produto P1 de 1 140 Kz com IVA 14 % (base 1 000), custo médio 600.
 */
final class ParidadePosContasTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000099']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '611' => 'Vendas', '2611' => 'Mercadorias', '7111' => 'CMV', '487' => 'Transitória transferências',
                '488' => 'Transitória TPA', '489' => 'Transitória numerário', '4511' => 'Caixa', '43101' => 'Banco', '6881' => 'Sobras', '7881' => 'Quebras',
                '3791' => 'Operadores'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoConfigVendas::class)->definir(['clientes_default' => '311']);
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1140, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => true, 'conta_inventario' => '2611', 'conta_custo' => '7111'])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
        });
        $this->s = $this->sessao(['pos_view', 'pos_venda', 'pos_fecho', 'pos_integrar', 'pos_terminais_gerir', 'pos_integracao_view']);
        $this->putJson('/api/pos/definicoes', ['conta_sobra' => '6881', 'conta_quebra' => '7881', 'conta_operador' => '3791', 'tolerancia_desvio' => 5], $this->s)->assertOk();
        $this->ids['t'] = $this->postJson('/api/pos/terminais', ['codigo' => 'T01', 'nome' => 'Loja 1', 'armazem_id' => $this->ids['a'], 'meios_pagamento' => [
            ['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511'],
            ['id' => 'pm_trf', 'tipo' => 'TRANSFERENCIA', 'nome' => 'Transferência', 'conta_transitoria' => '487', 'conta_liquidacao' => '43101'],
        ]], $this->s)->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    /** Abre, vende uma unidade a numerário e fecha com o numerário exacto (sem desvio). */
    private function sessaoFechada(): int
    {
        $id = $this->postJson("/api/pos/terminais/{$this->ids['t']}/sessoes", ['fundo_maneio' => 0], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/pos/sessoes/{$id}/vendas", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]], 'pagamentos' => [['meio_id' => 'pm_num', 'valor' => 1140]]], $this->s)
            ->assertCreated();
        $this->postJson("/api/pos/sessoes/{$id}/fechar", ['numerario_contado' => 1140], $this->s)->assertOk();

        return $id;
    }

    private function contarLancamentos(): int
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->count());
    }

    #[Test]
    public function pre_visualizacao_mostra_o_lancamento_sem_gravar_nada(): void
    {
        $id = $this->sessaoFechada();
        $antes = $this->contarLancamentos();
        $seq = DB::table('sequencias_documentos')->where('empresa_id', $this->empresa->id)->get()->toArray();

        $r = $this->postJson('/api/pos/integracao/pre-visualizacao', ['ids' => [$id]], $this->s)->assertOk()->json('dados');
        $this->assertSame(0, $r['impedimentos']);
        $this->assertSame(['1740.00', '1740.00'], [$r['total_debito'], $r['total_credito']]);   // vendas 1 140 + CMV 600
        $lan = $r['sessoes'][0]['lancamentos'][0];
        $linhas = collect($lan['linhas'])->map(fn ($l) => "{$l['tipo_dc']} {$l['codigo_conta']} {$l['valor']}")->sort()->values()->all();
        $this->assertSame(['C 2611 600.00', 'C 3452 140.00', 'C 611 1000.00', 'D 489 1140.00', 'D 7111 600.00'], $linhas);
        $this->assertSame('Transitória numerário', collect($lan['linhas'])->firstWhere('codigo_conta', '489')['nome_conta']);

        // nada ficou gravado: nem lançamentos, nem o estado da sessão, nem a numeração
        $this->assertSame($antes, $this->contarLancamentos());
        $this->assertSame('PENDENTE', DB::table('sessoes_pos')->where('id', $id)->value('estado_contabilizacao'));
        $this->assertEquals($seq, DB::table('sequencias_documentos')->where('empresa_id', $this->empresa->id)->get()->toArray());

        // a integração real gera o mesmo número de lançamento que a pré-visualização mostrou
        $real = $this->postJson('/api/pos/integracao/contabilizar', ['ids' => [$id]], $this->s)->assertOk()->json('dados');
        $this->assertSame([$lan['numero_lan_provisorio']], $real['contabilizadas'][0]['lancamentos']);
        $this->assertSame([], $real['erros']);
    }

    #[Test]
    public function integracao_em_lote_trata_cada_sessao_e_devolve_os_impedimentos(): void
    {
        $a = $this->sessaoFechada();
        $b = $this->sessaoFechada();
        $this->postJson("/api/pos/sessoes/{$a}/contabilizar", [], $this->s)->assertOk();

        $p = $this->postJson('/api/pos/integracao/pre-visualizacao', ['ids' => [$a, $b]], $this->s)->assertOk()->json('dados');
        $this->assertSame(1, $p['impedimentos']);
        $this->assertSame('SESSAO_JA_INTEGRADA', $p['sessoes'][0]['impedimentos'][0]['codigo']);
        $this->assertCount(1, $p['sessoes'][1]['lancamentos']);

        $r = $this->postJson('/api/pos/integracao/contabilizar', ['ids' => [$a, $b]], $this->s)->assertOk()->json('dados');
        $this->assertSame([$b], array_column($r['contabilizadas'], 'sessao_pos_id'));
        $this->assertSame(['SESSAO_JA_INTEGRADA'], array_column($r['erros'], 'codigo'));
        $this->assertSame('CONTABILIZADA', DB::table('sessoes_pos')->where('id', $b)->value('estado_contabilizacao'));
    }

    #[Test]
    public function exige_permissao_e_valida_os_pedidos(): void
    {
        $id = $this->sessaoFechada();
        $leitor = $this->sessao(['pos_integracao_view']);
        $this->postJson('/api/pos/integracao/pre-visualizacao', ['ids' => [$id]], $leitor)->assertForbidden();
        $this->postJson('/api/pos/integracao/contabilizar', ['ids' => [$id]], $leitor)->assertForbidden();
        $this->postJson('/api/pos/integracao/pre-visualizacao', ['ids' => []], $this->s)->assertStatus(422);
        $this->postJson('/api/pos/integracao/pre-visualizacao', ['ids' => [$id, $id]], $this->s)->assertStatus(422);
        $this->postJson('/api/pos/integracao/contabilizar', ['ids' => [999999]], $this->s)->assertStatus(404)->assertJsonPath('codigo', 'SESSAO_INEXISTENTE');

        // sessão de outra empresa: não é encontrada
        $outra = $this->criarEmpresa(['nif' => '5417000098']);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'pos_integrar' => true])->id]);
        $u->empresas()->attach($outra->id);
        $this->postJson('/api/pos/integracao/pre-visualizacao', ['ids' => [$id]], $this->entrar($u) + ['X-Empresa-Id' => $outra->id])->assertStatus(404);
        $this->assertSame('PENDENTE', DB::table('sessoes_pos')->where('id', $id)->value('estado_contabilizacao'));
    }

    #[Test]
    public function lista_de_ordens_da_lavandaria_traz_o_prazo_de_levantamento(): void
    {
        $this->getJson('/api/pos/lavandaria/ordens', $this->sessao(['pos_lavandaria_view']))->assertOk()
            ->assertJsonPath('dados.dias_armazenagem_gratis', fn ($v) => is_int($v) && $v >= 0);
    }
}
