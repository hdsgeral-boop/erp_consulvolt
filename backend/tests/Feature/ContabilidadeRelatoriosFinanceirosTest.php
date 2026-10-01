<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\PlanoConta;
use App\Models\SaldoHistorico;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contabilidade parte 2 (ADR-055): Balanço, DR e Fluxo de Caixa pelas notas, comparativo e saldos históricos, movimentos
 * por mapear, detalhe de nota, balancete com as opções do legado, extracto, compensações, evolução, IVA e reconciliação AGT.
 *
 * Cenário: 2025 — capital 1 000, serviços 500, pessoal 200, apuramento (período 13) para 88; 2026 — factura 1 000 + IVA 130,
 * recebimento, custo 400 + IVA 56 pago ao fornecedor, linha sem nota (50), classe 9 (100) e um pagamento estornado (100).
 * 2026-12-31: Activo 2 030 (10: 1 974; 9: 56) · CP+P 2 080 (12: 1 000; 14: 300; RL: 650; 21: 130) · diferença −50 = a linha sem nota.
 */
final class ContabilidadeRelatoriosFinanceirosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $n = [];

    private array $f = [];

    private int $diario;

    private int $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['31', 'Terceiros', 'T'], ['311', 'Clientes', 'M'], ['321', 'Fornecedores', 'M'], ['3452', 'IVA dedutível', 'M'], ['3453', 'IVA liquidado', 'M'],
                ['4311', 'Banco', 'M'], ['511', 'Capital', 'M'], ['621', 'Prestações de serviços', 'M'], ['721', 'Remunerações', 'M'], ['75', 'Outros custos', 'M'],
                ['881', 'Resultado líquido', 'M'], ['91', 'Analítica A', 'M'], ['92', 'Analítica B', 'M'], ['7681', 'Diferenças', 'M']] as [$c, $d, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => $t]);
            }
            foreach (['9' => 'Contas a receber', '10' => 'Disponibilidades', '12' => 'Capital', '14' => 'Resultados transitados', '14.1' => 'Transitados (sub)',
                '19' => 'Contas a pagar', '21' => 'Outros passivos correntes', '23' => 'Prestações de serviços', '28' => 'Custos com o pessoal', '30' => 'Outros custos'] as $c => $d) {
                $this->n[(string) $c] = NotaDemonstracao::create(['codigo' => (string) $c, 'descricao' => $d])->id;
            }
            foreach (['111' => 'Recebimentos de clientes', '112' => 'Pagamentos a fornecedores', '113' => 'Pagamentos ao pessoal'] as $c => $d) {
                $this->f[(string) $c] = NotaFluxoCaixa::create(['codigo' => (string) $c, 'descricao' => $d])->id;
            }
            $this->diario = DiarioContabil::create(['codigo' => 'OD', 'descricao' => 'Operações diversas'])->id;
            $this->fornecedor = Terceiro::create(['nif' => '5417009999', 'nome' => 'Fornecedor de Teste', 'tipo' => 'FORNECEDOR'])->id;

            $l = fn (string $data, string $lan, array $linhas, array $extra = []) => array_map(fn ($x) => LancamentoContabil::create($extra + [
                'diario_id' => $this->diario, 'data_documento' => $data, 'numero_lan' => $lan, 'numero_documento' => $x[5] ?? $lan,
                'codigo_conta' => $x[0], 'tipo_dc' => $x[1], 'valor' => $x[2], 'nota_demonstracao_id' => $x[3] ? $this->n[$x[3]] : null,
                'nota_fluxo_caixa_id' => isset($x[4]) && $x[4] ? $this->f[$x[4]] : null, 'terceiro_id' => $x[6] ?? null]), $linhas);
            $l('2025-01-10', 'OD2025000001', [['4311', 'D', 1000, '10'], ['511', 'C', 1000, '12']]);
            $l('2025-06-01', 'OD2025000002', [['4311', 'D', 500, '10', '111'], ['621', 'C', 500, '23']]);
            $l('2025-07-01', 'OD2025000003', [['721', 'D', 200, '28'], ['4311', 'C', 200, '10', '113']]);
            $l('2025-12-31', 'AP2025000001', [['621', 'D', 500, '23'], ['721', 'C', 200, '28'], ['881', 'C', 300, '14']], ['periodo_id' => 13]);
            $l('2026-03-01', 'OD2026000001', [['311', 'D', 1130, '9', null, 'FT 1'], ['621', 'C', 1000, '23', null, 'FT 1'], ['3453', 'C', 130, '21', null, 'FT 1']]);
            $l('2026-04-01', 'OD2026000002', [['4311', 'D', 1130, '10', '111', 'FT 1'], ['311', 'C', 1130, '9', null, 'FT 1']]);
            $l('2026-05-01', 'OD2026000003', [['75', 'D', 400, '30', null, 'FC 9'], ['3452', 'D', 56, '9', null, 'FC 9'], ['321', 'C', 456, '19', null, 'FC 9', $this->fornecedor]]);
            $l('2026-05-10', 'OD2026000004', [['321', 'D', 456, '19', null, 'PG 9', $this->fornecedor], ['4311', 'C', 456, '10', '112', 'PG 9']]);
            $l('2026-06-01', 'OD2026000005', [['91', 'D', 100, null], ['92', 'C', 100, null]]);
            $l('2026-06-15', 'OD2026000006', [['4311', 'D', 50, null], ['621', 'C', 50, '23']]);
            $pag = $l('2026-08-01', 'OD2026000007', [['321', 'D', 100, '19', null, 'PG 10', $this->fornecedor], ['4311', 'C', 100, '10', '112', 'PG 10']]);
            app(ServicoLancamentos::class)->estornar($pag[0], 'Pagamento em duplicado');
        });
        $this->s = $this->sessao(['contab_mapa_balanco_view', 'contab_mapa_dr_view', 'contab_mapa_fluxo_view', 'contab_mapa_balancete_view', 'contab_mapa_extrato_view',
            'contab_mapa_evolucao_view', 'relatorios_contabeis_view', 'contab_compensar', 'contab_reconc_rev', 'contab_agt']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function nota(array $seccao, string $codigo): array
    {
        return collect($seccao['linhas'])->firstWhere('codigo', $codigo);
    }

    #[Test]
    public function balanco_pelas_notas_com_resultado_igual_ao_da_dr_e_controlo_dos_movimentos_por_mapear(): void
    {
        $b = $this->getJson('/api/contabilidade/relatorios/balanco?data_fim=2026-12-31', $this->s)->assertOk()->json('dados');
        $this->assertSame('2026-01-01', $b['data_inicio']);
        $this->assertSame(['1974.00', '1300.00'], array_values(array_intersect_key($this->nota($b['seccoes']['activo_corrente'], '10'), ['atual' => 1, 'anterior' => 1])));
        $this->assertSame('56.00', $this->nota($b['seccoes']['activo_corrente'], '9')['atual']);
        $this->assertSame('300.00', $this->nota($b['seccoes']['capital_proprio'], '14')['atual']);        // 2025: 500 − 200 (com o apuramento)
        $this->assertSame(['650.00', '300.00'], [$b['resultado_liquido']['atual'], $b['resultado_liquido']['anterior']]);
        $this->assertSame('130.00', $this->nota($b['seccoes']['passivo_corrente'], '21')['atual']);
        $this->assertSame('0.00', $this->nota($b['seccoes']['passivo_corrente'], '19')['atual']);       // o estorno anula o pagamento
        $this->assertSame(['2030.00', '2080.00'], [$b['totais']['atual']['activo'], $b['totais']['atual']['capital_proprio_passivo']]);
        $this->assertSame(['diferenca' => '-50.00', 'equilibrado' => false], array_intersect_key($b['controlo']['atual'], ['diferenca' => 1, 'equilibrado' => 1]));
        $this->assertSame(['linhas' => 1, 'debito' => '50.00', 'credito' => '0.00'], $b['controlo']['atual']['sem_nota']);
        // o ano anterior fecha (o apuramento de 2025 não entra no Balanço de 2025)
        $this->assertTrue($b['controlo']['anterior']['equilibrado']);
        $this->assertSame('1300.00', $b['totais']['anterior']['activo']);

        $dr = $this->getJson('/api/contabilidade/relatorios/demonstracao-resultados?data_inicio=2026-01-01&data_fim=2026-12-31', $this->s)->assertOk()->json('dados');
        $this->assertSame($b['resultado_liquido']['atual'], $dr['resultado_liquido']['atual']);
        $this->assertSame(['1050.00', '500.00'], [$dr['proveitos_operacionais']['linhas'][1]['atual'], $dr['proveitos_operacionais']['linhas'][1]['anterior']]);
        $this->assertSame(['400.00', '200.00'], [$dr['custos_operacionais']['total']['atual'], $dr['custos_operacionais']['total']['anterior']]);
        $this->assertSame('300.00', $dr['resultado_liquido']['anterior']);   // o apuramento de 2025 é excluído no próprio ano (o legado anulava a DR)

        // "Movimentos por mapear" e detalhe da nota (14 inclui 14.1; exclusões dos mapas aplicadas)
        $sem = $this->getJson('/api/contabilidade/relatorios/movimentos-sem-nota?data_inicio=2026-01-01&data_fim=2026-12-31', $this->s)->assertOk()->json('dados');
        $this->assertSame(['linhas' => 1, 'debito' => '50.00', 'credito' => '0.00', 'diferenca' => '50.00'], $sem['resumo']);
        $this->assertSame('4311', $sem['linhas'][0]['codigo_conta']);
        $det = $this->getJson("/api/contabilidade/relatorios/notas/demonstracao/{$this->n['10']}?data_fim=2026-12-31", $this->s)->assertOk()->json('dados');
        $this->assertSame('1974.00', $det['totais']['saldo_devedor']);
        $this->getJson('/api/contabilidade/relatorios/balanco?data_fim=2026-12-31', $this->sessao(['contab_mapa_dr_view']))->assertForbidden();
    }

    #[Test]
    public function comparativo_usa_os_saldos_historicos_e_filtros(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            SaldoHistorico::create(['ano' => 2025, 'tipo' => 'DEMONSTRACAO_RESULTADOS', 'tipo_original' => 'DEMO', 'codigo' => '23', 'valor' => 999]);
            SaldoHistorico::create(['ano' => 2025, 'tipo' => 'DEMONSTRACAO_RESULTADOS', 'tipo_original' => 'DEMO', 'codigo' => '10', 'valor' => 1234]);
            SaldoHistorico::create(['ano' => 2025, 'tipo' => 'FLUXO_CAIXA', 'tipo_original' => 'FLUXO', 'codigo' => '112', 'valor' => 70]);
            SaldoHistorico::create(['ano' => 2025, 'tipo' => 'FLUXO_CAIXA', 'tipo_original' => 'FLUXO', 'codigo' => 'TOT2', 'valor' => 5000]);
        });
        $dr = $this->getJson('/api/contabilidade/relatorios/demonstracao-resultados?data_inicio=2026-01-01&data_fim=2026-12-31', $this->s)->json('dados');
        $this->assertTrue($dr['historico_anterior']);
        $this->assertSame(['999.00', '799.00'], [$dr['notas']['anterior']['23'], $dr['resultado_liquido']['anterior']]);
        $b = $this->getJson('/api/contabilidade/relatorios/balanco?data_fim=2026-12-31', $this->s)->json('dados');
        $this->assertSame(['1234.00', '799.00'], [$b['notas']['anterior']['10'], $b['resultado_liquido']['anterior']]);
        $fx = $this->getJson('/api/contabilidade/relatorios/fluxo-caixa?data_inicio=2026-01-01&data_fim=2026-12-31', $this->s)->json('dados');
        $this->assertSame(['-70.00', '5000.00'], [$fx['notas']['anterior']['112'], $fx['caixa_inicial']['anterior']]);   // pagamento positivo passa a negativo

        // sem comparativo e com filtro de contas (só os bancos)
        $sem = $this->getJson('/api/contabilidade/relatorios/balanco?data_fim=2026-12-31&comparativo=0&filtro_contas=43', $this->s)->json('dados');
        $this->assertNull($sem['totais']['anterior']);
        $this->assertSame('1974.00', $sem['totais']['atual']['activo']);
    }

    #[Test]
    public function fluxo_de_caixa_soma_com_sinal_por_nota_e_o_estorno_anula(): void
    {
        $fx = $this->getJson('/api/contabilidade/relatorios/fluxo-caixa?data_inicio=2026-01-01&data_fim=2026-12-31', $this->s)->assertOk()->json('dados');
        $op = collect($fx['seccoes']['operacionais']['linhas'])->keyBy('codigo');
        $this->assertSame('1130.00', $op['111']['atual']);
        $this->assertSame('-456.00', $op['112']['atual']);   // o legado (|valor| linha a linha) dava −656: contava o estorno como outro pagamento
        $this->assertSame(['674.00', '1300.00', '1974.00'], [$fx['variacao_caixa']['atual'], $fx['caixa_inicial']['atual'], $fx['caixa_final']['atual']]);
        $this->assertSame(['variacao_classe_4' => '724.00', 'nao_explicado' => '50.00'], $fx['controlo']);   // a linha de banco sem nota de fluxo
        $this->assertSame(['500.00', '-200.00'], [$op['111']['anterior'], $op['113']['anterior']]);
    }

    #[Test]
    public function balancete_com_totalizadoras_terceiros_e_exclusao_da_classe_9(): void
    {
        $b = $this->getJson('/api/contabilidade/relatorios/balancete?data_inicio=2026-01-01&data_fim=2026-12-31&totalizadoras=1&por_terceiro=1', $this->s)->assertOk()->json('dados');
        $linhas = collect($b['linhas'])->keyBy('codigo_conta');
        $this->assertFalse($linhas->has('91'));
        $this->assertTrue($linhas['31']['totalizadora']);
        $this->assertSame(['1130.00', '1130.00'], [$linhas['31']['debito'], $linhas['31']['credito']]);   // Σ de 311 (321 não começa por 31)
        $this->assertSame('5417009999', $linhas['321']['terceiros'][0]['nif']);
        $this->assertSame('1300.00', $linhas['4311']['saldo_inicial_devedor']);
        $this->assertSame($b['totais']['saldo_devedor'], $b['totais']['saldo_credor']);
        $com9 = $this->getJson('/api/contabilidade/relatorios/balancete?data_inicio=2026-01-01&data_fim=2026-12-31&incluir_classe_9=1', $this->s)->json('dados.linhas');
        $this->assertContains('91', array_column($com9, 'codigo_conta'));
        $razao = $this->getJson('/api/contabilidade/relatorios/balancete?data_inicio=2026-01-01&data_fim=2026-12-31&nivel=2&filtro_contas=31-32', $this->s)->json('dados.linhas');
        $this->assertSame(['31', '32'], array_column($razao, 'codigo_conta'));
    }

    #[Test]
    public function extracto_com_contrapartidas_em_aberto_e_compensacoes(): void
    {
        $ex = $this->getJson('/api/contabilidade/relatorios/extrato?data_inicio=2026-01-01&data_fim=2026-12-31&filtro_contas=311', $this->s)->assertOk()->json('dados');
        $this->assertCount(2, $ex['movimentos']);
        $this->assertSame('3453 / 621', $ex['movimentos'][0]['contrapartidas']);   // de todo o lançamento, não das linhas filtradas
        $this->assertSame(['0.00', '1130.00', '0.00'], [$ex['saldo_final'], $ex['movimentos'][0]['saldo'], $ex['movimentos'][1]['saldo']]);
        $aberto = $this->getJson('/api/contabilidade/relatorios/extrato?data_inicio=2026-01-01&filtro_contas=311&tipo=aberto', $this->s)->json('dados.movimentos');
        $this->assertCount(0, $aberto);   // FT 1 saldado
        $this->getJson('/api/contabilidade/relatorios/extrato?data_inicio=2026-01-01', $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXTRATO_SEM_CRITERIO');

        $ids = array_column($ex['movimentos'], 'id');
        $c = $this->postJson('/api/contabilidade/compensacoes', ['linhas' => $ids], $this->s)->assertCreated()->json('dados');
        $this->assertMatchesRegularExpression('/^MATCH-\d{8}-\d{4}$/', $c['reconciliacao_codigo']);
        $this->assertSame('1130.00', $c['valor_total']);
        $this->postJson('/api/contabilidade/compensacoes', ['linhas' => $ids], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'JA_COMPENSADO');
        $comp = $this->getJson('/api/contabilidade/relatorios/extrato?data_inicio=2026-01-01&filtro_contas=311&tipo=compensado', $this->s)->json('dados.movimentos');
        $this->assertCount(2, $comp);
        $this->getJson("/api/contabilidade/compensacoes/{$c['reconciliacao_codigo']}", $this->s)->assertOk()->assertJsonPath('dados.estado', 'COMPENSADO');
        $this->deleteJson("/api/contabilidade/compensacoes/{$c['reconciliacao_codigo']}", [], $this->s)->assertOk()->assertJsonPath('dados.linhas_libertadas', 2);
        $this->getJson("/api/contabilidade/compensacoes/{$c['reconciliacao_codigo']}", $this->s)->assertOk()->assertJsonPath('dados.estado', 'ANULADA');

        // conta fora da classe 3/48 exige o mesmo terceiro; diferença → regularização num lançamento OD
        $l4311 = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('codigo_conta', '4311')
            ->whereIn('numero_lan', ['OD2026000002', 'OD2026000006'])->pluck('id')->all());
        $this->postJson('/api/contabilidade/compensacoes', ['linhas' => $l4311], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'COMPENSACAO_DESEQUILIBRADA');
        $fc = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('codigo_conta', '321')
            ->where('numero_documento', 'FC 9')->value('id'));
        $reg = $this->postJson('/api/contabilidade/compensacoes/regularizar', ['linhas' => [$fc], 'codigo_conta' => '7681', 'data' => '2026-09-30'], $this->s)
            ->assertCreated()->json('dados');
        $this->assertSame('OD2026000009', $reg['lancamento_regularizacao']);   // OD2026000008 é o estorno
        $this->assertSame(2, $reg['linhas']);
        $this->postJson('/api/contabilidade/compensacoes', ['linhas' => $ids], $this->sessao(['contab_mapa_extrato_view']))->assertForbidden();
    }

    #[Test]
    public function evolucao_mensal_e_mapa_de_iva_com_reconciliacao_agt(): void
    {
        $ev = $this->getJson('/api/contabilidade/relatorios/evolucao?ano=2026&filtro_contas=621', $this->s)->assertOk()->json('dados');
        $this->assertSame([3, 6], $ev['meses_ativos']);
        $this->assertSame(['3' => '-1000.00', '6' => '-50.00'], $ev['contas'][0]['meses']);
        $this->assertSame('-1050.00', $ev['saldo_total']);

        $iva = $this->getJson('/api/contabilidade/relatorios/iva?data_inicio=2026-01-01&data_fim=2026-12-31', $this->s)->assertOk()->json('dados');
        $linhas = collect($iva['linhas'])->keyBy('codigo_conta');
        $this->assertSame(['1130.00', '1000.00', '130.00'], [$linhas['3453']['total_documento'], $linhas['3453']['base'], $linhas['3453']['iva_credito']]);
        $this->assertSame(['456.00', '400.00', '5417009999'], [$linhas['3452']['total_documento'], $linhas['3452']['base'], $linhas['3452']['nif']]);

        $agt = fn (array $linhas) => $this->postJson('/api/contabilidade/relatorios/iva/reconciliacao-agt', ['mes' => '2026-05', 'ficheiro' => $this->xlsx(
            [['NIF', 'Nome / Denominação', 'Número do Documento', 'Valor Tributável', 'IVA Dedutível Valor'], ...$linhas])], $this->s)->assertOk()->json('dados');
        $r = $agt([['5417009999', 'Fornecedor', 'FT-A/1', '400,00', 56.4], ['5000000001', 'Outro', 'X1', 100, 14]]);
        $this->assertSame(['DIVERGENTE' => 0, 'FALTA_NO_SISTEMA' => 1, 'FALTA_NA_AGT' => 0, 'CONCILIADO' => 1], $r['contagem']);
        $this->assertSame(['documentos' => 1, 'base' => '400.00', 'iva' => '56.00'], $r['sistema']);
        $r = $agt([['5417009999', 'Fornecedor', 'FC 9', 400, 80]]);
        $this->assertSame('DIVERGENTE', $r['linhas'][0]['estado']);   // mesmo NIF e documento, valores diferentes (o legado nunca o assinalava)
        $this->assertSame('-24.00', $r['linhas'][0]['diferenca_iva']);
    }

    private function xlsx(array $linhas): UploadedFile
    {
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray($linhas);
        $f = tempnam(sys_get_temp_dir(), 'agt').'.xlsx';
        (new Xlsx($ss))->save($f);

        return new UploadedFile($f, 'agt.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
