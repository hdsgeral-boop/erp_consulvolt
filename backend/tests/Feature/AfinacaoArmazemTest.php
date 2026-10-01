<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\GuiaSaida;
use App\Models\MovimentoInventario;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Afinação da Fase 5 (ADR-064) — Armazém: recálculo das valorizações de stock, guias e inventários paginados com filtro de estado. */
final class AfinacaoArmazemTest extends TestCase
{
    private Empresa $empresa;

    private int $produto;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            PlanoConta::create(['codigo' => '2611', 'descricao' => 'Mercadorias', 'tipo' => 'M']);
            $this->produto = Produto::create(['codigo' => 'P1', 'nome' => 'Parafuso', 'preco_unitario' => 200, 'movimenta_stock' => true, 'conta_inventario' => '2611'])->id;
        });
        $this->s = $this->sessao(['armazem_armazens_view', 'armazem_stock_view', 'armazem_movimentos_view', 'armazem_config', 'armazem_ajuste', 'armazem_transferencia',
            'armazem_recalcular', 'armazem_guias_view', 'inventario_sessoes_view', 'inventario_iniciar', 'inventario_manage']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    /**
     * E 10 × 100 (hoje) → transferência de 2 A→B → S 5 (hoje) → E 10 × 200 com data de anteontem (registada no fim).
     * Pela ordem cronológica o custo médio é 200 e depois 150 (não 166,67): a transferência e a saída passam a 150.
     *
     * @return array{0: int, 1: int, 2: int} armazém A, armazém B, id da saída de 5
     */
    private function cenario(): array
    {
        $a = $this->postJson('/api/logistica/armazens', ['nome' => 'Central'], $this->s)->assertCreated()->json('dados.id');
        $b = $this->postJson('/api/logistica/armazens', ['nome' => 'Loja'], $this->s)->assertCreated()->json('dados.id');
        $aj = fn (string $sent, $q, $custo, string $data) => $this->postJson('/api/logistica/ajustes', ['produto_id' => $this->produto, 'armazem_id' => $a, 'sentido' => $sent,
            'quantidade' => $q, 'custo_unitario' => $custo, 'data' => $data, 'motivo' => 'Teste de recálculo'], $this->s)->assertCreated();
        $aj('E', 10, 100, $this->hoje);
        $this->postJson('/api/logistica/transferencias', ['armazem_origem_id' => $a, 'armazem_destino_id' => $b, 'data' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto, 'quantidade' => 2]]], $this->s)->assertCreated();
        $saida = $aj('S', 5, null, $this->hoje)->assertJsonPath('dados.valor', '500.00')->json('dados.id');
        $aj('E', 10, 200, now()->subDays(2)->toDateString())->assertJsonPath('dados.custo_medio_apos', '166.666666');   // (5 × 100 + 10 × 200) / 15 (a transferência não muda o total)

        return [$a, $b, $saida];
    }

    private function movimento(int $id): MovimentoInventario
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => MovimentoInventario::query()->findOrFail($id));
    }

    private function custoMedio(): string
    {
        return (string) app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::query()->findOrFail($this->produto)->custo_medio);
    }

    #[Test]
    public function simula_e_aplica_o_recalculo_pela_ordem_cronologica(): void
    {
        [, , $saida] = $this->cenario();

        $this->postJson('/api/logistica/stock/recalcular-valorizacoes', [], $this->s)->assertOk()->assertJsonPath('dados.aplicado', false)
            ->assertJsonPath('dados.resumo.movimentos_alterados', 3)->assertJsonPath('dados.resumo.diferenca_valor', '450.00')
            ->assertJsonPath('dados.produtos.0.custo_medio_atual', '166.666666')->assertJsonPath('dados.produtos.0.custo_medio_recalculado', '150.000000');
        // a simulação não grava
        $this->assertSame('500.00', (string) $this->movimento($saida)->valor);
        $this->assertSame('166.666666', $this->custoMedio());

        $this->postJson('/api/logistica/stock/recalcular-valorizacoes', ['aplicar' => true, 'produto_id' => $this->produto], $this->s)->assertOk()
            ->assertJsonPath('dados.aplicado', true)->assertJsonPath('dados.resumo.movimentos_alterados', 3);
        $m = $this->movimento($saida);
        $this->assertSame(['750.00', '150.00', '150.000000'], [(string) $m->valor, (string) $m->preco_unitario, (string) $m->custo_medio_apos]);
        $this->assertSame('150.000000', $this->custoMedio());
        // idempotente
        $this->postJson('/api/logistica/stock/recalcular-valorizacoes', ['aplicar' => true], $this->s)->assertOk()
            ->assertJsonPath('dados.resumo.movimentos_alterados', 0)->assertJsonCount(0, 'dados.produtos');
    }

    #[Test]
    public function nao_altera_valores_contabilizados_e_respeita_inventario_e_permissoes(): void
    {
        [$a, , $saida] = $this->cenario();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($a, $saida) {
            $g = GuiaSaida::create(['numero_documento' => 'GS 1', 'data' => $this->hoje, 'tipo' => 'CONSUMO', 'armazem_id' => $a, 'estado' => 'CONCLUIDO', 'contabilizado' => true]);
            MovimentoInventario::query()->whereKey($saida)->update(['documento_tipo' => 'GUIA_SAIDA', 'documento_id' => $g->id]);
        });

        $this->postJson('/api/logistica/stock/recalcular-valorizacoes', ['aplicar' => true], $this->s)->assertOk()
            ->assertJsonPath('dados.resumo.movimentos_alterados', 2)->assertJsonPath('dados.resumo.divergencias_contabilizadas', 1)
            ->assertJsonPath('dados.resumo.diferenca_valor', '200.00');
        $this->assertSame('500.00', (string) $this->movimento($saida)->valor);   // contabilizado: fica como está
        $this->assertSame('150.000000', $this->custoMedio());

        // inventário em curso: simula, mas não aplica
        $this->postJson('/api/logistica/inventarios', ['armazem_id' => $a, 'data' => $this->hoje], $this->s)->assertCreated();
        $this->postJson('/api/logistica/stock/recalcular-valorizacoes', [], $this->s)->assertOk();
        $this->postJson('/api/logistica/stock/recalcular-valorizacoes', ['aplicar' => true], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ARMAZEM_EM_INVENTARIO');

        $semTarefa = $this->sessao(['armazem_movimentos_view', 'armazem_stock_view']);
        $this->postJson('/api/logistica/stock/recalcular-valorizacoes', [], $semTarefa)->assertStatus(403);
    }

    #[Test]
    public function guias_e_inventarios_paginados_com_filtro_de_estado(): void
    {
        [$a, $b] = $this->cenario();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($a) {
            GuiaSaida::create(['numero_documento' => 'GS 1', 'data' => $this->hoje, 'tipo' => 'CONSUMO', 'armazem_id' => $a, 'estado' => 'CONCLUIDO', 'contabilizado' => false]);
            GuiaSaida::create(['numero_documento' => 'GS 2', 'data' => $this->hoje, 'tipo' => 'CONSUMO', 'armazem_id' => $a, 'estado' => 'ANULADA', 'contabilizado' => false]);
        });
        $this->getJson('/api/logistica/guias-saida?por_pagina=1', $this->s)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('metadados.paginacao.total', 2);
        $this->getJson('/api/logistica/guias-saida?estado=ANULADA', $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.numero_documento', 'GS 2');
        $this->getJson('/api/logistica/guias-saida?pesquisa=gs 1', $this->s)->assertJsonCount(1, 'dados');

        $i1 = $this->postJson('/api/logistica/inventarios', ['armazem_id' => $a, 'data' => $this->hoje], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/logistica/inventarios/{$i1}/anular", ['motivo' => 'Aberto por engano'], $this->s)->assertOk();
        $this->postJson('/api/logistica/inventarios', ['armazem_id' => $b, 'data' => $this->hoje], $this->s)->assertCreated();
        $this->getJson('/api/logistica/inventarios?por_pagina=1', $this->s)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('metadados.paginacao.total', 2);
        $this->getJson('/api/logistica/inventarios?estado=EM_CONTAGEM', $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.armazem_id', $b);
    }
}
