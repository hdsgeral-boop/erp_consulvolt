<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\ItemCompra;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Módulo Compras (parte 1): pedido → deliberação → proposta → adjudicação → encomenda → recepção → factura → contabilização. */
final class ComprasTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $fornecedor;

    private Produto $artigo;

    private Produto $servico;

    private Armazem $armazem;

    /** comprador (cria, avalia, adjudica, regista), aprovador (aprova nível 1), armazém (valida), contabilista */
    private array $comprador;

    private array $aprovador;

    private array $armazenista;

    private array $contabilista;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3211' => 'Fornecedores', '3281' => 'Compras em trânsito', '211' => 'Compras de mercadorias', '261' => 'Mercadorias',
                '3451' => 'IVA dedutível', '752' => 'Fornecimentos e serviços', '6881' => 'Diferenças de câmbio desfavoráveis', '7881' => 'Diferenças de câmbio favoráveis'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->fornecedor = Terceiro::create(['nome' => 'Fornecedor & Cia', 'nif' => '5000000099', 'tipo' => Terceiro::FORNECEDOR,
                'codigo_conta' => '3211', 'conta_compra_transitoria' => '3281']);
            $this->artigo = Produto::create(['codigo' => 'A1', 'nome' => 'Cimento', 'preco_unitario' => 150, 'taxa_imposto' => 14, 'movimenta_stock' => true,
                'conta_compra' => '211', 'conta_inventario' => '261', 'conta_iva_dedutivel' => '3451']);
            $this->servico = Produto::create(['codigo' => 'S1', 'nome' => 'Transporte', 'preco_unitario' => 0, 'taxa_imposto' => 14, 'movimenta_stock' => false,
                'conta_custo' => '752', 'conta_iva_dedutivel' => '3451']);
            $this->armazem = Armazem::create(['nome' => 'Armazém central']);
        });
        $this->comprador = $this->sessao(['compras_pedidos_view', 'compras_ped_criar', 'compras_ped_aprovar', 'compras_prospeccao_view', 'compras_new_proposal',
            'compras_evaluate', 'compras_adjudicate', 'compras_encomendas_view', 'compras_enc_eliminar', 'compras_rececoes_view', 'compras_rec_registar',
            'compras_faturacao_view', 'compras_fact_registar', 'compras_fact_eliminar', 'compras_deliberacao_config']);
        $this->aprovador = $this->sessao(['compras_pedidos_view', 'compras_ped_aprovar']);
        $this->armazenista = $this->sessao(['armazem_rececoes_view', 'armazem_validar', 'armazem_rec_anular']);
        $this->contabilista = $this->sessao(['compras_faturacao_view', 'compras_fact_contabilizar', 'compras_descontab']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function pedido(float $qtd = 10, float $preco = 100, ?int $produto = null): TestResponse
    {
        return $this->postJson('/api/compras/pedidos', ['nome_requerente' => 'Obra Talatona', 'data' => $this->hoje,
            'linhas' => [['produto_id' => $produto ?? $this->artigo->id, 'quantidade' => $qtd, 'preco_unitario' => $preco]]], $this->comprador);
    }

    private function aprovar(int $pedido, ?array $quem = null): TestResponse
    {
        return $this->postJson("/api/compras/pedidos/{$pedido}/decidir", ['decisao' => 'APROVAR'], $quem ?? $this->aprovador);
    }

    private function proposta(int $pedido, float $preco, array $extra = []): TestResponse
    {
        $linha = $this->getJson("/api/compras/pedidos/{$pedido}", $this->comprador)->json('dados.linhas.0.id');

        return $this->postJson('/api/compras/propostas', $extra + ['pedido_compra_id' => $pedido, 'fornecedor_id' => $this->fornecedor->id, 'referencia' => 'PROP-'.$preco,
            'data' => $this->hoje, 'linhas' => [['item_pedido_id' => $linha, 'preco_unitario' => $preco, 'taxa_imposto' => 14]]], $this->comprador);
    }

    /** Pedido aprovado + proposta adjudicada → id da encomenda */
    private function encomenda(float $qtd = 10, float $preco = 90, array $extraProposta = []): int
    {
        $p = $this->pedido($qtd)->json('dados.id');
        $this->aprovar($p)->assertOk();
        $c = $this->proposta($p, $preco, $extraProposta)->assertCreated()->json('dados.id');
        $this->postJson("/api/compras/propostas/{$c}/propor", [], $this->comprador)->assertOk();

        return $this->postJson("/api/compras/propostas/{$c}/adjudicar", ['data' => $this->hoje], $this->comprador)->assertCreated()->json('dados.id');
    }

    private function receber(int $enc, float $qtd, string $guia = 'GR-1'): TestResponse
    {
        $linha = $this->getJson("/api/compras/encomendas/{$enc}", $this->comprador)->json('dados.linhas.0.id');

        return $this->postJson("/api/compras/encomendas/{$enc}/rececoes", ['numero_entrega' => $guia, 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linha, 'quantidade' => $qtd]]], $this->comprador);
    }

    private function faturar(int $enc, float $qtd, string $numero, array $extra = []): TestResponse
    {
        $linha = $this->getJson("/api/compras/encomendas/{$enc}", $this->comprador)->json('dados.linhas.0.id');

        return $this->postJson("/api/compras/encomendas/{$enc}/faturas", $extra + ['numero_fatura' => $numero, 'data' => $this->hoje,
            'linhas' => [['item_encomenda_id' => $linha, 'quantidade' => $qtd]]], $this->comprador);
    }

    private function lancamento(?string $numeroLan): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)->orderBy('id')->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
    }

    private function stock(): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => [
            (string) StockArmazem::query()->where('armazem_id', $this->armazem->id)->where('produto_id', $this->artigo->id)->value('quantidade_stock'),
            (string) Produto::query()->findOrFail($this->artigo->id)->custo_medio,
        ]);
    }

    #[Test]
    public function pedido_deliberacao_propostas_e_adjudicacao_unica(): void
    {
        $ano = substr($this->hoje, 0, 4);
        $p = $this->pedido()->assertCreated()->assertJsonPath('dados.numero_pedido', "PC A{$ano}/1")->assertJsonPath('dados.estado', 'PENDENTE')
            ->assertJsonPath('dados.deliberacao.etapas.0.tarefa', 'compras_ped_aprovar')->json('dados.id');
        $this->proposta($p, 90)->assertStatus(422)->assertJsonPath('codigo', 'PEDIDO_NAO_APROVADO');
        $this->aprovar($p, $this->comprador)->assertStatus(403)->assertJsonPath('codigo', 'AUTO_APROVACAO');   // ninguém aprova os próprios pedidos
        $this->postJson("/api/compras/pedidos/{$p}/decidir", ['decisao' => 'RECUSAR', 'nota' => 'x'], $this->aprovador)->assertStatus(422)->assertJsonPath('codigo', 'RECUSA_SEM_NOTA');
        $this->aprovar($p)->assertOk()->assertJsonPath('dados.estado', 'APROVADO');

        $c1 = $this->proposta($p, 90)->assertCreated()->assertJsonPath('dados.montante_total', '900.00')->assertJsonPath('dados.total_imposto', '126.00')
            ->assertJsonPath('dados.numero_proposta', "PP A{$ano}/1")->json('dados.id');
        $c2 = $this->proposta($p, 95)->json('dados.id');
        $this->getJson("/api/compras/pedidos/{$p}/comparacao", $this->comprador)->assertOk()->assertJsonPath('dados.propostas.0.id', $c1)
            ->assertJsonPath('dados.propostas.0.pontuacao.preco', 70.0);

        $this->postJson("/api/compras/propostas/{$c1}/adjudicar", [], $this->comprador)->assertStatus(422)->assertJsonPath('codigo', 'PROPOSTA_ESTADO_INVALIDO');
        $this->postJson("/api/compras/propostas/{$c1}/propor", [], $this->comprador)->assertOk()->assertJsonPath('dados.estado', 'PROPOSTA_ADJUDICACAO');
        $e = $this->postJson("/api/compras/propostas/{$c1}/adjudicar", [], $this->comprador)->assertCreated()
            ->assertJsonPath('dados.numero_encomenda', "EC A{$ano}/1")->assertJsonPath('dados.montante_total', '900.00')->assertJsonPath('dados.total_com_imposto', '1026.00')
            ->assertJsonPath('dados.linhas.0.quantidade', '10.000')->json('dados.id');
        $this->getJson("/api/compras/propostas/{$c2}", $this->comprador)->assertJsonPath('dados.estado', 'RECUSADA');
        $this->getJson("/api/compras/pedidos/{$p}", $this->comprador)->assertJsonPath('dados.estado', 'ADJUDICADO');
        // segunda adjudicação impossível (o legado permitia)
        $this->postJson("/api/compras/propostas/{$c2}/propor", [], $this->comprador)->assertStatus(422)->assertJsonPath('codigo', 'PEDIDO_NAO_ADJUDICAVEL');

        // anular a encomenda devolve a proposta e o pedido ao estado adjudicável
        $this->postJson("/api/compras/encomendas/{$e}/anular", ['motivo' => 'Fornecedor desistiu'], $this->comprador)->assertOk()->assertJsonPath('dados.estado', 'ANULADA');
        $this->getJson("/api/compras/pedidos/{$p}", $this->comprador)->assertJsonPath('dados.estado', 'APROVADO');
    }

    #[Test]
    public function revisao_da_deliberacao_quando_a_proposta_exige_mais_niveis(): void
    {
        $this->putJson('/api/compras/deliberacao/escaloes', ['niveis' => [['nome' => 'Chefe', 'limite' => 1000], ['nome' => 'Direcção', 'limite' => null]]], $this->comprador)->assertOk();
        $p = $this->pedido(5, 100)->json('dados.id');   // 500 → só o 1.º nível
        $this->aprovar($p)->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $c = $this->proposta($p, 300)->json('dados.id');   // 1500 → exige a Direcção
        $this->postJson("/api/compras/propostas/{$c}/propor", [], $this->comprador)->assertOk();
        $this->postJson("/api/compras/propostas/{$c}/adjudicar", [], $this->comprador)->assertStatus(409)->assertJsonPath('codigo', 'REVISAO_DELIBERACAO');
        $this->getJson("/api/compras/pedidos/{$p}", $this->comprador)->assertJsonPath('dados.estado', 'PENDENTE')
            ->assertJsonPath('dados.deliberacao.etapas.1.tarefa', 'compras_ped_aprovar_n2')->assertJsonPath('dados.deliberacao.etapas.1.revisao', true);
        $this->aprovar($p)->assertStatus(403)->assertJsonPath('codigo', 'SEM_PERMISSAO_ETAPA');
        $this->aprovar($p, $this->sessao(['compras_ped_aprovar_n2']))->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $this->postJson("/api/compras/propostas/{$c}/adjudicar", [], $this->comprador)->assertCreated();
    }

    #[Test]
    public function recepcao_validacao_factura_e_contabilizacao_em_kz(): void
    {
        $e = $this->encomenda();
        $this->receber($e, 11)->assertStatus(422)->assertJsonPath('codigo', 'QUANTIDADE_SUPERIOR_PENDENTE');
        $r = $this->receber($e, 4)->assertCreated()->json('dados.id');
        $this->getJson("/api/compras/encomendas/{$e}", $this->comprador)->assertJsonPath('dados.estado', 'PARCIAL')->assertJsonPath('dados.linhas.0.quantidade_recebida', '4.000');

        $this->postJson("/api/compras/rececoes/{$r}/validar", ['armazem_id' => $this->armazem->id], $this->comprador)->assertForbidden();   // segregação
        $v = $this->postJson("/api/compras/rececoes/{$r}/validar", ['armazem_id' => $this->armazem->id], $this->armazenista)->assertOk()
            ->assertJsonPath('dados.estado', 'VALIDADO')->assertJsonPath('dados.valor_total_kz', '360.00');
        $this->assertSame(['D 211 360.00', 'C 3281 360.00', 'D 261 360.00', 'C 211 360.00'], $this->lancamento($v->json('dados.numero_lan_contabilizacao')));
        $this->assertSame(['4.000', '90.000000'], $this->stock());
        $this->postJson("/api/compras/rececoes/{$r}/validar", ['armazem_id' => $this->armazem->id], $this->armazenista)->assertStatus(422);   // não duplica

        $this->faturar($e, 11, 'FT 1')->assertStatus(422)->assertJsonPath('codigo', 'QUANTIDADE_SUPERIOR_PENDENTE');
        $f = $this->faturar($e, 4, 'FT 1')->assertCreated()->assertJsonPath('dados.montante_total', '410.40')->assertJsonPath('dados.linhas.0.valor_transitoria_kz', '360.00')->json('dados.id');
        $this->faturar($e, 1, 'ft 1 ')->assertStatus(422)->assertJsonPath('codigo', 'FATURA_DUPLICADA');
        $c = $this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $this->contabilista)->assertOk();
        $this->assertSame(['D 3281 360.00', 'D 3451 50.40', 'C 3211 410.40'], $this->lancamento($c->json('dados.numero_lan_contabilizacao')));

        // reverter pela ordem inversa, sempre com estorno
        $this->postJson("/api/compras/rececoes/{$r}/reverter-validacao", ['motivo' => 'Guia errada'], $this->armazenista)->assertStatus(422)->assertJsonPath('codigo', 'ENCOMENDA_COM_FATURAS');
        $this->postJson("/api/compras/faturas/{$f}/anular", ['motivo' => 'Factura errada'], $this->comprador)->assertStatus(422)->assertJsonPath('codigo', 'FATURA_CONTABILIZADA');
        $this->postJson("/api/compras/faturas/{$f}/descontabilizar", ['motivo' => 'Factura errada'], $this->contabilista)->assertOk()->assertJsonPath('dados.contabilizado', false);
        $this->postJson("/api/compras/faturas/{$f}/anular", ['motivo' => 'Factura errada'], $this->comprador)->assertOk()->assertJsonPath('dados.estado', 'ANULADA');
        $this->getJson("/api/compras/encomendas/{$e}", $this->comprador)->assertJsonPath('dados.linhas.0.quantidade_faturada', '0.000')
            ->assertJsonPath('dados.linhas.0.cambial_recebido_por_faturar_kz', '360.00');
        $this->postJson("/api/compras/rececoes/{$r}/reverter-validacao", ['motivo' => 'Guia errada'], $this->armazenista)->assertOk()->assertJsonPath('dados.validado', false);
        $this->assertSame('0.000', $this->stock()[0]);
        $this->postJson("/api/compras/rececoes/{$r}/anular", ['motivo' => 'Guia errada'], $this->armazenista)->assertOk();
        $this->getJson("/api/compras/encomendas/{$e}", $this->comprador)->assertJsonPath('dados.estado', 'EM_PROCESSAMENTO');
        $this->faturar($e, 1, 'FT 1')->assertCreated();   // o n.º de uma factura anulada pode voltar a ser usado
    }

    #[Test]
    public function moeda_estrangeira_facturado_antes_de_receber_e_diferenca_de_cambio(): void
    {
        $this->putJson('/api/compras/configuracao/contas', ['contas' => ['diferencas_cambio_desfavoraveis' => '6881', 'diferencas_cambio_favoraveis' => '7881']], $this->contabilista)->assertOk();
        // encomenda de 3 × USD 10 ao câmbio manual 900 (Kz 9 000/un.)
        $e = $this->encomenda(3, 10, ['codigo_moeda' => 'USD', 'taxa_cambio' => 900]);
        $this->getJson("/api/compras/encomendas/{$e}", $this->comprador)->assertJsonPath('dados.codigo_moeda', 'USD')->assertJsonPath('dados.montante_total', '27000.00')
            ->assertJsonPath('dados.montante_total_moeda', '30.00');

        // 1) factura de 2 un. ao câmbio 950 ANTES da recepção: fica "facturado por receber" pelo valor da factura (19 000)
        $f1 = $this->faturar($e, 2, 'INV-1', ['taxa_cambio' => 950])->assertCreated()->assertJsonPath('dados.linhas.0.valor_transitoria_kz', '19000.00')->json('dados.id');
        $this->assertSame(['D 3281 19000.00', 'D 3451 2660.00', 'C 3211 21660.00'],
            $this->lancamento($this->postJson("/api/compras/faturas/{$f1}/contabilizar", [], $this->contabilista)->json('dados.numero_lan_contabilizacao')));

        // 2) recepção das 3 un.: 2 ao valor da factura (19 000) + 1 ao câmbio da encomenda (9 000) = 28 000
        $r = $this->receber($e, 3)->json('dados.id');
        $this->postJson("/api/compras/rececoes/{$r}/validar", ['armazem_id' => $this->armazem->id], $this->armazenista)->assertOk()->assertJsonPath('dados.valor_total_kz', '28000.00');
        $this->assertSame(['3.000', '9333.333333'], $this->stock());   // custo médio da entrada

        // 3) factura da última unidade ao câmbio 980: 9 800 face a 9 000 recebido → 800 de diferença desfavorável
        $f2 = $this->faturar($e, 1, 'INV-2', ['taxa_cambio' => 980])->assertCreated()->assertJsonPath('dados.linhas.0.valor_transitoria_kz', '9000.00')->json('dados.id');
        $this->assertSame(['D 3281 9000.00', 'D 6881 800.00', 'D 3451 1372.00', 'C 3211 11172.00'],
            $this->lancamento($this->postJson("/api/compras/faturas/{$f2}/contabilizar", [], $this->contabilista)->json('dados.numero_lan_contabilizacao')));
    }

    #[Test]
    public function factura_directa_so_de_servicos_e_contas_por_configurar(): void
    {
        $base = ['fornecedor_id' => $this->fornecedor->id, 'numero_fatura' => 'SRV-9', 'data' => $this->hoje];
        $this->postJson('/api/compras/faturas', $base + ['linhas' => [['produto_id' => $this->artigo->id, 'quantidade' => 1, 'preco_unitario' => 10]]], $this->comprador)
            ->assertStatus(422)->assertJsonPath('codigo', 'FATURA_DIRETA_COM_STOCK');
        $f = $this->postJson('/api/compras/faturas', $base + ['linhas' => [['produto_id' => $this->servico->id, 'quantidade' => 2, 'preco_unitario' => 12500.50, 'taxa_imposto' => 14]]],
            $this->comprador)->assertCreated()->assertJsonPath('dados.montante_total', '28501.14')->json('dados.id');
        $this->assertSame(['D 752 25001.00', 'D 3451 3500.14', 'C 3211 28501.14'],
            $this->lancamento($this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $this->contabilista)->assertOk()->json('dados.numero_lan_contabilizacao')));

        // produto sem conta de custo e sem configuração: recusado com indicação do que configurar
        $semConta = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Produto::create(['codigo' => 'S2', 'nome' => 'Consultoria', 'taxa_imposto' => 0, 'movimenta_stock' => false]));
        $f2 = $this->postJson('/api/compras/faturas', ['numero_fatura' => 'SRV-10'] + $base + ['linhas' => [['produto_id' => $semConta->id, 'quantidade' => 1, 'preco_unitario' => 100]]], $this->comprador)->json('dados.id');
        $this->postJson("/api/compras/faturas/{$f2}/contabilizar", [], $this->contabilista)->assertStatus(422)->assertJsonPath('codigo', 'CONFIG_COMPRAS_EM_FALTA');
    }

    #[Test]
    public function documentos_de_compra_de_outra_empresa_nao_sao_visiveis(): void
    {
        $p = $this->pedido()->json('dados.id');
        $outra = $this->criarEmpresa();
        $perfil = $this->criarPerfil(['_v2' => true, 'compras_pedidos_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($outra->id);
        $this->getJson("/api/compras/pedidos/{$p}", $this->entrar($u) + ['X-Empresa-Id' => $outra->id])->assertNotFound();
    }

    #[Test]
    public function factura_migrada_sem_valor_da_transitoria_e_notas_das_demonstracoes(): void
    {
        $notas = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => collect(['4', '9', '11'])
            ->mapWithKeys(fn ($c) => [$c => NotaDemonstracao::create(['codigo' => $c, 'descricao' => "Nota {$c}"])->id])->all());
        $e = $this->encomenda();
        $r = $this->receber($e, 4)->json('dados.id');
        $this->postJson("/api/compras/rececoes/{$r}/validar", ['armazem_id' => $this->armazem->id], $this->armazenista)->assertOk();
        $f = $this->faturar($e, 4, 'FT 9')->assertCreated()->json('dados.id');
        // E-STK-2: linha migrada sem valor_transitoria_kz → a 3281 salda pelo valor da linha (antes: 0 na 3281 e 360 em diferenças de câmbio)
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => ItemCompra::query()->where('fatura_compra_id', $f)->update(['valor_transitoria_kz' => null]));
        $lan = $this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $this->contabilista)->assertOk()->json('dados.numero_lan_contabilizacao');
        $this->assertSame(['D 3281 360.00', 'D 3451 50.40', 'C 3211 410.40'], $this->lancamento($lan));
        // E-CON-1: notas como o legado (321/322/328 → 11, 34 → 9)
        $this->assertSame(['3281' => $notas['11'], '3451' => $notas['9'], '3211' => $notas['11']], app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => LancamentoContabil::query()->where('numero_lan', $lan)->orderBy('id')->pluck('nota_demonstracao_id', 'codigo_conta')->map(fn ($v) => (int) $v)->all()));
    }
}
