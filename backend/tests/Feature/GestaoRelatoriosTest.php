<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\DiarioContabil;
use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\EstadiaHotel;
use App\Models\ItemVenda;
use App\Models\LancamentoContabil;
use App\Models\PedidoLavandaria;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\Produto;
use App\Models\ResultadoFolhaSalarial;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Gestao\Relatorios\PeriodosGestao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Relatórios de gestão com comparação de períodos (ADR-060, relatorios_gestao.js).
 *
 * Cenário: Março 2026 (A) e Março 2025 (B, homólogo).
 *   - Diário 2026: vendas 61 1 000, CMV 71 400, pessoal 72 100, amortizações 73 50, banco 43 +600; classe 9 (77); apuramento
 *     do período 13 (AP-O: 61 D 1 000) fora da DR; linha de salários com o id de período 13 (SAL: 72 D 30), que conta.
 *   - Diário 2025: vendas 61 500.
 *   - Vendas 2026: FT 1 000 (+140), FR 500 (+70, custo gravado 2 × 100), NC 200 (+28), FT anulada 999.
 *   - Hotelaria: duas estadias à diária (2 + 1 noites) com factura única (quarto 3 × 10 000 + consumo 5 000, sem IVA).
 */
final class GestaoRelatoriosTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'relatorios_gestao_view' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->h = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => $this->cenario());
    }

    private function cenario(): void
    {
        $od = DiarioContabil::create(['codigo' => 'OD', 'descricao' => 'Operações diversas', 'nome' => 'Operações diversas'])->id;
        $ap = DiarioContabil::create(['codigo' => 'AP-O', 'descricao' => 'Apuramento', 'nome' => 'Apuramento'])->id;
        $sal = DiarioContabil::create(['codigo' => 'SAL', 'descricao' => 'Salários', 'nome' => 'Salários'])->id;
        $l = fn (int $diario, string $data, array $linhas, array $extra = []) => array_map(fn ($x) => LancamentoContabil::create($extra + ['diario_id' => $diario,
            'data_documento' => $data, 'numero_documento' => 'D1', 'codigo_conta' => $x[0], 'tipo_dc' => $x[1], 'valor' => $x[2]]), $linhas);
        $l($od, '2026-03-05', [['611', 'C', 1000], ['43101', 'D', 600], ['311', 'D', 400]]);
        $l($od, '2026-03-06', [['711', 'D', 400], ['721', 'D', 100], ['731', 'D', 50], ['261', 'C', 400], ['4311', 'C', 150]]);
        $l($od, '2026-03-07', [['91', 'D', 77], ['92', 'C', 77]]);
        $l($ap, '2026-03-31', [['611', 'D', 1000], ['881', 'C', 1000]], ['periodo_id' => 13]);
        $l($sal, '2026-03-31', [['721', 'D', 30], ['362', 'C', 30]], ['periodo_id' => 13]);
        $l($od, '2025-03-10', [['611', 'C', 500], ['43101', 'D', 500]]);

        $cliente = Terceiro::create(['nif' => '5417000009', 'nome' => 'Cliente', 'tipo' => 'CLIENTE', 'codigo_conta' => '311'])->id;
        $p = Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 250, 'taxa_imposto' => 14, 'movimenta_stock' => true, 'custo_medio' => 120])->id;
        $v = fn (string $tipo, string $num, $liq, $iva, ?string $estado = null) => Venda::create(['tipo_documento' => $tipo, 'numero_documento' => $num, 'data_emissao' => '2026-03-10 10:00:00',
            'total_liquido' => $liq, 'total_imposto' => $iva, 'total_bruto' => $liq + $iva, 'estado' => $estado, 'cliente_id' => $cliente]);
        $v('FT', 'FT 1', 1000, 140);
        $fr = $v('FR', 'FR 1', 500, 70, 'PAGO');
        ItemVenda::create(['venda_id' => $fr->id, 'produto_id' => $p, 'quantidade' => 2, 'preco_unitario' => 250, 'total_linha' => 500, 'total' => 570, 'custo_unitario_kz' => 100, 'quantidade_stock' => 2]);
        $v('NC', 'NC 1', 200, 28);
        $v('FT', 'FT 2', 999, 0, 'ANULADO');

        $quarto = Produto::create(['codigo' => 'Q1', 'nome' => 'Quarto 1', 'preco_unitario' => 10000, 'taxa_imposto' => 0, 'e_quarto' => true])->id;
        Produto::create(['codigo' => 'Q2', 'nome' => 'Quarto 2', 'preco_unitario' => 10000, 'taxa_imposto' => 0, 'e_quarto' => true]);
        $bebida = Produto::create(['codigo' => 'B1', 'nome' => 'Bebida', 'preco_unitario' => 5000, 'taxa_imposto' => 0])->id;
        $fh = Venda::create(['tipo_documento' => 'FR', 'numero_documento' => 'FR 2', 'data_emissao' => '2026-03-20 12:00:00', 'total_liquido' => 35000, 'total_imposto' => 0, 'total_bruto' => 35000,
            'estado' => 'PAGO', 'cliente_id' => $cliente]);
        ItemVenda::create(['venda_id' => $fh->id, 'produto_id' => $quarto, 'quantidade' => 3, 'preco_unitario' => 10000, 'total_linha' => 30000, 'total' => 30000]);
        ItemVenda::create(['venda_id' => $fh->id, 'produto_id' => $bebida, 'quantidade' => 1, 'preco_unitario' => 5000, 'total_linha' => 5000, 'total' => 5000]);
        foreach ([2, 1] as $n) {
            $e = EstadiaHotel::create(['produto_quarto_id' => $quarto, 'nome_quarto' => 'Quarto 1', 'estado' => EstadiaHotel::FECHADA, 'modo' => 'DIA', 'entrada_em' => '2026-03-18 14:00:00',
                'quantidade' => $n, 'quantidade_final' => $n, 'preco_unitario' => 10000, 'venda_id' => $fh->id]);
            DB::table('vendas_estadias_hotel')->insert(['empresa_id' => $this->empresa->id, 'venda_id' => $fh->id, 'estadia_hotel_id' => $e->id]);
        }
        PedidoLavandaria::create(['numero_encomenda' => 'OS/1', 'recebido_em' => '2026-03-02 09:00:00', 'data_prometida' => '2026-03-05 18:00:00', 'entregue_em' => '2026-03-04 10:00:00',
            'estado' => 'ENTREGUE', 'itens' => [['linha_id' => 1, 'estado' => 'ENTREGUE', 'quantidade' => 2, 'preco' => 1500, 'valor' => '3000.00']], 'extras' => [['id' => 'x', 'valor' => 500, 'cancelado' => false]]]);
    }

    private function kpis(array $dados): array
    {
        return collect($dados['kpis'])->keyBy('chave')->all();
    }

    #[Test]
    public function periodos_presets_e_comparacao_como_no_legado(): void
    {
        $this->assertSame(['inicio' => '2026-01-01', 'fim' => '2026-03-31'], array_intersect_key(PeriodosGestao::preset('trimestre', '2026-02-10'), ['inicio' => 1, 'fim' => 1]));
        $this->assertSame('2025-10-01', PeriodosGestao::preset('trimestre_anterior', '2026-02-10')['inicio']);
        $this->assertSame('2025-12-31', PeriodosGestao::preset('mes_anterior', '2026-01-15')['fim']);
        $h = PeriodosGestao::comparacao('homologo', ['inicio' => '2024-02-01', 'fim' => '2024-02-29']);
        $this->assertSame(['2023-02-01', '2023-02-28'], [$h['inicio'], $h['fim']]);
        $a = PeriodosGestao::comparacao('anterior', ['inicio' => '2026-01-01', 'fim' => '2026-03-31']);
        $this->assertSame(['2025-10-01', '2025-12-31'], [$a['inicio'], $a['fim']]);
        $d = PeriodosGestao::comparacao('anterior', ['inicio' => '2026-03-10', 'fim' => '2026-03-19']);
        $this->assertSame(['2026-02-28', '2026-03-09'], [$d['inicio'], $d['fim']]);

        $r = $this->getJson('/api/gestao/relatorios/periodos?preset_a=ytd&referencia=2026-05-20&comparacao=anterior', $this->h)->assertOk();
        $this->assertSame('2026-01-01', $r->json('dados.a.inicio'));
        $this->assertSame(140, $r->json('dados.a.dias'));
        $this->assertTrue($r->json('dados.duracao_diferente') === false);
        $this->getJson('/api/gestao/relatorios/periodos?a_inicio=2026-03-10&a_fim=2026-03-01', $this->h)->assertStatus(422);
        $this->getJson('/api/gestao/relatorios/periodos?comparacao=livre', $this->h)->assertStatus(422);
    }

    #[Test]
    public function exige_a_consulta_do_ecra_e_lista_os_nove_modulos(): void
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'vendas_faturacao_view' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->getJson('/api/gestao/relatorios', $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id])->assertForbidden();
        $r = $this->getJson('/api/gestao/relatorios', $this->h)->assertOk()->assertJsonStructure($this->envelope());
        $this->assertSame(['financas', 'vendas', 'compras', 'tesouraria', 'stock', 'rh', 'activos', 'projectos', 'servicos'], array_column($r->json('dados.modulos'), 'id'));
        $this->getJson('/api/gestao/relatorios/inexistente', $this->h)->assertNotFound();
    }

    #[Test]
    public function financas_exclui_classe_9_e_apuramento_mas_nao_os_salarios_e_compara_com_o_homologo(): void
    {
        $r = $this->getJson('/api/gestao/relatorios/financas?a_inicio=2026-03-01&a_fim=2026-03-31&comparacao=homologo', $this->h)->assertOk();
        $k = $this->kpis($r->json('dados'));
        $this->assertSame('1000.00', $k['vn']['a']);
        $this->assertSame('500.00', $k['vn']['b']);
        $this->assertSame(['abs' => '500.00', 'pct' => 100.0], $k['vn']['variacao']);
        $this->assertSame('boa', $k['vn']['leitura']);
        $this->assertSame('600.00', $k['margem_bruta']['a']);
        $this->assertSame('580.00', $k['custos']['a']);
        $this->assertSame('420.00', $k['resultado']['a']);
        $this->assertSame('470.00', $k['ebitda']['a']);
        $this->assertSame('420.00', $k['ebit']['a']);
        $this->assertSame(60.0, $k['margem_bruta_pct']['a']);
        $this->assertSame('950.00', $k['disponib']['a']);
        $this->assertSame('400.00', $k['clientes']['a']);
        $this->assertSame('ma', $k['custos']['leitura']);
        $this->assertSame(0.0, $k['pmr']['b']);
        $dr = collect($r->json('dados.tabelas.0.linhas'))->keyBy('rubrica');
        $this->assertSame('-400.00', $dr['CMVMC (71)']['valor']);
        $this->assertSame('500.00', $dr['Vendas (61)']['b']);
        $this->assertSame(['1000.00'], $r->json('dados.graficos.0.series.0.a'));
        $this->assertSame(['500.00'], $r->json('dados.graficos.0.series.0.b'));
    }

    #[Test]
    public function vendas_abate_notas_de_credito_ignora_anulados_e_usa_o_custo_gravado(): void
    {
        $r = $this->getJson('/api/gestao/relatorios/vendas?a_inicio=2026-03-01&a_fim=2026-03-31&comparacao=nenhum', $this->h)->assertOk();
        $k = $this->kpis($r->json('dados'));
        $this->assertSame('36300.00', $k['liq']['a']);
        $this->assertSame(3, $k['n_fact']['a']);
        $this->assertSame('36100.00', $k['margem']['a']);
        $this->assertSame('35570.00', $k['recebimentos']['a']);
        $this->assertSame(round(200 / 36500 * 100, 4), $k['nc_pct']['a']);
        $top = collect($r->json('dados.tabelas'))->firstWhere('id', 'top_produtos')['linhas'];
        $this->assertSame(['nome' => 'Quarto 1', 'qtd' => 3.0, 'valor' => '30000.00'], $top[0]);
        $this->assertNull($k['margem']['b']);
        $this->assertSame('neutra', $k['liq']['leitura']);
    }

    #[Test]
    public function servicos_conta_cada_factura_de_hotel_uma_vez_e_so_o_alojamento(): void
    {
        $r = $this->getJson('/api/gestao/relatorios/servicos?a_inicio=2026-03-01&a_fim=2026-03-31&comparacao=nenhum', $this->h)->assertOk();
        $k = $this->kpis($r->json('dados'));
        $this->assertSame(2, $k['hot_estadias']['a']);
        $this->assertSame('10000.00', $k['hot_adr']['a']);
        $this->assertSame(round(3 / (2 * 31) * 100, 4), $k['hot_ocupacao']['a']);
        $this->assertSame(1, $k['lav_ordens']['a']);
        $this->assertSame('3500.00', $k['lav_valor']['a']);
        $this->assertSame(100.0, $k['lav_prazo']['a']);
        $this->assertSame('0.00', $k['pos_vendas']['a']);
    }

    #[Test]
    public function tesouraria_e_rh_pela_fotografia_dos_processamentos(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $org = DB::table('tipos_organizacao_rh')->insertGetId(['empresa_id' => $this->empresa->id, 'nome' => 'Sede']);
            $a = Colaborador::create(['nome_completo' => 'A', 'estado' => 'ACTIVO', 'sexo' => 'F', 'tipo_organizacao_id' => $org, 'data_admissao' => '2026-03-02'])->id;
            Colaborador::create(['nome_completo' => 'B', 'estado' => 'ACTIVO', 'sexo' => 'M']);
            Colaborador::create(['nome_completo' => 'C', 'estado' => 'INACTIVO', 'sexo' => 'F']);
            ContratoTrabalho::create(['colaborador_id' => $a, 'data_inicio' => '2026-01-01', 'estado' => 'ACTIVO', 'horas_por_dia' => 8, 'dias_contrato_mes' => 22]);
            $p = PeriodoProcessamentoSalarial::create(['mes_ano' => '03/2026', 'estado' => 'FECHADO', 'contabilizado' => false])->id;
            PeriodoProcessamentoSalarial::create(['mes_ano' => '04/2026', 'estado' => 'ABERTO', 'contabilizado' => false]);
            ResultadoFolhaSalarial::create(['periodo_processamento_salarial_id' => $p, 'colaborador_id' => $a, 'bruto' => 1000, 'liquido' => 920, 'inss_trabalhador' => 30, 'inss_patronal' => 80,
                'irt' => 50, 'dias_contrato' => 22, 'avencado' => false, 'rubricas' => [['nome' => 'Salário Base', 'tipo' => 'VENCIMENTO', 'valor' => '900.00'],
                    ['nome' => 'H. Extras', 'tipo' => 'VENCIMENTO', 'valor' => '100.00', 'horas' => '5'], ['nome' => 'Desc. Falta', 'tipo' => 'DESCONTO', 'valor' => '40.00', 'horas' => '2', 'falta' => true]]]);
            DocumentoTesouraria::create(['tipo' => 'PAGAMENTO', 'data_documento' => '2026-03-15', 'conta_financeira' => '43101', 'valor_total' => 10, 'estado' => 'PENDENTE']);
        });
        $t = $this->kpis($this->getJson('/api/gestao/relatorios/tesouraria?a_inicio=2026-03-01&a_fim=2026-03-31&comparacao=nenhum', $this->h)->assertOk()->json('dados'));
        $this->assertSame(['600.00', '150.00', '450.00', '500.00', '950.00', 1], [$t['entradas']['a'], $t['saidas']['a'], $t['fluxo']['a'], $t['saldo_ini']['a'], $t['saldo_fim']['a'], $t['docs_pend']['a']]);
        $this->assertSame(round(950 / (150 / (31 / 30.4375)), 4), $t['cobertura']['a']);
        $r = $this->getJson('/api/gestao/relatorios/rh?a_inicio=2026-03-01&a_fim=2026-03-31&comparacao=nenhum', $this->h)->assertOk()->json('dados');
        $k = $this->kpis($r);
        $this->assertSame([2, 1, 1], [$k['efectivo']['a'], $k['admissoes']['a'], $k['processados']['a']]);
        $this->assertSame(['1000.00', '1080.00', '920.00', '160.00', '100.00', '40.00'], [$k['massa']['a'], $k['custo']['a'], $k['liquido']['a'], $k['encargos']['a'], $k['hextra']['a'], $k['faltas']['a']]);
        $this->assertSame([5.0, 10.0, 50.0], [$k['hextra_h']['a'], $k['hextra_pct']['a'], $k['mulheres']['a']]);
        $this->assertSame(round(2 / 176 * 100, 4), $k['absentismo']['a']);
        $this->assertSame([['mes' => '03/2026', 'custo' => '1080.00']], $r['tabelas'][0]['linhas']);
        $this->assertSame([], $r['notas']);
    }

    #[Test]
    public function resumo_mostra_os_indicadores_chave_e_as_variacoes_relevantes(): void
    {
        $r = $this->getJson('/api/gestao/relatorios/resumo?a_inicio=2026-03-01&a_fim=2026-03-31&comparacao=homologo', $this->h)->assertOk();
        $fin = collect($r->json('dados.blocos'))->firstWhere('modulo.id', 'financas');
        $this->assertSame(['vn', 'ebitda', 'resultado', 'margem_liq', 'disponib'], array_column($fin['kpis'], 'chave'));
        $destaques = collect($r->json('dados.destaques'));
        $this->assertTrue($destaques->contains(fn ($d) => $d['chave'] === 'vn' && $d['modulo_id'] === 'financas'));
        $this->assertFalse($destaques->contains(fn ($d) => $d['sentido'] === 'neutro' || $d['actual']));
        $t = $this->getJson('/api/gestao/relatorios/todos?a_inicio=2026-03-01&a_fim=2026-03-31&comparacao=nenhum', $this->h)->assertOk();
        $this->assertCount(9, $t->json('dados.modulos'));
        $this->assertSame([], $t->json('dados.destaques'));
    }
}
