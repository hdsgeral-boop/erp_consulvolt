<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\Moeda;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Caixa em moeda estrangeira (moedas_tesouraria.js:355-420): moedas habilitadas por empresa, permissão própria,
 * liquidação de documentos em USD com ganho e perda cambial, saldo por moeda, conferência na moeda e Kz inalterado.
 * Dados fictícios.
 */
final class TesourariaCaixaMultimoedaTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $cliente;

    private Terceiro $fornecedor;

    private string $hoje;

    private array $gestor;

    private array $operador;

    private array $semMoeda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3111' => 'Clientes', '3211' => 'Fornecedores', '3452' => 'IVA liquidado', '3451' => 'IVA dedutível', '4511' => 'Caixa Kz',
                '611' => 'Vendas', '752' => 'Serviços', '6881' => 'Diferenças de câmbio favoráveis', '7881' => 'Diferenças de câmbio desfavoráveis', '6882' => 'Sobras', '7882' => 'Quebras'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            PlanoConta::create(['codigo' => '4512', 'descricao' => 'Caixa USD', 'tipo' => 'M', 'codigo_moeda' => 'USD']);
            $this->cliente = Terceiro::create(['nome' => 'Cliente USD', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            $this->fornecedor = Terceiro::create(['nome' => 'Fornecedor USD', 'nif' => '5000000002', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211']);
            Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611', 'conta_iva_liquidado' => '3452']);
            Produto::create(['codigo' => 'S1', 'nome' => 'Serviço', 'taxa_imposto' => 14, 'movimenta_stock' => false, 'conta_custo' => '752', 'conta_iva_dedutivel' => '3451']);
            TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => now()->subDays(3)->toDateString(), 'taxa' => '900.5']);
        });
        $base = ['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_alterar_preco', 'vendas_fat_contabilizar', 'compras_faturacao_view', 'compras_fact_registar',
            'compras_fact_contabilizar', 'teso_folha_caixa_view', 'teso_caixa_operar', 'teso_caixa_fechar', 'teso_caixa_contabilizar', 'teso_gestao_pagamentos_view',
            'teso_gestao_conferencia_view', 'teso_conf_registar', 'teso_integrar', 'teso_doc_emitir'];
        $this->gestor = $this->sessao(array_merge($base, ['teso_caixa_multimoeda_gerir', 'teso_caixa_multimoeda_liquidar']));
        $this->operador = $this->gestor;
        $this->semMoeda = $this->sessao($base);
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['diferencas_cambio_favoraveis' => '6881', 'diferencas_cambio_desfavoraveis' => '7881',
            'caixa_sobras' => '6882', 'caixa_quebras' => '7882']], $this->gestor)->assertOk();
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

    private function habilitar(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Moeda::query()->firstOrCreate(['codigo' => 'USD'], ['nome' => 'Dólar', 'simbolo' => '$', 'casas_decimais' => 2, 'ativo' => true]));
        $this->putJson('/api/tesouraria/caixa/moedas', ['moedas' => ['USD']], $this->gestor)->assertOk()->assertJsonPath('dados.habilitadas.0', 'USD');
    }

    private function cambioHoje(string $taxa): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => $this->hoje, 'taxa' => $taxa]));
    }

    private function lancamento(string $numeroLan): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $numeroLan)->orderBy('id')->get()
            ->map(fn ($l) => trim("{$l->tipo_dc} {$l->codigo_conta} {$l->valor} ".($l->codigo_moeda ? "{$l->codigo_moeda} {$l->valor_moeda}" : '')))->all());
    }

    #[Test]
    public function por_omissao_so_kz_e_a_configuracao_exige_a_permissao_de_gerir(): void
    {
        $this->getJson('/api/tesouraria/caixa/moedas', $this->gestor)->assertOk()->assertJsonPath('dados.habilitadas', [])->assertJsonPath('dados.pode_gerir', true);
        $this->getJson('/api/tesouraria/caixa/moedas', $this->semMoeda)->assertOk()->assertJsonPath('dados.activo', false)->assertJsonPath('dados.moedas', []);
        $this->putJson('/api/tesouraria/caixa/moedas', ['moedas' => ['USD']], $this->semMoeda)->assertForbidden();
        $this->putJson('/api/tesouraria/caixa/moedas', ['moedas' => ['AOA']], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'MOEDA_BASE');
        $this->putJson('/api/tesouraria/caixa/moedas', ['moedas' => ['XXX']], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'MOEDA_INEXISTENTE');
        $this->habilitar();
        $this->getJson('/api/tesouraria/caixa/moedas', $this->gestor)->assertJsonPath('dados.habilitadas', ['USD']);
    }

    #[Test]
    public function moeda_nao_habilitada_e_utilizador_sem_permissao_sao_recusados(): void
    {
        $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4512', 'data' => $this->hoje], $this->gestor)
            ->assertStatus(422)->assertJsonPath('codigo', 'MOEDA_NAO_HABILITADA');
        $this->habilitar();
        $this->cambioHoje('1000');
        $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4512', 'data' => $this->hoje], $this->semMoeda)->assertForbidden();
        $id = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4512', 'data' => $this->hoje], $this->gestor)->assertCreated()
            ->assertJsonPath('dados.codigo_moeda', 'USD')->json('dados.id');

        // sem a permissão a sessão não existe: nem na lista nem no detalhe, nem para movimentar
        $this->assertSame([], collect($this->getJson('/api/tesouraria/caixa/sessoes', $this->semMoeda)->json('dados'))->where('codigo_moeda', 'USD')->all());
        $this->getJson("/api/tesouraria/caixa/sessoes/{$id}", $this->semMoeda)->assertForbidden();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/movimentos", ['tipo' => 'PAG', 'data_documento' => $this->hoje, 'descricao' => 'Despesa', 'conta_contrapartida' => '752', 'valor' => 5], $this->semMoeda)
            ->assertForbidden();
        $this->assertSame([], collect($this->getJson('/api/tesouraria/caixa/saldos', $this->semMoeda)->json('dados'))->where('moeda', 'USD')->all());

        // não se desabilita a moeda com sessão aberta
        $this->putJson('/api/tesouraria/caixa/moedas', ['moedas' => []], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_ABERTA_NA_MOEDA');
    }

    #[Test]
    public function recebimento_em_usd_liquida_pelo_historico_com_ganho_cambial_e_saldo_por_moeda(): void
    {
        $this->habilitar();
        $ft = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje, 'codigo_moeda' => 'USD',
            'linhas' => [['produto_id' => $this->produto('P1'), 'quantidade' => 3, 'preco_unitario' => 10]]], $this->gestor)->assertCreated()->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->gestor)->assertOk();   // USD 34,20 = Kz 30 797,10 ao câmbio 900,5
        $this->cambioHoje('1000');

        $s = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4512', 'data' => $this->hoje], $this->gestor)->assertCreated()->json('dados.id');
        $mov = fn (array $d) => $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/movimentos", $d + ['tipo' => 'REC', 'data_documento' => $this->hoje, 'descricao' => 'Recebimento',
            'conta_contrapartida' => '3111', 'terceiro_id' => $this->cliente->id, 'venda_id' => $ft], $this->gestor);

        $mov(['valor' => 35])->assertStatus(422)->assertJsonPath('codigo', 'VALOR_SUPERIOR_EM_ABERTO');
        $r = $mov(['valor' => 10])->assertCreated()->assertJsonPath('dados.saldo_sistema', '10.00');
        $m = $r->json('dados.movimentos.0');
        $this->assertSame(['USD', '1000.000000', '10000.00', '9005.00', 'USD', '10.00'], [$m['codigo_moeda'], $m['taxa_cambio'], $m['valor_kz'], $m['contravalor_kz'], $m['contra_moeda'], $m['contravalor_moeda']]);
        // o pendente já desconta o contravalor (histórico) e a moeda
        $this->getJson('/api/tesouraria/pendentes', $this->gestor)->assertJsonPath('dados.0.saldo_moeda', '24.20')->assertJsonPath('dados.0.saldo', '21792.10');

        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/fechar", ['saldo_fisico' => 10, 'data' => $this->hoje], $this->gestor)->assertOk()->assertJsonPath('dados.saldo_fecho', '10.00');
        $lans = $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/contabilizar", [], $this->gestor)->assertOk()->json('dados.numeros_lan_contabilizacao');
        $this->assertSame(['D 4512 10000.00 USD 10.00', 'C 3111 9005.00 USD 10.00', 'C 6881 995.00'], $this->lancamento($lans[0]));
        $this->getJson("/api/vendas/documentos/{$ft}", $this->gestor)->assertJsonPath('dados.valor_pago', '9005.00')->assertJsonPath('dados.estado', 'PARCIAL');

        // saldo da caixa por moeda: USD 10,00 (Kz 10 000)
        $c = collect($this->getJson('/api/tesouraria/caixa/saldos', $this->gestor)->assertOk()->json('dados'))->firstWhere('conta', '4512');
        $this->assertSame(['USD', '10.00', '10000.00'], [$c['moeda'], $c['saldo_moeda'], $c['saldo_kz']]);
    }

    #[Test]
    public function pagamento_em_usd_com_perda_cambial_e_cambio_manual_dentro_da_tolerancia(): void
    {
        $this->habilitar();
        $f = $this->postJson('/api/compras/faturas', ['fornecedor_id' => $this->fornecedor->id, 'numero_fatura' => 'INV-USD-1', 'data' => $this->hoje, 'codigo_moeda' => 'USD',
            'taxa_cambio' => 900, 'linhas' => [['produto_id' => $this->produto('S1'), 'quantidade' => 1, 'preco_unitario' => 100, 'taxa_imposto' => 14]]], $this->gestor)
            ->assertCreated()->assertJsonPath('dados.montante_total', '102600.00')->json('dados.id');
        $this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $this->gestor)->assertOk();
        $this->cambioHoje('950');

        $s = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4512', 'data' => $this->hoje], $this->gestor)->assertCreated()->json('dados.id');
        $corpo = ['tipo' => 'PAG', 'data_documento' => $this->hoje, 'descricao' => 'Pagamento', 'conta_contrapartida' => '3211', 'terceiro_id' => $this->fornecedor->id,
            'fatura_compra_id' => $f, 'valor' => 114];
        // câmbio manual fora da tolerância (5 %) é recusado; dentro, aceita-se e fica registado como manual (taxa_cambio_id nulo)
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/movimentos", $corpo + ['taxa_cambio' => 1200], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'CAMBIO_FORA_TOLERANCIA');
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/movimentos", $corpo, $this->gestor)->assertCreated();
        $m = $this->getJson("/api/tesouraria/caixa/sessoes/{$s}", $this->gestor)->json('dados.movimentos.0');
        $this->assertSame(['950.000000', '108300.00', '102600.00'], [$m['taxa_cambio'], $m['valor_kz'], $m['contravalor_kz']]);
        $this->assertNotNull($m['taxa_cambio_id']);

        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/fechar", ['saldo_fisico' => -114, 'data' => $this->hoje], $this->gestor)->assertStatus(422);
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/fechar", ['saldo_fisico' => 0, 'data' => $this->hoje], $this->gestor)->assertOk();
        // saldo físico 0 contra -114 do sistema: sobra de USD 114 lançada ao câmbio do dia do fecho
        $lans = $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/contabilizar", [], $this->gestor)->assertOk()->json('dados.numeros_lan_contabilizacao');
        $this->assertSame(['D 3211 102600.00 USD 114.00', 'C 4512 108300.00 USD 114.00', 'D 7881 5700.00'], $this->lancamento($lans[0]));
        $this->assertSame(['D 4512 108300.00 USD 114.00', 'C 6882 108300.00'], $this->lancamento($lans[1]));
        $this->getJson("/api/compras/faturas/{$f}", $this->gestor)->assertJsonPath('dados.estado', 'PAGO');
    }

    #[Test]
    public function diferenca_cambial_exige_as_contas_configuradas_antes_de_aceitar_o_movimento(): void
    {
        $this->habilitar();
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['diferencas_cambio_favoraveis' => null, 'diferencas_cambio_desfavoraveis' => null]], $this->gestor)->assertOk();
        $ft = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje, 'codigo_moeda' => 'USD',
            'linhas' => [['produto_id' => $this->produto('P1'), 'quantidade' => 1, 'preco_unitario' => 10]]], $this->gestor)->assertCreated()->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->gestor)->assertOk();
        $this->cambioHoje('1000');
        $s = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4512', 'data' => $this->hoje], $this->gestor)->assertCreated()->json('dados.id');
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/movimentos", ['tipo' => 'REC', 'data_documento' => $this->hoje, 'descricao' => 'Recebimento', 'conta_contrapartida' => '3111',
            'terceiro_id' => $this->cliente->id, 'venda_id' => $ft, 'valor' => 5], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'CONFIG_TESOURARIA_EM_FALTA');
    }

    #[Test]
    public function conferencia_de_caixa_em_usd_conta_se_na_moeda(): void
    {
        $this->habilitar();
        $this->cambioHoje('1000');
        $s = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4512', 'data' => $this->hoje, 'saldo_abertura' => 0], $this->gestor)->assertCreated()->json('dados.id');
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/movimentos", ['tipo' => 'REC', 'data_documento' => $this->hoje, 'descricao' => 'Venda a pronto', 'conta_contrapartida' => '611', 'valor' => 100], $this->gestor)->assertCreated();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/fechar", ['saldo_fisico' => 100, 'data' => $this->hoje], $this->gestor)->assertOk();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/contabilizar", [], $this->gestor)->assertOk();

        $corpo = ['codigo_conta' => '4512', 'data_conferencia' => $this->hoje];
        $this->postJson('/api/tesouraria/conferencias', $corpo, $this->gestor)->assertStatus(422);
        $this->postJson('/api/tesouraria/conferencias', $corpo + ['total_contado' => 95], $this->semMoeda)->assertForbidden();
        $c = $this->postJson('/api/tesouraria/conferencias', $corpo + ['total_contado' => 95], $this->gestor)->assertCreated()->assertJsonPath('dados.codigo_moeda', 'USD')
            ->assertJsonPath('dados.total_fisico', '95.00')->assertJsonPath('dados.total_sistema', '100.00')->assertJsonPath('dados.diferenca', '-5.00')->json('dados.id');
        $this->putJson("/api/tesouraria/conferencias/{$c}", $corpo + ['total_contado' => 95, 'justificacao' => 'Troco dado em excesso ao cliente'], $this->gestor)->assertOk();
        $f = $this->postJson("/api/tesouraria/conferencias/{$c}/finalizar", ['regularizar' => true], $this->gestor)->assertOk()->assertJsonPath('dados.conta_regularizacao', '7882');
        $this->assertSame(['D 7882 5000.00', 'C 4512 5000.00 USD 5.00'], $this->lancamento($f->json('dados.referencia_lancamento')));
        // sem a permissão a conferência em moeda não aparece
        $this->assertSame([], collect($this->getJson('/api/tesouraria/conferencias', $this->semMoeda)->json('dados'))->where('codigo_conta', '4512')->all());
    }

    #[Test]
    public function caixa_em_kz_fica_inalterada(): void
    {
        $this->habilitar();
        $s = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje], $this->semMoeda)->assertCreated()->assertJsonPath('dados.codigo_moeda', 'AOA')->json('dados.id');
        $r = $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/movimentos", ['tipo' => 'PAG', 'data_documento' => $this->hoje, 'descricao' => 'Despesa', 'conta_contrapartida' => '752',
            'valor' => 200, 'taxa_cambio' => 999], $this->semMoeda)->assertCreated()->assertJsonPath('dados.saldo_sistema', '-200.00');
        $m = $r->json('dados.movimentos.0');
        $this->assertSame(['AOA', '200.00', null, null], [$m['codigo_moeda'], $m['valor_kz'], $m['taxa_cambio'], $m['contravalor_kz']]);
        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/fechar", ['saldo_fisico' => 0, 'data' => $this->hoje], $this->semMoeda)->assertOk();
        $lans = $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/contabilizar", [], $this->gestor)->assertOk()->json('dados.numeros_lan_contabilizacao');
        $this->assertSame(['D 752 200.00', 'C 4511 200.00'], $this->lancamento($lans[0]));
    }
}
