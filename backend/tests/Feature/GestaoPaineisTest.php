<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\FaturaCompra;
use App\Models\LancamentoContabil;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\ResultadoFolhaSalarial;
use App\Models\Terceiro;
use App\Models\Utilizador;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Painéis, Análise Dinâmica e BI (ADR-059).
 *
 * Cenário (2026): venda 1 000 (6211 / 311), salários 300 (721 / 4311), recebimento 500 (4311 / 311), custo 100 (75 / 321),
 * classe 9 (50) e o apuramento de Março (período 13: 621 D 1 000, 721 C 300, 881 C 700), que não pode zerar o resultado.
 * Até Março: proveitos 1 000, custos 400, resultado 600, disponibilidades 200, clientes 500, fornecedores 100.
 * Documentos: FT Março 1 000 + IVA 140, NC Março 200 + IVA 28, FT anulada 999, FT Janeiro 400 + IVA 56, PF 300;
 * factura de fornecedor 500 + IVA 70 e uma anulada (1 000); 2 colaboradores activos e 1 inactivo; processamento 03/2026.
 */
final class GestaoPaineisTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->cenario($this->empresa->id);
    }

    private function cenario(int $empresa): void
    {
        app(ContextoEmpresa::class)->executarComo($empresa, function () {
            $diario = DiarioContabil::create(['codigo' => 'OD', 'descricao' => 'Operações diversas', 'nome' => 'Operações diversas'])->id;
            $cliente = Terceiro::create(['nif' => '5417000001', 'nome' => 'Cliente A', 'tipo' => 'CLIENTE'])->id;
            $l = fn (string $data, string $lan, array $linhas, array $extra = []) => array_map(fn ($x) => LancamentoContabil::create($extra + ['diario_id' => $diario,
                'data_documento' => $data, 'numero_lan' => $lan, 'numero_documento' => $lan, 'codigo_conta' => $x[0], 'tipo_dc' => $x[1], 'valor' => $x[2], 'terceiro_id' => $x[3] ?? null]), $linhas);
            $l('2026-01-15', 'OD2026000001', [['6211', 'C', 1000], ['311', 'D', 1000, $cliente]]);
            $l('2026-02-10', 'OD2026000002', [['721', 'D', 300], ['4311', 'C', 300]]);
            $l('2026-03-05', 'OD2026000003', [['4311', 'D', 500], ['311', 'C', 500, $cliente]]);
            $l('2026-03-20', 'OD2026000004', [['75', 'D', 100], ['321', 'C', 100]]);
            $l('2026-03-25', 'OD2026000005', [['91', 'D', 50], ['92', 'C', 50]]);
            $l('2026-03-31', 'AP2026000001', [['6211', 'D', 1000], ['721', 'C', 300], ['881', 'C', 700]], ['periodo_id' => 13]);

            $v = fn (string $tipo, string $num, string $data, $liq, $iva, ?string $estado = null) => Venda::create(['tipo_documento' => $tipo, 'numero_documento' => $num,
                'data_emissao' => "{$data} 10:00:00", 'total_liquido' => $liq, 'total_imposto' => $iva, 'total_bruto' => $liq + $iva, 'estado' => $estado, 'cliente_id' => $cliente]);
            $v('FT', 'FT 2026/1', '2026-03-10', 1000, 140);
            $v('NC', 'NC 2026/1', '2026-03-12', 200, 28);
            $v('FT', 'FT 2026/2', '2026-03-15', 999, 0, 'ANULADO');
            $v('FT', 'FT 2026/3', '2026-01-10', 400, 56, 'PAGO');
            $v('PF', 'PF 2026/1', '2026-03-01', 300, 42, 'PENDENTE');
            FaturaCompra::create(['numero_fatura' => 'FC 1', 'data' => '2026-03-02', 'montante_total' => 570, 'total_imposto' => 70, 'estado' => 'PENDENTE', 'contabilizado' => false]);
            FaturaCompra::create(['numero_fatura' => 'FC 2', 'data' => '2026-03-03', 'montante_total' => 1000, 'total_imposto' => 0, 'estado' => 'ANULADA', 'anulado_em' => now()]);

            $c1 = Colaborador::create(['nome_completo' => 'Colaborador Um', 'estado' => 'ACTIVO']);
            $c2 = Colaborador::create(['nome_completo' => 'Colaborador Dois', 'estado' => 'ACTIVO']);
            Colaborador::create(['nome_completo' => 'Colaborador Três', 'estado' => 'INACTIVO']);
            $p = PeriodoProcessamentoSalarial::create(['mes_ano' => '03/2026', 'estado' => 'VALIDADO']);
            PeriodoProcessamentoSalarial::create(['mes_ano' => '04/2026', 'estado' => 'ABERTO']);
            foreach ([[$c1, 100000, 3000, 8000, 10000, 87000], [$c2, 50000, 1500, 4000, 2000, 46500]] as [$c, $b, $it, $ie, $irt, $liq]) {
                ResultadoFolhaSalarial::create(['periodo_processamento_salarial_id' => $p->id, 'colaborador_id' => $c->id, 'bruto' => $b, 'inss_trabalhador' => $it,
                    'inss_patronal' => $ie, 'irt' => $irt, 'liquido' => $liq, 'descontos' => 0]);
            }
        });
    }

    private function sessao(array $permissoes, ?array $empresas = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        foreach ($empresas ?? [$this->empresa->id] as $e) {
            $u->empresas()->attach($e);
        }

        return $this->entrar($u) + ['X-Empresa-Id' => ($empresas ?? [$this->empresa->id])[0]];
    }

    private function total(): array
    {
        return $this->sessao(['all']);
    }

    private static function kpi(array $painel, string $id): mixed
    {
        $k = collect($painel['kpis'])->firstWhere('id', $id);

        return $k === null ? 'AUSENTE' : $k['valor'];
    }

    #[Test]
    public function inicio_mostra_empresa_dica_e_so_os_pendentes_permitidos(): void
    {
        $r = $this->getJson('/api/gestao/inicio', $this->total())->assertOk()->assertJsonStructure($this->envelope())->json('dados');
        $this->assertSame($this->empresa->nome, $r['empresa']['nome']);
        $this->assertSame('Pode usar o atalho CTRL+K para pesquisar rapidamente.', $r['dica_do_dia']);
        $this->assertContains($r['saudacao'], ['Bom dia', 'Boa tarde', 'Boa noite']);
        $pend = collect($r['pendentes'])->pluck('quantidade', 'id');
        $this->assertSame(1, $pend['vendas_por_liquidar']);   // FT de Março (a anulada e a paga não contam)
        $this->assertSame(1, $pend['rh_periodos_abertos']);
        $this->assertTrue(collect($r['modulos'])->every(fn ($m) => $m['autorizado']));

        // câmbios do BAI por validar (ronda 2): só para quem gere moedas
        DB::table('cambios_bai_pendentes')->insert(['data_cotacao' => now()->toDateString(), 'codigo_moeda' => 'USD', 'taxa_media' => 900, 'estado' => 'PENDENTE']);
        $this->assertSame(1, collect($this->getJson('/api/gestao/inicio', $this->total())->json('dados.pendentes'))->firstWhere('id', 'cambios_bai')['quantidade'] ?? null);
        $restrito = $this->getJson('/api/gestao/inicio', $this->sessao(['vendas_faturacao_view']))->assertOk()->json('dados');
        $this->assertSame(['vendas_por_liquidar'], collect($restrito['pendentes'])->pluck('id')->all());
        $this->assertFalse(collect($restrito['modulos'])->firstWhere('id', 'colaboradores')['autorizado']);
        $this->assertTrue(collect($restrito['modulos'])->firstWhere('id', 'vendas_faturacao')['autorizado']);
    }

    #[Test]
    public function lista_e_paineis_respeitam_as_permissoes_dos_modulos(): void
    {
        $this->getJson('/api/gestao/paineis', $this->sessao(['vendas_faturacao_view']))->assertForbidden()->assertJsonPath('codigo', 'SEM_PERMISSAO');

        $s = $this->sessao(['dashboard_view', 'vendas_faturacao_view']);
        $lista = $this->getJson('/api/gestao/paineis', $s)->assertOk()->json('dados.paineis');
        $this->assertSame(['geral', 'vendas', 'cubo'], array_column($lista, 'id'));
        $this->getJson('/api/gestao/paineis/rh?ano=2026&mes=3', $s)->assertForbidden()->assertJsonPath('codigo', 'SEM_PERMISSAO');

        // A Visão Geral só mostra os cartões dos painéis visíveis (vendas: vendas do mês e clientes a receber)
        $geral = $this->getJson('/api/gestao/paineis/geral?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame(['vendas_mes', 'clientes_receber'], array_column($geral['kpis'], 'id'));
        $this->assertSame(['vendas_compras'], array_column($geral['graficos'], 'id'));
    }

    #[Test]
    public function visao_geral_com_resultado_sem_apuramento_e_documentos_sem_iva(): void
    {
        $g = $this->getJson('/api/gestao/paineis/geral?ano=2026&mes=3', $this->total())->assertOk()->json('dados');
        $this->assertSame('800.00', self::kpi($g, 'vendas_mes'));      // 1 000 − 200 (NC), sem IVA; a FT anulada não conta
        $this->assertSame('500.00', self::kpi($g, 'compras_mes'));     // sem IVA; a anulada não conta
        $this->assertSame('600.00', self::kpi($g, 'resultado_ano'));   // 1 000 − 400, o apuramento não zera
        $this->assertSame('200.00', self::kpi($g, 'disponibilidades'));
        $this->assertSame('500.00', self::kpi($g, 'clientes_receber'));
        $this->assertSame('100.00', self::kpi($g, 'fornecedores_pagar'));
        $this->assertSame(2, self::kpi($g, 'colaboradores_activos'));
        $vc = collect($g['graficos'])->firstWhere('id', 'vendas_compras');
        $this->assertCount(12, $vc['rotulos']);
        $this->assertSame('Mar/26', $vc['rotulos'][11]);
        $this->assertSame('400.00', $vc['series'][0]['valores'][9]);   // Janeiro
        $this->assertSame(['#2563eb', '#ea580c'], array_column($vc['series'], 'cor'));   // cores do legado (vendas, compras)
        $this->assertSame(2026, $g['periodo']['ano']);

        $comIva = $this->getJson('/api/gestao/paineis/geral?ano=2026&mes=3&iva=com&actualizar=1', $this->total())->assertOk()->json('dados');
        $this->assertSame('912.00', self::kpi($comIva, 'vendas_mes'));   // 1 140 − 228 (paridade com o legado)
        $this->assertSame('570.00', self::kpi($comIva, 'compras_mes'));
    }

    #[Test]
    public function paineis_de_vendas_compras_tesouraria_contabilidade_e_rh(): void
    {
        $s = $this->total();
        $v = $this->getJson('/api/gestao/paineis/vendas?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame('800.00', self::kpi($v, 'faturacao_mes'));
        $this->assertSame('1200.00', self::kpi($v, 'faturacao_ano'));
        $this->assertSame(1, self::kpi($v, 'faturas_mes'));
        $this->assertSame('1000.00', self::kpi($v, 'valor_medio_fatura'));
        $this->assertSame('200.00', self::kpi($v, 'notas_credito_mes'));
        $this->assertSame(1, self::kpi($v, 'por_converter'));
        $this->assertSame('1200.00', collect($v['tabelas'])->firstWhere('id', 'top_clientes')['linhas'][0]['total']);

        $c = $this->getJson('/api/gestao/paineis/compras?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame('500.00', self::kpi($c, 'compras_ano'));
        $this->assertSame(1, self::kpi($c, 'faturas_por_contabilizar'));

        $t = $this->getJson('/api/gestao/paineis/tesouraria?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame('200.00', self::kpi($t, 'saldo_bancos'));
        $this->assertSame('200.00', end(collect($t['graficos'])->firstWhere('id', 'evolucao_saldo')['series'][0]['valores']));

        $ct = $this->getJson('/api/gestao/paineis/contabilidade?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame('1000.00', self::kpi($ct, 'proveitos_ano'));
        $this->assertSame('400.00', self::kpi($ct, 'custos_ano'));
        $this->assertSame(60.0, self::kpi($ct, 'margem'));
        $this->assertSame(4, self::kpi($ct, 'lancamentos_mes'));   // OD3, OD4, OD5 e o apuramento
        $this->assertArrayHasKey('resultado_liquido_dr', collect($ct['kpis'])->keyBy('id')->all());
        $classes = collect(collect($ct['tabelas'])->firstWhere('id', 'balancete_classes')['linhas'])->keyBy('classe');
        $this->assertSame(['1000.00', '600.00', '400.00'], [$classes['3']['debito'], $classes['3']['credito'], $classes['3']['saldo_devedor']]);
        $this->assertSame('1000.00', $classes['6']['saldo_credor']);   // regras dos mapas: sem o apuramento do ano
        $this->assertFalse($classes->has('8'));

        $rh = $this->getJson('/api/gestao/paineis/rh?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame('150000.00', self::kpi($rh, 'iliquido'));
        $this->assertSame('162000.00', self::kpi($rh, 'custo_total'));   // ilíquido + INSS empresa
        $this->assertSame('133500.00', self::kpi($rh, 'liquido'));
        $this->assertSame(1, self::kpi($rh, 'periodos_abertos'));
        $this->assertStringContainsString('03/2026', $rh['aviso']);
    }

    #[Test]
    public function todos_os_paineis_respondem_com_a_forma_comum(): void
    {
        $s = $this->total();
        foreach (['armazem', 'ativos', 'projetos', 'orcamento', 'crm', 'acrescimos', 'estrutura'] as $m) {
            $this->getJson("/api/gestao/paineis/{$m}?ano=2026&mes=3", $s)->assertOk()
                ->assertJsonStructure(['dados' => ['modulo' => ['id', 'nome'], 'empresa', 'periodo' => ['meses'], 'filtros', 'filtros_ignorados', 'aviso', 'kpis', 'graficos', 'tabelas', 'atalhos']]);
        }
        $this->getJson('/api/gestao/paineis/crm?ano=2026&mes=3', $s)->assertJsonPath('dados.aviso', 'O CRM ainda não tem funis de vendas configurados.');
        $this->getJson('/api/gestao/paineis/armazem?unidade_negocio_id=5', $s)->assertJsonPath('dados.filtros_ignorados', ['unidade_negocio_id']);
        $this->getJson('/api/gestao/paineis/cubo', $s)->assertStatus(422)->assertJsonPath('codigo', 'PAINEL_INVALIDO');
        $this->getJson('/api/gestao/paineis/grupo', $s)->assertForbidden();   // só na holding
        $this->getJson('/api/gestao/paineis/geral?mes=13', $s)->assertStatus(422);
    }

    #[Test]
    public function holding_compara_as_empresas_do_grupo_e_agrega_as_sem_acesso(): void
    {
        $holding = $this->criarEmpresa(['e_consolidacao' => true]);
        $m2 = $this->criarEmpresa();
        $grupo = DB::table('grupos_consolidacao')->insertGetId(['empresa_id' => $holding->id, 'empresa_holding_id' => $holding->id, 'nome' => 'Grupo de teste']);
        foreach ([$this->empresa->id, $m2->id] as $m) {
            DB::table('membros_consolidacao')->insert(['empresa_id' => $holding->id, 'grupo_consolidacao_id' => $grupo, 'empresa_membro_id' => $m, 'percentagem' => 100, 'metodo' => 'INTEGRAL']);
        }
        app(ContextoEmpresa::class)->executarComo($holding->id, function () use ($m2) {
            $l = fn (string $conta, string $dc, $valor, ?int $origem, ?string $tipo = 'AGREGACAO') => LancamentoContabil::create(['data_documento' => '2026-03-10', 'numero_lan' => 'CONS1',
                'codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => $valor, 'empresa_origem_id' => $origem, 'tipo_consolidacao' => $tipo]);
            $l('6211', 'C', 1000, $this->empresa->id);
            $l('311', 'D', 1000, $this->empresa->id);
            $l('6211', 'C', 700, $m2->id);
            $l('4311', 'D', 700, $m2->id);
            $l('6211', 'D', 100, $this->empresa->id, 'ELIMINACAO');
            $l('321', 'C', 100, $this->empresa->id, 'ELIMINACAO');
        });
        $s = $this->sessao(['dashboard_view', 'lancamentos_view', 'vendas_faturacao_view'], [$holding->id, $this->empresa->id]);

        $lista = $this->getJson('/api/gestao/paineis', $s)->assertOk()->json('dados');
        $this->assertTrue($lista['holding']);
        $this->assertSame(['grupo', 'vendas', 'contabilidade', 'cubo'], array_column($lista['paineis'], 'id'));

        $g = $this->getJson('/api/gestao/paineis/grupo?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame([$this->empresa->id], array_column($g['empresas'], 'empresa_id'));   // a M2 não é identificada
        $this->assertSame('1600.00', self::kpi($g, 'proveitos'));   // 1 000 + 700 − 100 (eliminação)
        $this->assertSame('100.00', self::kpi($g, 'eliminacoes'));
        $quadro = collect(collect($g['tabelas'])->firstWhere('id', 'quadro_comparativo')['linhas'])->keyBy('chave');
        $this->assertSame('700.00', $quadro['SEM_ACESSO']['proveitos']);
        $this->assertSame('Outras empresas (sem acesso)', $quadro['SEM_ACESSO']['nome']);
        $this->assertSame('1600.00', $quadro['TOTAL']['proveitos']);
        $this->assertStringContainsString('ainda não foi consolidada', $g['aviso']);

        // Contabilidade na holding: consolidado + a empresa acessível (antes das eliminações)
        $ct = $this->getJson('/api/gestao/paineis/contabilidade?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame('1600.00', self::kpi($ct, 'proveitos_ano'));
        $this->assertSame('1000.00', $ct['por_empresa'][0]['kpis'][0]['valor']);
        // Vendas na holding: soma das empresas do grupo acessíveis
        $v = $this->getJson('/api/gestao/paineis/vendas?ano=2026&mes=3', $s)->assertOk()->json('dados');
        $this->assertSame('800.00', self::kpi($v, 'faturacao_mes'));
        $this->assertNull(self::kpi($v, 'valor_medio_fatura'));
    }

    #[Test]
    public function comparacao_livre_so_com_empresas_acessiveis(): void
    {
        $outra = $this->criarEmpresa();
        $semAcesso = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($outra->id, fn () => LancamentoContabil::create(['data_documento' => '2026-02-01', 'codigo_conta' => '6211', 'tipo_dc' => 'C', 'valor' => 250]));
        $soPainel = $this->sessao(['dashboard_view'], [$this->empresa->id, $outra->id]);
        $this->getJson('/api/gestao/paineis/comparacao?ano=2026&mes=3', $soPainel)->assertForbidden();
        $s = $this->sessao(['dashboard_view', 'lancamentos_view'], [$this->empresa->id, $outra->id]);
        $r = $this->getJson('/api/gestao/paineis/comparacao?ano=2026&mes=3&empresas[]='.$this->empresa->id.'&empresas[]='.$outra->id, $s)->assertOk()->json('dados');
        $quadro = collect(collect($r['tabelas'])->firstWhere('id', 'quadro_comparativo')['linhas'])->keyBy('chave');
        $this->assertSame('600.00', $quadro[(string) $this->empresa->id]['resultado']);
        $this->assertSame('250.00', $quadro[(string) $outra->id]['proveitos']);
        $this->assertSame('1250.00', $quadro['TOTAL']['proveitos']);
        $this->getJson('/api/gestao/paineis/comparacao?empresas[]='.$semAcesso->id, $s)->assertForbidden()->assertJsonPath('codigo', 'EMPRESA_SEM_ACESSO');
    }

    #[Test]
    public function comparacao_em_cache_deixa_de_ser_vista_logo_que_o_acesso_e_retirado(): void
    {
        $outra = $this->criarEmpresa();
        $s = $this->sessao(['dashboard_view', 'lancamentos_view'], [$this->empresa->id, $outra->id]);
        $url = '/api/gestao/paineis/comparacao?ano=2026&mes=3&empresas[]='.$this->empresa->id.'&empresas[]='.$outra->id;
        $this->getJson($url, $s)->assertOk();
        $this->getJson($url, $s)->assertOk();   // 2.º pedido servido pela cache (gestao:comparacao:*)

        // retirar o acesso à segunda empresa (o pivot invalida a lista de empresas acessíveis do utilizador)
        Utilizador::query()->latest('id')->firstOrFail()->empresas()->detach($outra->id);

        // a entrada da comparação continua em cache, mas a autorização é verificada antes de a ler
        $this->getJson($url, $s)->assertForbidden()->assertJsonPath('codigo', 'EMPRESA_SEM_ACESSO')->assertJsonPath('erros.empresas', [$outra->id]);
        $this->getJson('/api/gestao/paineis/comparacao?ano=2026&mes=3&empresas[]='.$this->empresa->id, $s)->assertOk();
    }

    #[Test]
    public function cubo_agrega_no_servidor_com_totais_e_filtros(): void
    {
        $s = $this->total();
        $conj = $this->getJson('/api/gestao/cubo/conjuntos', $s)->assertOk()->json('dados');
        $this->assertSame(['contabilidade', 'vendas', 'compras', 'tesouraria', 'armazem', 'rh', 'projetos', 'ativos'], array_column($conj, 'id'));

        $pedido = ['conjunto' => 'contabilidade', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-03-31', 'linhas' => ['classe'], 'colunas' => ['mes'],
            'medidas' => [['medida' => 'saldo', 'agregacao' => 'soma'], ['agregacao' => 'contagem']]];
        $r = $this->postJson('/api/gestao/cubo/consultar', $pedido, $s)->assertOk()->json('dados');
        $this->assertSame([['01 - Jan'], ['02 - Fev'], ['03 - Mar']], $r['chaves_colunas']);
        $linhas = collect($r['resultado'])->keyBy(fn ($l) => $l['chave'][0]);
        $this->assertSame(['3', '4', '6', '7'], array_map('strval', $linhas->keys()->all()));   // classe 9 excluída
        $this->assertSame(['400.00', 3], $linhas['3']['total']);
        $this->assertSame(['1000.00', 1], $linhas['3']['valores'][0]);
        $this->assertNull($linhas['3']['valores'][1]);
        $this->assertSame('-1000.00', $linhas['6']['total'][0]);   // sem o apuramento
        $this->assertSame(['0.00', 8], $r['total_geral']);
        $this->assertSame('0.00', $r['totais_colunas'][2][0]);   // Março: 500 − 500 − 100 + 100

        $com = $this->postJson('/api/gestao/cubo/consultar', $pedido + ['incluir_apuramento' => true], $s)->assertOk()->json('dados');
        $this->assertSame('0.00', collect($com['resultado'])->firstWhere('chave', ['6'])['total'][0]);

        $filtrado = $this->postJson('/api/gestao/cubo/consultar', array_merge($pedido, ['filtros' => ['classe' => ['6', '7']], 'colunas' => []]), $s)->assertOk()->json('dados');
        $this->assertSame(['-600.00', 3], $filtrado['total_geral']);

        $vendas = $this->postJson('/api/gestao/cubo/consultar', ['conjunto' => 'vendas', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-03-31',
            'linhas' => ['tipo_documento'], 'medidas' => [['medida' => 'valor_sem_iva', 'agregacao' => 'soma'], ['medida' => 'total', 'agregacao' => 'soma']]], $s)->assertOk()->json('dados');
        $porTipo = collect($vendas['resultado'])->mapWithKeys(fn ($l) => [$l['chave'][0] => $l['total']]);
        $this->assertSame(['1400.00', '1596.00'], $porTipo['FT']);   // a anulada não conta
        $this->assertSame(['-200.00', '-228.00'], $porTipo['NC']);

        // Todos os conjuntos respondem com a visão padrão e com só colunas
        foreach ($conj as $c) {
            $base = ['conjunto' => $c['id'], 'data_inicio' => '2026-01-01', 'data_fim' => '2026-03-31'];
            $this->postJson('/api/gestao/cubo/consultar', $base + $c['padrao'], $s)->assertOk()->assertJsonStructure(['dados' => ['chaves_colunas', 'resultado', 'totais_colunas', 'total_geral']]);
            $this->postJson('/api/gestao/cubo/consultar', $base + ['colunas' => ['ano'], 'medidas' => [['agregacao' => 'contagem']]], $s)->assertOk();
        }
        $rh = $this->postJson('/api/gestao/cubo/consultar', ['conjunto' => 'rh', 'data_inicio' => '2026-03-15', 'data_fim' => '2026-03-31',
            'medidas' => [['medida' => 'custo_empresa', 'agregacao' => 'soma'], ['medida' => 'iliquido', 'agregacao' => 'media']]], $s)->assertOk()->json('dados');
        $this->assertSame(['162000.00', '75000.00'], $rh['total_geral']);
        $compras = $this->postJson('/api/gestao/cubo/consultar', ['conjunto' => 'compras', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-03-31',
            'medidas' => [['medida' => 'valor_sem_iva', 'agregacao' => 'soma'], ['medida' => 'total', 'agregacao' => 'soma']]], $s)->assertOk()->json('dados');
        $this->assertSame(['500.00', '570.00'], $compras['total_geral']);

        $valores = $this->getJson('/api/gestao/cubo/valores?conjunto=contabilidade&dimensao=conta&data_inicio=2026-01-01&data_fim=2026-03-31&pesquisa=43', $s)->assertOk()->json('dados');
        $this->assertSame([['valor' => '4311', 'linhas' => 2]], $valores);
    }

    #[Test]
    public function cubo_recusa_dimensoes_fora_da_lista_branca_e_conjuntos_sem_permissao(): void
    {
        $s = $this->sessao(['dashboard_view', 'vendas_faturacao_view']);
        $this->assertSame(['vendas'], array_column($this->getJson('/api/gestao/cubo/conjuntos', $s)->assertOk()->json('dados'), 'id'));
        $base = ['data_inicio' => '2026-01-01', 'data_fim' => '2026-03-31'];
        $this->postJson('/api/gestao/cubo/consultar', $base + ['conjunto' => 'contabilidade', 'linhas' => ['classe']], $s)->assertForbidden();
        $this->postJson('/api/gestao/cubo/consultar', $base + ['conjunto' => 'vendas', 'linhas' => ['cliente); DROP TABLE vendas; --']], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CUBO_PEDIDO_INVALIDO');
        $this->postJson('/api/gestao/cubo/consultar', $base + ['conjunto' => 'vendas', 'medidas' => [['medida' => 'total', 'agregacao' => 'string_agg']]], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CUBO_PEDIDO_INVALIDO');
        $this->postJson('/api/gestao/cubo/consultar', $base + ['conjunto' => 'vendas', 'filtros' => ['1=1' => ['x']]], $s)->assertStatus(422);
        $r = $this->postJson('/api/gestao/cubo/consultar', $base + ['conjunto' => 'vendas', 'linhas' => ['cliente'], 'filtros' => ['cliente' => ["Cliente A' OR '1'='1"]]], $s)->assertOk()->json('dados');
        $this->assertSame([], $r['resultado']);   // o valor do filtro vai por parâmetro
        $this->postJson('/api/gestao/cubo/consultar', ['conjunto' => 'vendas'], $s)->assertStatus(422);
    }

    #[Test]
    public function bi_contabilistico_com_a_visao_padrao_do_legado(): void
    {
        $this->getJson('/api/gestao/bi', $this->sessao(['dashboard_view']))->assertForbidden();
        $s = $this->sessao(['accounting_bi_view']);
        $meta = $this->getJson('/api/gestao/bi', $s)->assertOk()->json('dados');
        $this->assertSame(['conta'], $meta['padrao']['linhas']);
        $this->assertSame(['todo', 'mes_atual', 'ano_atual'], array_column($meta['periodos'], 'id'));
        $r = $this->postJson('/api/gestao/bi/consultar', ['periodo' => 'todo'], $s)->assertOk()->json('dados');
        $this->assertSame([['01 - Janeiro'], ['02 - Fevereiro'], ['03 - Março']], $r['chaves_colunas']);
        $this->assertSame('-1000.00', collect($r['resultado'])->firstWhere('chave', ['6211'])['total'][0]);
        $this->assertSame(['0.00'], $r['total_geral']);
        $diario = $this->postJson('/api/gestao/bi/consultar', ['periodo' => 'todo', 'linhas' => ['diario'], 'colunas' => []], $s)->assertOk()->json('dados');
        $this->assertSame([['Operações diversas']], array_column($diario['resultado'], 'chave'));
    }
}
