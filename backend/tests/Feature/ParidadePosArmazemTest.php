<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Services\Logistica\ServicoStock;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade do POS de armazém com o legado (RELATORIO 2.6): artigos em cartões (com os sem stock), aviso e acerto do stock
 * do armazém com o stock global, e Monitor de Chamada de Senhas (senhas gravadas por empresa/armazém/dia).
 * Dados fictícios: P1 custo 600, 10 no armazém Central e 1 na Loja; P3 sem stock.
 */
final class ParidadePosArmazemTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000071']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Produto Teste', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'movimenta_stock' => true])->id;
            $this->ids['p3'] = Produto::create(['codigo' => 'P3', 'nome' => 'Artigo Esgotado', 'preco_unitario' => 250, 'taxa_imposto' => 14, 'movimenta_stock' => true])->id;
            $this->ids['serv'] = Produto::create(['codigo' => 'S1', 'nome' => 'Serviço', 'preco_unitario' => 500, 'taxa_imposto' => 14, 'movimenta_stock' => false, 'e_servico' => true])->id;
            $this->ids['a'] = Armazem::create(['nome' => 'Central', 'predefinido' => true])->id;
            $this->ids['b'] = Armazem::create(['nome' => 'Loja'])->id;
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['a'], '10', '600', now()->toDateString(), 'Stock inicial');
            app(ServicoStock::class)->entrada($this->ids['p'], $this->ids['b'], '1', '600', now()->toDateString(), 'Stock inicial');
        });
        $this->s = $this->sessao(['pos_armazem_view', 'pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    #[Test]
    public function artigos_mostram_todos_os_produtos_de_stock_com_a_quantidade_do_armazem_e_o_preco(): void
    {
        $this->getJson('/api/pos/armazem/artigos?armazem_id='.$this->ids['a'], $this->sessao([]))->assertForbidden();
        $artigos = collect($this->getJson('/api/pos/armazem/artigos?armazem_id='.$this->ids['a'], $this->s)->assertOk()->json('dados'))->keyBy('codigo');
        $this->assertSame(['P3', 'P1'], $artigos->keys()->all());   // por nome; o serviço não aparece
        $this->assertSame(['10.000', '1000.00'], [$artigos['P1']['quantidade'], $artigos['P1']['preco_unitario']]);
        $this->assertSame('0.000', $artigos['P3']['quantidade']);   // «Esgotado»
        $this->getJson('/api/pos/armazem/artigos', $this->s)->assertStatus(422);

        // armazém de outra empresa: não se vê
        $outra = $this->criarEmpresa(['nif' => '5417000072']);
        $alheio = app(ContextoEmpresa::class)->executarComo($outra->id, fn () => Armazem::create(['nome' => 'Alheio'])->id);
        $this->getJson("/api/pos/armazem/artigos?armazem_id={$alheio}", $this->s)->assertNotFound();
    }

    #[Test]
    public function acerto_aplica_a_diferenca_do_stock_global_ao_armazem_com_movimento_e_sem_mudar_o_global(): void
    {
        $this->assertSame([], $this->getJson('/api/pos/armazem/acerto-stock?armazem_id='.$this->ids['a'], $this->s)->assertOk()->json('dados'));
        // stock global 13 × soma dos armazéns 11 (ex.: stock lançado fora do armazém no legado)
        $this->naEmpresa(fn () => Produto::query()->findOrFail($this->ids['p'])->forceFill(['quantidade_stock' => 13])->save());

        $dif = $this->getJson('/api/pos/armazem/acerto-stock?armazem_id='.$this->ids['a'], $this->s)->assertOk()->json('dados');
        $this->assertCount(1, $dif);
        $this->assertSame(['P1', '13.000', '11.000', '10.000', '2.000', '2.000', false, '12.000'],
            [$dif[0]['codigo'], $dif[0]['global'], $dif[0]['armazens'], $dif[0]['local'], $dif[0]['diferenca'], $dif[0]['ajuste'], $dif[0]['limitado'], $dif[0]['fica']]);

        $url = '/api/pos/armazem/acerto-stock';
        $this->postJson($url, ['armazem_id' => $this->ids['a']], $this->s)->assertForbidden();   // exige pos_armazem_acerto
        $acerto = $this->sessao(['pos_armazem_acerto']);
        $this->postJson($url, ['armazem_id' => $this->ids['a']], $acerto)->assertOk()->assertJsonPath('dados.acertados', 1);

        $this->naEmpresa(function () {
            $this->assertSame('12.000', (string) StockArmazem::query()->where('armazem_id', $this->ids['a'])->where('produto_id', $this->ids['p'])->value('quantidade_stock'));
            $p = Produto::query()->findOrFail($this->ids['p']);
            $this->assertSame(['13.000', '600.000000'], [(string) $p->quantidade_stock, number_format((float) $p->custo_medio, 6, '.', '')]);
            $m = MovimentoInventario::query()->where('documento_tipo', 'ACERTO_ARMAZEM')->sole();
            $this->assertSame(['AJUSTE', 'E', '2.000', $this->ids['a']], [$m->tipo, $m->sentido, number_format((float) $m->quantidade, 3, '.', ''), $m->armazem_id]);
        });
        $this->assertSame([], $this->getJson('/api/pos/armazem/acerto-stock?armazem_id='.$this->ids['a'], $this->s)->json('dados'));
        $this->postJson($url, ['armazem_id' => $this->ids['a']], $acerto)->assertStatus(422)->assertJsonPath('codigo', 'SEM_DIFERENCAS');

        // global abaixo da soma: o ajuste fica limitado ao que o armazém tem (não fica negativo)
        $this->naEmpresa(fn () => Produto::query()->findOrFail($this->ids['p'])->forceFill(['quantidade_stock' => 5])->save());
        $dif = $this->getJson('/api/pos/armazem/acerto-stock?armazem_id='.$this->ids['b'], $this->s)->json('dados');
        $this->assertSame(['-8.000', '-1.000', true, '0.000'], [$dif[0]['diferenca'], $dif[0]['ajuste'], $dif[0]['limitado'], $dif[0]['fica']]);
        $this->postJson($url, ['armazem_id' => $this->ids['b'], 'produtos' => [$this->ids['p']]], $acerto)->assertOk();
        $this->naEmpresa(fn () => $this->assertSame('0.000',
            (string) StockArmazem::query()->where('armazem_id', $this->ids['b'])->where('produto_id', $this->ids['p'])->value('quantidade_stock')));
        $this->postJson($url, ['armazem_id' => $this->ids['a'], 'produtos' => [$this->ids['serv']]], $acerto)->assertStatus(422)->assertJsonPath('codigo', 'PRODUTO_SEM_STOCK');
    }

    #[Test]
    public function senhas_sao_emitidas_chamadas_por_ordem_e_lidas_pelo_monitor(): void
    {
        $a = $this->ids['a'];
        $estado = fn (array $s = []) => $this->getJson("/api/pos/armazem/senhas?armazem_id={$a}", $s ?: $this->s)->assertOk()->json('dados');
        $this->assertSame([null, [], []], [($e = $estado())['actual'], $e['fila'], $e['chamadas']]);

        $soVer = $this->sessao(['pos_armazem_view']);
        $this->postJson('/api/pos/armazem/senhas', ['armazem_id' => $a], $soVer)->assertForbidden();
        $this->postJson('/api/pos/armazem/senhas/chamar', ['armazem_id' => $a], $soVer)->assertForbidden();
        $this->postJson('/api/pos/armazem/senhas/chamar', ['armazem_id' => $a], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_SENHAS');

        $this->assertSame('P-001', $this->postJson('/api/pos/armazem/senhas', ['armazem_id' => $a], $this->s)->assertCreated()->json('dados.codigo'));
        $this->assertSame('P-002', $this->postJson('/api/pos/armazem/senhas', ['armazem_id' => $a], $this->s)->assertCreated()->json('dados.codigo'));
        $this->assertSame('P-001', $this->postJson('/api/pos/armazem/senhas', ['armazem_id' => $this->ids['b']], $this->s)->json('dados.codigo'));   // por armazém
        $this->assertSame(['P-001', 'P-002'], array_column($estado($soVer)['fila'], 'codigo'));   // o monitor só precisa de ver

        $c = $this->postJson('/api/pos/armazem/senhas/chamar', ['armazem_id' => $a], $this->s)->assertOk()->json('dados');
        $this->assertSame(['P-001', 'CHAMADA', 1], [$c['codigo'], $c['estado'], $c['chamadas']]);
        $this->assertSame(2, $this->postJson("/api/pos/armazem/senhas/{$c['id']}/repetir", [], $this->s)->assertOk()->json('dados.chamadas'));
        $this->postJson("/api/pos/armazem/senhas/{$c['id']}/repetir", [], $soVer)->assertForbidden();

        $this->postJson('/api/pos/armazem/senhas/chamar', ['armazem_id' => $a], $this->s)->assertOk()->assertJsonPath('dados.codigo', 'P-002');
        $e = $estado();
        $this->assertSame(['P-002', [], ['P-001']], [$e['actual']['codigo'], $e['fila'], array_column($e['chamadas'], 'codigo')]);
        $this->postJson('/api/pos/armazem/senhas/chamar', ['armazem_id' => $a], $this->s)->assertStatus(422);

        // outra empresa não vê nem repete as senhas desta
        $outra = $this->criarEmpresa(['nif' => '5417000073']);
        $alheia = $this->sessao(['pos_armazem_vender'], $outra);
        $this->postJson("/api/pos/armazem/senhas/{$c['id']}/repetir", [], $alheia)->assertNotFound();
        $this->getJson("/api/pos/armazem/senhas?armazem_id={$a}", $alheia)->assertNotFound();
    }
}
