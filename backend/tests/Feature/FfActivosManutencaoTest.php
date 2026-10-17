<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\CentroCusto;
use App\Models\Empresa;
use App\Models\ItemCompra;
use App\Models\MovimentoInventario;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Services\Ativos\ServicoPlanosManutencao;
use App\Services\Logistica\ServicoStock;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase F (Versão Actual) — manutenção planeada (js/activos_manutencao.js): planos por tempo e por contador, situação,
 * geração de ordens sem duplicar, necessidades repartidas entre armazém e compras, requisição com saída de stock ao
 * custo médio, conclusão com histórico e avanço do plano, cancelamentos, permissões e isolamento. Dados fictícios.
 */
final class FfActivosManutencaoTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417100021']);
        $this->naEmpresa(function () {
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'MAN', 'descricao' => 'Manutenção'])->id;
            $this->ids['oleo'] = Produto::create(['codigo' => 'M1', 'nome' => 'Óleo de motor', 'preco_unitario' => 0, 'movimenta_stock' => true])->id;
            $this->ids['filtro'] = Produto::create(['codigo' => 'M2', 'nome' => 'Filtro de ar', 'preco_unitario' => 0, 'movimenta_stock' => true])->id;
            $this->ids['servico'] = Produto::create(['codigo' => 'S1', 'nome' => 'Revisão por oficina', 'preco_unitario' => 0, 'movimenta_stock' => false])->id;
            $this->ids['arm'] = Armazem::create(['nome' => 'Armazém fictício'])->id;
            // 10 litros de óleo a 500 Kz; filtro sem stock
            app(ServicoStock::class)->entrada($this->ids['oleo'], $this->ids['arm'], '10', '500', now()->toDateString(), 'Stock inicial de teste');
        });
        $this->h = $this->sessao(['activos_view', 'activos_gerir', 'activos_cat_gerir', 'activos_manutencao_view', 'activos_manut_gerir', 'activos_manut_requisitar']);
        $this->ids['cat'] = $this->postJson('/api/ativos/categorias', ['nome' => 'Viaturas', 'taxa_anual' => 25, 'vida_util_padrao' => 48], $this->h)->assertCreated()->json('dados.id');
        $this->ids['gerador'] = $this->postJson('/api/ativos/bens', ['descricao' => 'Gerador fictício', 'categoria_ativo_id' => $this->ids['cat'], 'valor_aquisicao' => 100000,
            'centro_custo_id' => $this->ids['cc']], $this->h)->assertCreated()->json('dados.id');
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function plano(array $d = []): int
    {
        return $this->postJson('/api/ativos/manutencao/planos', $d + [
            'nome' => 'Revisão trimestral do gerador', 'ativo_imobilizado_id' => $this->ids['gerador'], 'tipo' => 'PREVENTIVA',
            'periodicidade' => ['modo' => 'TEMPO', 'intervalo' => 3, 'unidade' => 'MESES'], 'proxima_data' => now()->addDays(3)->toDateString(),
            'antecedencia_dias' => 7, 'tarefas' => ['Trocar óleo', 'Limpar filtro', ''],
            'itens' => [['tipo' => 'MATERIAL', 'produto_id' => $this->ids['oleo'], 'quantidade' => 6], ['tipo' => 'MATERIAL', 'produto_id' => $this->ids['filtro'], 'quantidade' => 1],
                ['tipo' => 'SERVICO', 'produto_id' => $this->ids['servico'], 'quantidade' => 1]],
        ], $this->h)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function situacao_e_periodicidade_como_no_legado(): void
    {
        $this->assertSame('2026-05-28', ServicoPlanosManutencao::somar('2026-02-28', 3, 'MESES'));
        $this->assertSame('2026-02-28', ServicoPlanosManutencao::somar('2026-01-31', 1, 'MESES'));   // sem transbordar para Março
        $this->assertSame('2026-03-14', ServicoPlanosManutencao::somar('2026-02-28', 2, 'SEMANAS'));
        $id = $this->plano();
        $planos = $this->getJson('/api/ativos/manutencao/planos', $this->h)->assertOk();
        $planos->assertJsonPath('dados.0.codigo', 'PM-'.now()->year.'/0001')->assertJsonPath('dados.0.situacao.chave', 'A_VENCER')
            ->assertJsonPath('dados.0.tarefas', ['Trocar óleo', 'Limpar filtro'])->assertJsonPath('dados.0.execucoes', 0);

        // contador: falta ≤ max(1, 10 % do intervalo) = a vencer
        $this->plano(['nome' => 'Mudança de óleo a 250 h', 'periodicidade' => ['modo' => 'CONTADOR', 'intervalo' => 250, 'unidade' => 'HORAS'],
            'proximo_contador' => 1000, 'contador_atual' => 980, 'proxima_data' => null]);
        $sit = collect($this->getJson('/api/ativos/manutencao/planos', $this->h)->json('dados'))->firstWhere('nome', 'Mudança de óleo a 250 h')['situacao'];
        $this->assertSame('A_VENCER', $sit['chave']);

        // validações do legado + unidade coerente com o modo
        $this->postJson('/api/ativos/manutencao/planos', ['nome' => 'X', 'tipo' => 'PREVENTIVA', 'periodicidade' => ['modo' => 'TEMPO', 'intervalo' => 1, 'unidade' => 'MESES'],
            'proxima_data' => '2026-12-01'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'DADOS_INVALIDOS');
        $this->postJson('/api/ativos/manutencao/planos', ['nome' => 'X', 'ativo_imobilizado_id' => $this->ids['gerador'], 'tipo' => 'PREVENTIVA',
            'periodicidade' => ['modo' => 'TEMPO', 'intervalo' => 1, 'unidade' => 'KM'], 'proxima_data' => '2026-12-01'], $this->h)->assertStatus(422);
        $this->postJson('/api/ativos/manutencao/planos', ['nome' => 'X', 'ativo_imobilizado_id' => $this->ids['gerador'], 'tipo' => 'PREVENTIVA',
            'periodicidade' => ['modo' => 'CONTADOR', 'intervalo' => 100, 'unidade' => 'KM']], $this->h)->assertStatus(422);

        // desactivar / reactivar; eliminar sem ordens
        $this->postJson("/api/ativos/manutencao/planos/{$id}/alternar", [], $this->h)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->assertSame('INACTIVO', collect($this->getJson('/api/ativos/manutencao/planos', $this->h)->json('dados'))->firstWhere('id', $id)['situacao']['chave']);
        $this->deleteJson("/api/ativos/manutencao/planos/{$id}", [], $this->h)->assertOk();
    }

    #[Test]
    public function fluxo_completo_plano_ordem_requisicao_compra_entrega_conclusao(): void
    {
        $plano = $this->plano();
        // gerar: abre uma ordem; repetir não duplica (ordem aberta do mesmo plano para o mesmo activo)
        $this->postJson('/api/ativos/manutencao/ordens/gerar', [], $this->h)->assertOk()->assertJsonCount(1, 'dados.criadas');
        $this->postJson('/api/ativos/manutencao/ordens/gerar', [], $this->h)->assertOk()->assertJsonCount(0, 'dados.criadas')->assertJsonCount(1, 'dados.saltadas');
        $this->postJson("/api/ativos/manutencao/planos/{$plano}/ordens", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'NADA_A_GERAR');
        $ordem = $this->getJson('/api/ativos/manutencao/ordens', $this->h)->assertOk()->assertJsonPath('dados.0.numero', 'OM '.now()->year.'/0001')
            ->assertJsonPath('dados.0.estado', 'PLANEADA')->assertJsonPath('dados.0.prioridade', 'NORMAL')->json('dados.0.id');
        $det = $this->getJson("/api/ativos/manutencao/ordens/{$ordem}", $this->h)->assertOk()->assertJsonCount(3, 'dados.itens')->assertJsonCount(2, 'dados.tarefas');
        $this->assertSame('Revisão trimestral do gerador', $det->json('dados.descricao'));

        // o plano não se elimina depois de gerar ordens
        $this->deleteJson("/api/ativos/manutencao/planos/{$plano}", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_COM_ORDENS');

        // pré-visualização: óleo 6 do stock (10), filtro sem stock → compra, serviço → compra
        $prev = collect($this->getJson("/api/ativos/manutencao/ordens/{$ordem}/plano-requisicao?armazem_id={$this->ids['arm']}", $this->h)->assertOk()->json('dados'))->keyBy('produto_id');
        $this->assertSame(['6.000', '0.000'], [$prev[$this->ids['oleo']]['para_armazem'], $prev[$this->ids['oleo']]['para_compra']]);
        $this->assertSame(['0.000', '1.000'], [$prev[$this->ids['filtro']]['para_armazem'], $prev[$this->ids['filtro']]['para_compra']]);
        $this->assertSame('1.000', $prev[$this->ids['servico']]['para_compra']);

        // requisitar: uma requisição (1 linha) e um pedido de compra (2 linhas) que herda o CC do activo; ordem aguarda material
        $res = $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/requisitar", ['armazem_id' => $this->ids['arm'], 'requerente' => 'Técnico fictício'], $this->h)
            ->assertCreated()->assertJsonPath('dados.armazem', 1)->assertJsonPath('dados.compra', 2)->json('dados');
        $this->assertSame('RQ '.now()->year.'/0001', $res['requisicao_numero']);
        $pedido = $this->naEmpresa(fn () => PedidoCompra::query()->findOrFail($res['pedido_compra_id']));
        $this->assertSame('PENDENTE', $pedido->estado);
        $this->assertSame($this->ids['cc'], (int) $pedido->centro_custo_id);
        $this->assertStringContainsString('OM '.now()->year.'/0001', (string) $pedido->descricao);
        $this->assertSame(2, $this->naEmpresa(fn () => ItemCompra::query()->where('pedido_compra_id', $pedido->id)->count()));
        $this->getJson("/api/ativos/manutencao/ordens/{$ordem}", $this->h)->assertJsonPath('dados.estado', 'AGUARDA_MATERIAL')->assertJsonPath('dados.por_pedir', 0);
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/requisitar", ['armazem_id' => $this->ids['arm']], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'SEM_NECESSIDADES');

        // entrega: não mais do que falta; parcial e depois o resto; saída ao custo médio (500)
        $req = $res['requisicao_id'];
        $linha = $this->getJson("/api/ativos/manutencao/requisicoes/{$req}", $this->h)->assertOk()->assertJsonPath('dados.linhas.0.stock', '10.000')->json('dados.linhas.0.id');
        $this->postJson("/api/ativos/manutencao/requisicoes/{$req}/entregar", ['entregas' => [$linha => 7]], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'QUANTIDADE_EXCEDE');
        $this->postJson("/api/ativos/manutencao/requisicoes/{$req}/entregar", ['entregas' => [$linha => 2]], $this->h)->assertOk()
            ->assertJsonPath('dados.total', '1000.00')->assertJsonPath('dados.completa', false);
        $this->postJson("/api/ativos/manutencao/requisicoes/{$req}/cancelar", ['motivo' => 'x'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'REQUISICAO_COM_ENTREGAS');
        $this->postJson("/api/ativos/manutencao/requisicoes/{$req}/entregar", [], $this->h)->assertOk()->assertJsonPath('dados.total', '2000.00')->assertJsonPath('dados.completa', true);
        $this->assertSame('4.000', $this->naEmpresa(fn () => (string) StockArmazem::query()->where('armazem_id', $this->ids['arm'])->where('produto_id', $this->ids['oleo'])->value('quantidade_stock')));
        $this->assertSame(2, $this->naEmpresa(fn () => MovimentoInventario::query()->where('documento_tipo', 'REQUISICAO_MANUTENCAO')->where('documento_id', $req)->count()));
        $this->getJson('/api/ativos/manutencao/requisicoes', $this->h)->assertJsonPath('dados.0.estado', 'SATISFEITA');

        // requisição completa: a ordem passa a EM_CURSO; há compras pendentes, por isso a conclusão pede confirmação
        $det = $this->getJson("/api/ativos/manutencao/ordens/{$ordem}", $this->h)->assertJsonPath('dados.custo_materiais', '3000.00')->assertJsonPath('dados.estado', 'EM_CURSO');
        $itens = collect($det->json('dados.itens'))->keyBy('produto_id');
        $this->assertSame('RECEBIDO', $itens[$this->ids['oleo']]['estado']);
        $this->assertSame('EM_COMPRA', $itens[$this->ids['filtro']]['estado']);
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/iniciar", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'ORDEM_EM_CURSO');
        $this->putJson("/api/ativos/manutencao/ordens/{$ordem}/tarefas", ['feitas' => [true, false]], $this->h)->assertOk()->assertJsonPath('dados.tarefas.0.feito', true);
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/concluir", ['resolucao' => ''], $this->h)->assertStatus(422);
        // r3_activos: com uma tarefa obrigatória por fazer a ordem não se conclui, nem forçando
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/concluir", ['resolucao' => 'Óleo trocado', 'forcar' => true], $this->h)->assertStatus(422)
            ->assertJsonPath('codigo', 'TAREFAS_PENDENTES');
        $this->putJson("/api/ativos/manutencao/ordens/{$ordem}/tarefas", ['feitas' => [true, true]], $this->h)->assertOk();
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/concluir", ['resolucao' => 'Óleo trocado'], $this->h)->assertStatus(409)->assertJsonPath('codigo', 'NECESSIDADES_PENDENTES');
        $fim = now()->toDateString();
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/concluir", ['resolucao' => 'Óleo trocado e filtro limpo', 'data_fim' => $fim, 'horas' => 2.5,
            'custo_mao_obra' => 1500, 'custo_servicos' => 4000, 'forcar' => true], $this->h)->assertOk()
            ->assertJsonPath('dados.estado', 'CONCLUIDA')->assertJsonPath('dados.custo_total', '8500.00');

        // histórico do activo e plano avançado a partir da execução real
        $this->getJson("/api/ativos/bens/{$this->ids['gerador']}", $this->h)->assertJsonPath('dados.manutencoes.0.numero_ordem', 'OM '.now()->year.'/0001')
            ->assertJsonPath('dados.manutencoes.0.custo', '8500.00')->assertJsonPath('dados.manutencoes.0.estado', 'CONCLUIDA');
        $p = collect($this->getJson('/api/ativos/manutencao/planos', $this->h)->json('dados'))->firstWhere('id', $plano);
        $this->assertSame(1, $p['execucoes']);
        $this->assertSame(ServicoPlanosManutencao::somar($fim, 3, 'MESES'), substr($p['proxima_data'], 0, 10));
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/cancelar", ['motivo' => 'x'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'ORDEM_FECHADA');
    }

    #[Test]
    public function ordem_avulsa_contador_cancelamentos_e_regras(): void
    {
        $plano = $this->plano(['nome' => 'Revisão a 500 km', 'periodicidade' => ['modo' => 'CONTADOR', 'intervalo' => 500, 'unidade' => 'KM'], 'proximo_contador' => 1000,
            'contador_atual' => 1200, 'proxima_data' => null, 'itens' => []]);
        // «Ordem» do plano: abre já; vencido por contador
        $this->postJson("/api/ativos/manutencao/planos/{$plano}/ordens", [], $this->h)->assertCreated()->assertJsonCount(1, 'dados.criadas');
        $ordem = $this->getJson('/api/ativos/manutencao/ordens', $this->h)->json('dados.0.id');
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/concluir", ['resolucao' => 'Revisão feita', 'leitura_contador' => 1250, 'tarefas' => [true, true]], $this->h)->assertOk();
        $p = collect($this->getJson('/api/ativos/manutencao/planos', $this->h)->json('dados'))->firstWhere('id', $plano);
        $this->assertSame(['1250.00', '1750.00'], [$p['contador_atual'], $p['proximo_contador']]);

        // ordem avulsa: necessidades acrescentadas e retiradas; requisição cancelada devolve-as a «por pedir»
        $o = $this->postJson('/api/ativos/manutencao/ordens', ['ativo_imobilizado_id' => $this->ids['gerador'], 'tipo' => 'CORRECTIVA', 'prioridade' => 'URGENTE',
            'data_prevista' => now()->toDateString(), 'descricao' => 'Avaria no arranque'], $this->h)->assertCreated()->json('dados.id');
        $this->postJson('/api/ativos/manutencao/ordens', ['ativo_imobilizado_id' => $this->ids['gerador'], 'tipo' => 'XPTO', 'data_prevista' => now()->toDateString(),
            'descricao' => 'x'], $this->h)->assertStatus(422);
        $item = $this->postJson("/api/ativos/manutencao/ordens/{$o}/itens", ['tipo' => 'MATERIAL', 'produto_id' => $this->ids['oleo'], 'quantidade' => 1], $this->h)->assertCreated()->json('dados.id');
        $outro = $this->postJson("/api/ativos/manutencao/ordens/{$o}/itens", ['tipo' => 'MATERIAL', 'produto_id' => $this->ids['filtro'], 'quantidade' => 1], $this->h)->assertCreated()->json('dados.id');
        $this->deleteJson("/api/ativos/manutencao/itens/{$outro}", [], $this->h)->assertOk();
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/requisitar", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'ARMAZEM_EM_FALTA');
        $req = $this->postJson("/api/ativos/manutencao/ordens/{$o}/requisitar", ['armazem_id' => $this->ids['arm']], $this->h)->assertCreated()
            ->assertJsonPath('dados.pedido_compra_id', null)->json('dados.requisicao_id');
        $this->deleteJson("/api/ativos/manutencao/itens/{$item}", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'ITEM_JA_PEDIDO');
        $this->postJson("/api/ativos/manutencao/requisicoes/{$req}/cancelar", ['motivo' => 'Peça trazida pelo fornecedor'], $this->h)->assertOk()->assertJsonPath('dados.estado', 'CANCELADA');
        $this->getJson("/api/ativos/manutencao/ordens/{$o}", $this->h)->assertJsonPath('dados.itens.0.estado', 'POR_PEDIR');
        $this->postJson("/api/ativos/manutencao/requisicoes/{$req}/entregar", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'REQUISICAO_FECHADA');

        // cancelar a ordem exige motivo; cancelada não se conclui
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/cancelar", [], $this->h)->assertStatus(422);
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/cancelar", ['motivo' => 'Avaria resolvida pelo operador'], $this->h)->assertOk()
            ->assertJsonPath('dados.ordem.estado', 'CANCELADA')->assertJsonPath('dados.pedidos_emitidos', 0);
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/concluir", ['resolucao' => 'x'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'ORDEM_FECHADA');
        $this->getJson('/api/ativos/manutencao/resumo', $this->h)->assertOk()->assertJsonPath('dados.ordens.CANCELADA', 1)->assertJsonPath('dados.ordens.CONCLUIDA', 1);
    }

    #[Test]
    public function permissoes_e_isolamento(): void
    {
        $plano = $this->plano();
        $ver = $this->sessao(['activos_manutencao_view']);
        $this->getJson('/api/ativos/manutencao/planos', $ver)->assertOk();
        $this->postJson('/api/ativos/manutencao/ordens/gerar', [], $ver)->assertForbidden();
        $this->postJson("/api/ativos/manutencao/planos/{$plano}/ordens", [], $this->h)->assertCreated();
        $ordem = $this->getJson('/api/ativos/manutencao/ordens', $this->h)->json('dados.0.id');
        // gerir sem requisitar: não emite requisições nem pedidos de compra
        $gestor = $this->sessao(['activos_manut_gerir']);
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/requisitar", ['armazem_id' => $this->ids['arm']], $gestor)->assertForbidden();
        $this->getJson("/api/ativos/manutencao/ordens/{$ordem}/plano-requisicao", $gestor)->assertForbidden();
        $this->getJson('/api/ativos/manutencao/planos', $this->sessao(['activos_view']))->assertForbidden();

        $outra = $this->criarEmpresa(['nif' => '5417100022']);
        $h2 = $this->sessao(['activos_manutencao_view', 'activos_manut_gerir', 'activos_manut_requisitar'], $outra);
        $this->getJson("/api/ativos/manutencao/ordens/{$ordem}", $h2)->assertNotFound();
        $this->putJson("/api/ativos/manutencao/planos/{$plano}", ['nome' => 'X'], $h2)->assertNotFound();
        $this->postJson("/api/ativos/manutencao/ordens/{$ordem}/requisitar", [], $h2)->assertNotFound();
        $this->getJson('/api/ativos/manutencao/ordens?filtro=TODAS', $h2)->assertOk()->assertJsonCount(0, 'dados');
    }
}
