<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\Empresa;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\InfotipoSalarial;
use App\Models\ItemCompra;
use App\Models\LancamentoContabil;
use App\Models\MapeamentoContabilRH;
use App\Models\MapeamentoContabilSistemaRH;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeNegocio;
use App\Services\Reparticao\ServicoReparticao;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase C — UN/CC por linha (R5) e repartição na contabilização (R3) de vendas, compras e salários, e Balanço/DR por
 * unidade de negócio. Regras: soma das partes = total ao cêntimo (a última absorve o arredondamento), lançamento
 * equilibrado, linha › repartição do documento › cabeçalho; na venda só proveitos e CMV (não 31/34), na compra só as
 * linhas de gasto (não 32/34). Dados fictícios.
 */
final class FcReparticaoContabilizacaoTest extends TestCase
{
    private Empresa $empresa;

    /** @var array<string, int> */
    private array $un = [];

    private int $cc;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000123']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['UN1' => 'Comercial', 'UN2' => 'Industrial', 'UN3' => 'Serviços'] as $c => $n) {
                $this->un[$c] = UnidadeNegocio::create(['codigo' => $c, 'nome' => $n, 'estado' => 'ATIVO'])->id;
            }
            $this->cc = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Obra fictícia'])->id;
            foreach ([['3111', 'Clientes'], ['3452', 'IVA liquidado'], ['4311', 'Banco fictício'], ['611', 'Vendas'], ['3211', 'Fornecedores'], ['3451', 'IVA dedutível'],
                ['752', 'Fornecimentos e serviços']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            NotaDemonstracao::create(['codigo' => '22', 'descricao' => 'Vendas']);
        });
        $this->s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_alterar_preco', 'vendas_fat_contabilizar', 'vendas_fat_descontab', 'vendas_recibos',
            'compras_faturacao_view', 'compras_fact_registar', 'compras_fact_contabilizar', 'tabelas_aux_view', 'aux_gerir',
            'contab_mapa_balanco_view', 'contab_mapa_dr_view', 'lancamentos_view']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function criterio(string $codigo, string $forma): int
    {
        return $this->postJson('/api/reparticao/criterios', ['codigo' => $codigo, 'nome' => "Critério {$codigo}", 'criterio' => $forma], $this->s)->assertCreated()->json('dados.id');
    }

    /** @param  array<string, string|int|null>  $pesos */
    private function alocacao(int $criterio, array $pesos): array
    {
        return ['criterio_id' => $criterio, 'unidades' => array_map(fn ($c, $p) => ['unidade_negocio_id' => $this->un[$c], 'peso' => $p], array_keys($pesos), $pesos)];
    }

    /** @return list<string> "D|C conta valor UN [CC]" ordenadas */
    private function linhas(string $numeroLan): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($numeroLan) {
            $codigos = UnidadeNegocio::query()->pluck('codigo', 'id');
            $ls = LancamentoContabil::query()->where('numero_lan', $numeroLan)->get();
            $d = $ls->where('tipo_dc', 'D')->reduce(fn ($s, $l) => bcadd($s, (string) $l->valor, 2), '0.00');
            $c = $ls->where('tipo_dc', 'C')->reduce(fn ($s, $l) => bcadd($s, (string) $l->valor, 2), '0.00');
            $this->assertSame($d, $c, 'o lançamento tem de ficar equilibrado');

            return $ls->map(fn ($l) => trim("{$l->tipo_dc} {$l->codigo_conta} {$l->valor} ".($codigos[$l->unidade_negocio_id] ?? '-').($l->centro_custo_id ? ' CC' : '')))
                ->sort()->values()->all();
        });
    }

    #[Test]
    public function venda_reparte_proveitos_por_partes_iguais_e_a_linha_com_unidade_propria_fica_como_esta(): void
    {
        $iguais = $this->criterio('IGUAIS', 'PARTES_IGUAIS');
        [$cliente, $produto] = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => [
            Terceiro::create(['nome' => 'Cliente Fictício', 'nif' => '5000000041', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111'])->id,
            Produto::create(['codigo' => 'SV', 'nome' => 'Serviço fictício', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => false])->id,
        ]);

        // UN de outra empresa na linha: recusada; alocação inválida: recusada antes de gravar
        $base = ['tipo_documento' => 'FT', 'cliente_id' => $cliente, 'data_emissao' => $this->hoje];
        $this->postJson('/api/vendas/documentos', $base + ['linhas' => [['produto_id' => $produto, 'quantidade' => 1]],
            'unidades_alocadas' => $this->alocacao($iguais, ['UN1' => null])], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ALOCACAO_INVALIDA');

        $ft = $this->postJson('/api/vendas/documentos', $base + ['linhas' => [
            ['produto_id' => $produto, 'quantidade' => 1],                                                                   // 1 000 → repartido 333,33 / 333,33 / 333,34
            ['produto_id' => $produto, 'quantidade' => 1, 'preco_unitario' => 500, 'unidade_negocio_id' => $this->un['UN3'], 'centro_custo_id' => $this->cc],   // linha própria
        ], 'unidades_alocadas' => $this->alocacao($iguais, ['UN1' => null, 'UN2' => null, 'UN3' => null])], $this->s)->assertCreated()->json('dados.id');

        $lan = $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->s)->assertOk()->json('dados.numero_lan_contabilizacao');
        $this->assertSame([
            'C 3452 210.00 -',          // IVA: não se reparte, fica com o cabeçalho
            'C 611 333.33 UN1', 'C 611 333.33 UN2', 'C 611 333.34 UN3',
            'C 611 500.00 UN3 CC',      // unidade e centro de custo da própria linha (linha › repartição › cabeçalho)
            'D 3111 1710.00 -',         // cliente: a dívida é uma só
        ], $this->linhas($lan));
        $desc = app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => LancamentoContabil::query()->where('numero_lan', $lan)->where('unidade_negocio_id', $this->un['UN1'])->value('descricao'));
        $this->assertStringContainsString('33,33% UN1 — Comercial', $desc);

        // documento contabilizado: a repartição já não muda
        $this->putJson("/api/reparticao/alocacoes/venda/{$ft}", ['unidades_alocadas' => null], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_CONTABILIZADO');

        // DR por unidade: uma coluna por unidade com movimento, «Sem unidade» e o Total
        $dr = $this->getJson("/api/contabilidade/relatorios/demonstracao-resultados/por-unidade?data_inicio={$this->hoje}&data_fim={$this->hoje}", $this->s)->assertOk()->json('dados');
        $this->assertSame(['UN1 — Comercial', 'UN2 — Industrial', 'UN3 — Serviços', 'Sem unidade'], array_column($dr['colunas'], 'rotulo'));
        $this->assertSame(['333.33', '333.33', '833.34', '0.00'], array_map(fn ($c) => $c['mapa']['notas']['atual']['22'], $dr['colunas']));
        $this->assertSame('1500.00', $dr['total']['notas']['atual']['22']);
        $this->getJson("/api/contabilidade/relatorios/balanco/por-unidade?data_fim={$this->hoje}", $this->s)->assertOk()->assertJsonCount(4, 'dados.colunas');
        $this->getJson("/api/contabilidade/relatorios/balanco/por-unidade?data_fim={$this->hoje}", $this->sessao(['contab_mapa_dr_view']))->assertForbidden();

        // NC por conversão herda a repartição e a UN/CC das linhas (o crédito anula a mesma divisão)
        $nc = $this->postJson("/api/vendas/documentos/{$ft}/converter", ['tipo_destino' => 'NC', 'motivo_nota_credito' => 'Devolução fictícia'], $this->s)->assertCreated()->json('dados.id');
        $lanNc = $this->postJson("/api/vendas/documentos/{$nc}/contabilizar", [], $this->s)->assertOk()->json('dados.numero_lan_contabilizacao');
        $this->assertSame(['C 3111 1710.00 -', 'D 3452 210.00 -', 'D 611 333.33 UN1', 'D 611 333.33 UN2', 'D 611 333.34 UN3', 'D 611 500.00 UN3 CC'], $this->linhas($lanNc));
    }

    #[Test]
    public function recibo_herda_a_reparticao_da_primeira_factura(): void
    {
        $pct = $this->criterio('PCT', 'PERCENTAGEM');
        [$cliente, $produto] = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => [
            Terceiro::create(['nome' => 'Cliente Fictício', 'nif' => '5000000071', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111'])->id,
            Produto::create(['codigo' => 'SV', 'nome' => 'Serviço fictício', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => false])->id,
        ]);
        $ft = $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $cliente, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $produto, 'quantidade' => 1]], 'unidades_alocadas' => $this->alocacao($pct, ['UN1' => 1, 'UN2' => 1])], $this->s)
            ->assertCreated()->json('dados.id');
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->s)->assertOk();
        $recibo = $this->postJson('/api/vendas/recibos', ['cliente_id' => $cliente, 'data' => $this->hoje, 'codigo_conta' => '4311', 'meio_pagamento' => 'TRANSFERENCIA',
            'alocacoes' => [['venda_id' => $ft, 'montante' => '1140']]], $this->s)->assertCreated()->json('dados.id');
        $this->getJson("/api/reparticao/alocacoes/recibo_venda/{$recibo}", $this->s)->assertOk()
            ->assertJsonPath('dados.unidades_alocadas.criterio_id', $pct)->assertJsonCount(2, 'dados.unidades_alocadas.unidades');
    }

    #[Test]
    public function factura_da_encomenda_herda_a_reparticao_do_pedido_e_a_unidade_da_linha_segue_o_pedido(): void
    {
        $pct = $this->criterio('PCT', 'PERCENTAGEM');
        $comprador = $this->sessao(['compras_pedidos_view', 'compras_ped_criar']);
        [$fornecedor, $servico] = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => [
            Terceiro::create(['nome' => 'Fornecedor Fictício', 'nif' => '5000000061', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211'])->id,
            Produto::create(['codigo' => 'LP', 'nome' => 'Limpeza', 'preco_unitario' => 0, 'taxa_imposto' => 14, 'movimenta_stock' => false,
                'conta_custo' => '752', 'conta_iva_dedutivel' => '3451'])->id,
        ]);
        // o pedido grava a alocação e a UN/CC de cada linha (validadas no servidor)
        $pedido = $this->postJson('/api/compras/pedidos', ['nome_requerente' => 'Requerente fictício', 'data' => $this->hoje, 'linhas' => [
            ['produto_id' => $servico, 'quantidade' => 1, 'preco_unitario' => 1000, 'centro_custo_id' => $this->cc],
        ], 'unidades_alocadas' => $this->alocacao($pct, ['UN1' => 25, 'UN2' => 75])], $comprador)->assertCreated()->json('dados.id');
        $this->getJson("/api/reparticao/alocacoes/pedido_compra/{$pedido}", $comprador)->assertOk()->assertJsonPath('dados.unidades_alocadas.unidades.1.peso', '75.0000');

        // encomenda e factura sem alocação própria (atalho do processo de compra: proposta/adjudicação estão cobertas em ComprasTest)
        $fatura = app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($pedido, $fornecedor, $servico) {
            $enc = EncomendaCompra::create(['pedido_compra_id' => $pedido, 'fornecedor_id' => $fornecedor, 'numero_encomenda' => 'EC FC/1', 'data' => $this->hoje,
                'estado' => 'EM_PROCESSAMENTO', 'contabilizado' => false, 'montante_total' => 1000, 'total_imposto' => 140, 'total_com_imposto' => 1140]);
            $f = FaturaCompra::create(['encomenda_compra_id' => $enc->id, 'fornecedor_id' => $fornecedor, 'numero_fatura' => 'FF-FC-2', 'data' => $this->hoje,
                'montante_total' => 1140, 'total_imposto' => 140, 'estado' => 'PENDENTE', 'contabilizado' => false]);
            $linhaPedido = ItemCompra::query()->where('pedido_compra_id', $pedido)->firstOrFail();
            $this->assertSame($this->cc, (int) $linhaPedido->centro_custo_id);
            ItemCompra::create(['tipo_documento_origem' => 'FATURA', 'fatura_compra_id' => $f->id, 'produto_id' => $servico, 'quantidade' => 1, 'preco_unitario' => 1000,
                'total' => 1000, 'total_kz' => 1000, 'imposto_kz' => 140, 'taxa_imposto' => 14, 'centro_custo_id' => $linhaPedido->centro_custo_id]);

            return $f->id;
        });
        $lan = $this->postJson("/api/compras/faturas/{$fatura}/contabilizar", [], $this->s)->assertOk()->json('dados.numero_lan_contabilizacao');
        $this->assertSame(['C 3211 1140.00 -', 'D 3451 140.00 -', 'D 752 250.00 UN1 CC', 'D 752 750.00 UN2 CC'], $this->linhas($lan));
    }

    #[Test]
    public function factura_de_compra_reparte_so_as_linhas_de_gasto_por_percentagem(): void
    {
        $pct = $this->criterio('PCT', 'PERCENTAGEM');
        [$fornecedor, $servico] = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => [
            Terceiro::create(['nome' => 'Fornecedor Fictício', 'nif' => '5000000051', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211'])->id,
            Produto::create(['codigo' => 'TR', 'nome' => 'Transporte', 'preco_unitario' => 0, 'taxa_imposto' => 14, 'movimenta_stock' => false,
                'conta_custo' => '752', 'conta_iva_dedutivel' => '3451'])->id,
        ]);
        $f = $this->postJson('/api/compras/faturas', ['fornecedor_id' => $fornecedor, 'numero_fatura' => 'FF-FC-1', 'data' => $this->hoje, 'linhas' => [
            ['produto_id' => $servico, 'quantidade' => 2, 'preco_unitario' => 12500.50, 'taxa_imposto' => 14],
            ['produto_id' => $servico, 'quantidade' => 1, 'preco_unitario' => 100, 'taxa_imposto' => 14, 'unidade_negocio_id' => $this->un['UN3']],
        ], 'unidades_alocadas' => $this->alocacao($pct, ['UN1' => 70, 'UN2' => 30])], $this->s)->assertCreated()->assertJsonPath('dados.montante_total', '28615.14')->json('dados.id');
        $this->getJson("/api/reparticao/alocacoes/fatura_compra/{$f}", $this->s)->assertOk()->assertJsonPath('dados.unidades_alocadas.unidades.0.peso', '70.0000');

        $lan = $this->postJson("/api/compras/faturas/{$f}/contabilizar", [], $this->s)->assertOk()->json('dados.numero_lan_contabilizacao');
        $this->assertSame([
            'C 3211 28615.14 -',        // fornecedor: não se reparte
            'D 3451 3514.14 -',         // IVA dedutível: não se reparte
            'D 752 100.00 UN3',         // linha com unidade própria
            'D 752 17500.70 UN1', 'D 752 7500.30 UN2',
        ], $this->linhas($lan));
    }

    #[Test]
    public function salarios_repartem_custo_impostos_e_liquido_do_colaborador_com_alocacao(): void
    {
        $pct = $this->criterio('PCT', 'PERCENTAGEM');
        $ids = app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['7211' => 'Remunerações', '7221' => 'Encargos INSS', '3611' => 'Remunerações a pagar', '3421' => 'IRT', '3431' => 'INSS a pagar'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores']);
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true']);
            $ana = Colaborador::create(['nome_completo' => 'Ana Fictícia', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org->id, 'dias_uteis_mes' => 22,
                'unidade_negocio_id' => $this->un['UN1']]);
            $rui = Colaborador::create(['nome_completo' => 'Rui Fictício', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org->id, 'dias_uteis_mes' => 22,
                'unidade_negocio_id' => $this->un['UN3']]);
            foreach ([$ana, $rui] as $c) {
                ContratoTrabalho::create(['colaborador_id' => $c->id, 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8,
                    'data_inicio' => now()->subYear()->toDateString(), 'remuneracoes' => [['infotype_id' => $base->id, 'value_month' => 300000]]]);
            }
            MapeamentoContabilRH::create(['infotipo_salarial_id' => $base->id, 'tipo_organizacao_id' => $org->id, 'avencado' => false, 'numero_conta' => '7211']);
            foreach (['NET_PAY_CREDIT' => '3611', 'IRT_CREDIT' => '3421', 'INSS_FUNC_CREDIT' => '3431', 'INSS_EMP_DEBIT' => '7221', 'INSS_EMP_CREDIT' => '3431'] as $c => $conta) {
                MapeamentoContabilSistemaRH::create(['codigo' => $c, 'tipo_organizacao_id' => $org->id, 'avencado' => false, 'numero_conta' => $conta]);
            }

            return ['ana' => $ana->id];
        });
        $rh = $this->sessao(['calcular_view', 'processamento_view', 'calcular_lancar', 'calcular_folha', 'processamento_validate', 'processamento_integrate',
            'colaboradores_view', 'colaboradores_detail']);
        // Ana: 1/3 na UN1 e 2/3 na UN2 (os valores não dão contas certas: a última parcela absorve o arredondamento)
        $this->putJson("/api/reparticao/alocacoes/colaborador/{$ids['ana']}", ['unidades_alocadas' => $this->alocacao($pct, ['UN1' => 1, 'UN2' => 2])], $rh)->assertOk();

        $p = $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => now()->format('m/Y')], $rh)->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/salarios/periodos/{$p}/importar-contratos", [], $rh)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$p}/encerrar", [], $rh)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$p}/validar", [], $rh)->assertOk();
        $lan = $this->postJson("/api/rh/salarios/periodos/{$p}/contabilizar", [], $rh)->assertOk()->json('dados.numero_lan_contabilizacao');

        // o lançamento fecha (verificado em linhas()) e o total de cada conta não muda com a repartição
        $porConta = [];
        $porUn = [];
        foreach ($this->linhas($lan) as $l) {
            [$dc, $conta, $valor, $un] = explode(' ', $l);
            $porConta["{$dc} {$conta}"] = bcadd($porConta["{$dc} {$conta}"] ?? '0.00', $valor, 2);
            $porUn["{$dc} {$conta} {$un}"] = $valor;
        }
        $this->assertSame('600000.00', $porConta['D 7211']);
        $this->assertSame('48000.00', $porConta['D 7221']);
        // Rui (sem alocação): tudo na unidade da ficha; Ana: 1/3 + 2/3, com a última parcela a fechar ao cêntimo
        $this->assertSame('300000.00', $porUn['D 7211 UN3']);
        $this->assertSame('100000.00', $porUn['D 7211 UN1']);
        $this->assertSame('200000.00', $porUn['D 7211 UN2']);
        $this->assertSame('8000.00', $porUn['D 7221 UN1']);
        $this->assertSame('16000.00', $porUn['D 7221 UN2']);
        $liquidoAna = bcadd($porUn['C 3611 UN1'], $porUn['C 3611 UN2'], 2);
        $this->assertSame($porUn['C 3611 UN3'], $liquidoAna);   // mesmo salário → mesmo líquido, agora repartido
        $this->assertSame(ServicoReparticao::arredondar(bcdiv($liquidoAna, '3', 12), 2), $porUn['C 3611 UN1']);
        $this->assertSame(bcadd($porUn['C 3421 UN1'], $porUn['C 3421 UN2'], 2), $porUn['C 3421 UN3']);   // IRT repartido pela mesma regra
    }
}
