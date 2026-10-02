<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Logistica\ServicoStock;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Logística parte 1 (ADR-042): custo médio, transferências, ajustes, extracto do artigo e inventário físico. */
final class LogisticaStockTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    private array $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['2611' => 'Mercadorias', '6851' => 'Sobras de inventário', '7851' => 'Quebras de inventário', '7111' => 'CMV'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['p'] = Produto::create(['codigo' => 'P1', 'nome' => 'Parafuso', 'preco_unitario' => 200, 'movimenta_stock' => true, 'conta_inventario' => '2611'])->id;
            $this->ids['q'] = Produto::create(['codigo' => 'Q1', 'nome' => 'Porca', 'preco_unitario' => 50, 'movimenta_stock' => true, 'conta_inventario' => '2611'])->id;
        });
        $perm = ['armazem_armazens_view', 'armazem_stock_view', 'armazem_movimentos_view', 'armazem_config', 'armazem_ajuste', 'armazem_transferencia',
            'inventario_sessoes_view', 'inventario_contagem_view', 'inventario_revisao_view', 'inventario_iniciar', 'inventario_manage', 'inventario_count', 'inventario_rever'];
        $this->s = $this->sessao($perm);
        $this->s2 = $this->sessao($perm);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function stockEm(int $armazem, string $k = 'p'): string
    {
        return (string) app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => StockArmazem::query()->where('armazem_id', $armazem)->where('produto_id', $this->ids[$k])->value('quantidade_stock'));
    }

    #[Test]
    public function custo_medio_transferencia_ajustes_e_extracto(): void
    {
        $s = $this->s;
        $a = $this->postJson('/api/logistica/armazens', ['nome' => 'Central'], $s)->assertCreated()->assertJsonPath('dados.predefinido', true)->json('dados.id');
        $b = $this->postJson('/api/logistica/armazens', ['nome' => 'Loja'], $s)->assertCreated()->assertJsonPath('dados.predefinido', false)->json('dados.id');
        $this->postJson('/api/logistica/armazens', ['nome' => 'central'], $s)->assertStatus(422)->assertJsonPath('codigo', 'ARMAZEM_DUPLICADO');
        $hoje = now()->toDateString();
        $aj = fn (int $arm, string $sent, $q, $custo = null) => $this->postJson('/api/logistica/ajustes', ['produto_id' => $this->ids['p'], 'armazem_id' => $arm, 'sentido' => $sent,
            'quantidade' => $q, 'custo_unitario' => $custo, 'data' => $hoje, 'motivo' => 'Stock inicial'], $s);
        $aj($a, 'E', 10, 100)->assertCreated();
        $aj($a, 'E', 10, 130)->assertCreated()->assertJsonPath('dados.custo_medio_apos', '115.000000');   // (10 × 100 + 10 × 130) / 20

        // transferência ao custo médio: o total e o custo médio não mudam
        $this->postJson('/api/logistica/transferencias', ['armazem_origem_id' => $a, 'armazem_destino_id' => $a, 'data' => $hoje, 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 1]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MESMO_ARMAZEM');
        $this->postJson('/api/logistica/transferencias', ['armazem_origem_id' => $a, 'armazem_destino_id' => $b, 'data' => $hoje, 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 100]]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'STOCK_INSUFICIENTE');
        $this->postJson('/api/logistica/transferencias', ['armazem_origem_id' => $a, 'armazem_destino_id' => $b, 'data' => $hoje, 'linhas' => [['produto_id' => $this->ids['p'], 'quantidade' => 5]]], $s)
            ->assertCreated()->assertJsonPath('dados.numero', 'TRF '.now()->format('Y').'/0001')->assertJsonPath('dados.movimentos', 2);
        $this->assertSame(['15.000', '5.000'], [$this->stockEm($a), $this->stockEm($b)]);
        $aj($b, 'S', 3)->assertCreated()->assertJsonPath('dados.valor', '345.00');   // saída ao custo médio 115

        // extracto: saldo corrido em quantidade e valor (10×100 + 10×130 − 3×115 = 1 955 = 17 × 115)
        $e = $this->getJson("/api/logistica/produtos/{$this->ids['p']}/extracto?de={$hoje}&ate={$hoje}", $s)->assertOk()->json('dados');
        $this->assertCount(5, $e['movimentos']);
        $this->assertSame(['17.000', '1955.00'], [$e['saldo_final']['quantidade'], $e['saldo_final']['valor']]);
        $this->getJson('/api/logistica/stock', $s)->assertOk()->assertJsonPath('dados.valorizacao.total', '1955.00');

        // stock mínimo → ruptura
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::query()->whereKey($this->ids['p'])->update(['stock_minimo' => 5]));
        $loja = collect($this->getJson("/api/logistica/stock?armazem_id={$b}", $s)->json('dados.linhas'))->firstWhere('produto_id', $this->ids['p']);
        $this->assertTrue($loja['ruptura']);   // 2 ≤ 5

        // eliminar armazém: com stock recusado; o predefinido passa para outro
        $this->deleteJson("/api/logistica/armazens/{$b}", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->putJson("/api/logistica/armazens/{$b}", ['nome' => 'Loja', 'predefinido' => true], $s)->assertOk()->assertJsonPath('dados.predefinido', true);
        $this->getJson('/api/logistica/armazens', $s)->assertJsonPath('dados.0.id', $b)->assertJsonPath('dados.1.predefinido', false);
    }

    #[Test]
    public function inventario_bloqueia_movimentos_regulariza_e_reabre_por_estorno(): void
    {
        $s = $this->s;
        $hoje = now()->toDateString();
        $a = $this->postJson('/api/logistica/armazens', ['nome' => 'Central'], $s)->json('dados.id');
        $b = $this->postJson('/api/logistica/armazens', ['nome' => 'Loja'], $s)->json('dados.id');
        $this->postJson('/api/logistica/ajustes', ['produto_id' => $this->ids['p'], 'armazem_id' => $a, 'sentido' => 'E', 'quantidade' => 15, 'custo_unitario' => 115, 'data' => $hoje,
            'motivo' => 'Stock inicial'], $s)->assertCreated();

        $inv = $this->postJson('/api/logistica/inventarios', ['armazem_id' => $a, 'data' => $hoje, 'descricao' => 'Inventário anual'], $s)->assertCreated()
            ->assertJsonPath('dados.estado', 'EM_CONTAGEM')->json('dados.id');
        $this->postJson('/api/logistica/inventarios', ['armazem_id' => $a, 'data' => $hoje], $s)->assertStatus(422)->assertJsonPath('codigo', 'INVENTARIO_EM_CURSO');
        // armazém congelado; o outro armazém continua a movimentar
        $mov = fn (int $arm) => $this->postJson('/api/logistica/ajustes', ['produto_id' => $this->ids['p'], 'armazem_id' => $arm, 'sentido' => 'E', 'quantidade' => 1, 'custo_unitario' => 115,
            'data' => $hoje, 'motivo' => 'Teste de bloqueio'], $s);
        $mov($a)->assertStatus(422)->assertJsonPath('codigo', 'ARMAZEM_EM_INVENTARIO');
        $mov($b)->assertCreated();
        // contagem cega
        $this->getJson("/api/logistica/inventarios/{$inv}", $s)->assertOk()->assertJsonMissingPath('dados.linhas.0.quantidade_sistema');

        $this->postJson("/api/logistica/inventarios/{$inv}/contagem", ['linhas' => [['produto_id' => $this->ids['p'], 'quantidade_contada' => 12]]], $s)->assertOk();
        $this->postJson("/api/logistica/inventarios/{$inv}/concluir-contagem", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'LINHAS_POR_CONTAR');   // a porca ficou por contar
        $this->postJson("/api/logistica/inventarios/{$inv}/concluir-contagem", ['por_contar_como_zero' => true], $s)->assertOk()->assertJsonPath('dados.estado', 'REVISAO');
        $this->postJson("/api/logistica/inventarios/{$inv}/revisao", ['linhas' => [['produto_id' => $this->ids['p'], 'justificacao' => 'Quebra no armazém']]], $s)->assertOk();

        // aprovação: por outra pessoa, com as contas configuradas; quebra de 3 × 115 = 345 no diário SQ
        $this->postJson("/api/logistica/inventarios/{$inv}/aprovar", [], $s)->assertStatus(403)->assertJsonPath('codigo', 'SEGREGACAO_FUNCOES');
        $this->postJson("/api/logistica/inventarios/{$inv}/aprovar", [], $this->s2)->assertStatus(422)->assertJsonPath('codigo', 'CONFIG_LOGISTICA_EM_FALTA');
        $this->putJson('/api/logistica/configuracao/contas', ['contas' => ['sobras_inventario' => '6851', 'quebras_inventario' => '7851', 'custo_mercadorias_vendidas' => '7111']], $s)->assertOk();
        $lan = $this->postJson("/api/logistica/inventarios/{$inv}/aprovar", [], $this->s2)->assertOk()->assertJsonPath('dados.estado', 'CONCLUIDA')->json('dados.numero_lan_contabilizacao');
        $this->assertSame('12.000', $this->stockEm($a));
        $linhas = fn (string $lan) => app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $lan)->orderBy('tipo_dc')->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
        $this->assertSame(['C 2611 345.00', 'D 7851 345.00'], $linhas($lan));
        $mov($a)->assertCreated();   // concluído: o armazém volta a movimentar

        // reabrir (estorno) e voltar a aprovar duas vezes: o stock volta sempre ao ponto certo
        $this->postJson("/api/logistica/inventarios/{$inv}/reabrir", ['motivo' => 'Recontagem pedida'], $this->s2)->assertOk()->assertJsonPath('dados.estado', 'REVISAO');
        $this->assertSame('16.000', $this->stockEm($a));   // 12 + 3 anulados + 1 do ajuste posterior
        $this->postJson("/api/logistica/inventarios/{$inv}/aprovar", [], $this->s2)->assertOk();
        $this->assertSame('13.000', $this->stockEm($a));
        $this->postJson("/api/logistica/inventarios/{$inv}/reabrir", ['motivo' => 'Segunda recontagem'], $this->s2)->assertOk();
        $this->assertSame('16.000', $this->stockEm($a));

        // anular: fica ANULADA com motivo e o armazém volta a movimentar
        $this->postJson("/api/logistica/inventarios/{$inv}/anular", ['motivo' => 'Inventário repetido'], $s)->assertOk()->assertJsonPath('dados.estado', 'ANULADA')
            ->assertJsonPath('dados.motivo_anulacao', 'Inventário repetido');
        $mov($a)->assertCreated();
    }

    #[Test]
    public function saida_a_custo_explicito_recalcula_o_custo_medio_e_ajuste_manual_sai_ao_custo_medio(): void
    {
        $a = $this->postJson('/api/logistica/armazens', ['nome' => 'Central'], $this->s)->assertCreated()->json('dados.id');
        $hoje = now()->toDateString();
        $aj = fn (string $sent, $q, $custo = null, ?string $data = null) => $this->postJson('/api/logistica/ajustes', ['produto_id' => $this->ids['p'], 'armazem_id' => $a,
            'sentido' => $sent, 'quantidade' => $q, 'custo_unitario' => $custo, 'data' => $data ?? $hoje, 'motivo' => 'Teste de custo'], $this->s);
        $aj('E', 10, 100)->assertCreated();
        $aj('E', 10, 200)->assertCreated()->assertJsonPath('dados.custo_medio_apos', '150.000000');

        // E-STK-1: estorno da 2.ª entrada (sai ao custo dela, 200): ficam 10 a 100 = 1 000 (antes ficavam a 150 = 1 500, ≠ conta 26)
        $r = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoStock::class)->saida($this->ids['p'], $a, '10', '200', $hoje, 'Estorno da recepção'));
        $this->assertSame('100.000000', $r['movimento']->custo_medio_apos);
        $this->assertSame('2000.00', (string) $r['movimento']->valor);

        // M4: no ajuste manual a saída ignora o custo indicado e sai ao custo médio (o legado valorizava ao custo do produto)
        $aj('S', 1, 999)->assertCreated()->assertJsonPath('dados.preco_unitario', '100.00')->assertJsonPath('dados.custo_medio_apos', '100.000000');

        // M4/M5: nenhum movimento de stock num exercício encerrado
        $ano = (int) now()->subYear()->format('Y');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoExercicios::class)->encerrar($this->empresa->id, $ano));
        $aj('E', 1, 100, "{$ano}-12-31")->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_ENCERRADO');
    }
}
