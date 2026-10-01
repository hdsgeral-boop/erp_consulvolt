<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\SaldoHistorico;
use App\Models\StockArmazem;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Encerramento do exercício (ADR-056): passos de apuramento no período 13 (paridade com js/ui_closing.js), repetição por estorno,
 * validações finais, cadeado, sequência dos exercícios, reabertura e cancelamento do apuramento por estorno.
 * Exercício de 2025: venda 1 000 (6111), CMV 400 (7111), proveito financeiro 50 (6611), custo não operacional 30 (7811).
 * Resultado: operacional −600, financeiro −50, não operacional +30 → 889 com saldo credor de 620.
 */
final class EncerramentoExercicioTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private int $diario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000561']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['2111', '3111', '4511', '6111', '619', '7111', '719', '6611', '669', '7811', '7819', '821', '826', '8219', '881', '831', '839', '882',
                '852', '859', '884', '889', '2611'] as $c) {
                PlanoConta::create(['codigo' => $c, 'descricao' => "Conta {$c}", 'tipo' => 'M']);
            }
            $this->diario = DiarioContabil::create(['codigo' => 'OD', 'nome' => 'Operações diversas'])->id;
        });
        $this->s = $this->sessao(['encerramento_view', 'contab_apurar', 'contab_exercicio_reabrir', 'lancamentos_post']);
        $this->lancar('2025-03-10', [['4511', 'D', 1000], ['6111', 'C', 1000]]);
        $this->lancar('2025-03-11', [['2111', 'D', 400], ['4511', 'C', 400]]);
        $this->lancar('2025-03-12', [['7111', 'D', 400], ['2111', 'C', 400]]);
        $this->lancar('2025-06-30', [['4511', 'D', 50], ['6611', 'C', 50]]);
        $this->lancar('2025-09-30', [['7811', 'D', 30], ['4511', 'C', 30]]);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function lancar(string $data, array $linhas): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoLancamentos::class)->criar(['diario_id' => $this->diario, 'data_documento' => $data,
            'linhas' => array_map(fn ($l) => ['codigo_conta' => $l[0], 'tipo_dc' => $l[1], 'valor' => $l[2]], $linhas)]));
    }

    private function saldo(string $conta, int $ano = 2025): string
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => number_format((float) LancamentoContabil::query()->where('codigo_conta', $conta)
            ->whereYear('data_documento', $ano)->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS s")->value('s'), 2, '.', ''));
    }

    private function apurar(): void
    {
        foreach ([1, 2, 3, 4, 5] as $p) {
            $this->postJson("/api/contabilidade/encerramento/2025/passos/{$p}", [], $this->s)->assertOk();
        }
    }

    #[Test]
    public function os_cinco_passos_apuram_no_periodo_13_como_o_legado(): void
    {
        $pre = $this->getJson('/api/contabilidade/encerramento/2025/passos/1', $this->s)->assertOk()->json('dados');
        $this->assertSame(16, $pre['linhas']);
        $this->assertSame('-600.00', $pre['resultado']);
        $this->assertSame([], $pre['contas_em_falta']);
        $this->assertSame(['descricao' => 'Apuramento para a conta 619', 'conta_debito' => '6111', 'conta_credito' => '619', 'valor' => '1000.00'], $pre['movimentos'][0]);
        $this->assertSame(['descricao' => 'Transferência para a conta 889', 'conta_debito' => '881', 'conta_credito' => '889', 'valor' => '600.00'], $pre['movimentos'][7]);
        $this->assertNull(app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => DiarioContabil::query()->where('codigo', 'AP-O')->first()));

        $r1 = $this->postJson('/api/contabilidade/encerramento/2025/passos/1', [], $this->s)->assertOk()->json('dados');
        $this->assertSame('AP-O2025000001', $r1['numero_lan']);
        $this->assertSame('AP-OP-2025', $r1['documento']);
        $this->assertSame(10, $this->postJson('/api/contabilidade/encerramento/2025/passos/2', [], $this->s)->assertOk()->json('dados.linhas'));
        $this->assertNull($this->postJson('/api/contabilidade/encerramento/2025/passos/3', [], $this->s)->assertOk()->json('dados.numero_lan'));
        $r4 = $this->postJson('/api/contabilidade/encerramento/2025/passos/4', [], $this->s)->assertOk()->json('dados');
        $this->assertSame('30.00', $r4['resultado']);
        $this->assertSame('7819', $r4['movimentos'][0]['conta_debito']);
        $this->postJson('/api/contabilidade/encerramento/2025/passos/5', [], $this->s)->assertOk();

        foreach (['6111', '7111', '6611', '7811', '619', '719', '669', '7819', '821', '826', '8219', '881', '882', '884'] as $c) {
            $this->assertSame('0.00', $this->saldo($c), "conta {$c}");
        }
        $this->assertSame('-620.00', $this->saldo('889'));
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $ap = LancamentoContabil::query()->where('tipo_origem', 'ENCERRAMENTO')->get();
            $this->assertCount(36, $ap);
            $this->assertTrue($ap->every(fn ($l) => $l->periodo_id === 13 && $l->data_documento->toDateString() === '2025-12-31'));
        });

        $estado = $this->getJson('/api/contabilidade/encerramento/2025', $this->s)->assertOk()->json('dados');
        $this->assertFalse($estado['encerrado']);
        $this->assertSame(['AP-O2025000001', '-600.00'], [$estado['passos'][0]['numero_lan'], $estado['passos'][0]['resultado']]);
        $this->assertNull($estado['passos'][2]['numero_lan']);
        $this->assertSame('620.00', collect($estado['resumo_classe_8'])->firstWhere('codigo_conta', '889')['saldo_credor']);
        $mapa = $this->getJson('/api/contabilidade/encerramento/2025/mapa', $this->s)->assertOk()->json('dados');
        $this->assertCount(36, $mapa['linhas']);
        $this->assertSame($mapa['total_debito'], $mapa['total_credito']);
    }

    #[Test]
    public function repetir_um_passo_estorna_o_apuramento_anterior(): void
    {
        $this->postJson('/api/contabilidade/encerramento/2025/passos/1', [], $this->s)->assertOk();
        $this->lancar('2025-11-30', [['4511', 'D', 100], ['6111', 'C', 100]]);
        $r = $this->postJson('/api/contabilidade/encerramento/2025/passos/1', [], $this->s)->assertOk()->json('dados');
        $this->assertSame(['AP-O2025000001'], $r['estornados']);
        $this->assertSame('AP-O2025000003', $r['numero_lan']);
        $this->assertSame('-700.00', $r['resultado']);
        $this->assertSame('-700.00', $this->saldo('889'));
        $this->assertSame('0.00', $this->saldo('6111'));
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $estornos = LancamentoContabil::query()->where('numero_lan', 'AP-O2025000002')->get();
            $this->assertCount(16, $estornos);
            $this->assertTrue($estornos->every(fn ($l) => $l->periodo_id === 13 && $l->estorno_de_id !== null));
        });
    }

    #[Test]
    public function encerra_so_sem_divergencias_e_tranca_o_exercicio(): void
    {
        $v = $this->getJson('/api/contabilidade/encerramento/2025/validacoes', $this->s)->assertOk()->json('dados');
        $this->assertFalse($v['pode_encerrar']);
        $this->assertSame(['APURAMENTO'], array_column($v['divergencias'], 'tipo'));
        $this->assertSame(['-1050.00', '430.00'], [$v['divergencias'][0]['detalhes']['classe_6'], $v['divergencias'][0]['detalhes']['classe_7']]);
        $this->postJson('/api/contabilidade/encerramento/2025/encerrar', [], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'ENCERRAMENTO_COM_DIVERGENCIAS')->assertJsonPath('erros.divergencias.0.tipo', 'APURAMENTO');

        $this->apurar();
        $v = $this->getJson('/api/contabilidade/encerramento/2025/validacoes', $this->s)->assertOk()->json('dados');
        $this->assertTrue($v['pode_encerrar']);
        $this->assertSame(['SEQUENCIA', 'BALANCO', 'APURAMENTO', 'IMOBILIZADO', 'INVENTARIO', 'HISTORICO'], array_column($v['verificacoes'], 'tipo'));
        $this->postJson('/api/contabilidade/encerramento/2025/encerrar', [], $this->s)->assertOk()->assertJsonPath('dados.encerrado', true);
        $this->assertTrue((bool) $this->getJson('/api/contabilidade/encerramento/2025', $this->s)->json('dados.encerrado'));
        $this->postJson('/api/contabilidade/encerramento/2025/encerrar', [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_JA_ENCERRADO');

        $this->postJson('/api/contabilidade/lancamentos', ['diario_id' => $this->diario, 'data_documento' => '2025-05-05',
            'linhas' => [['codigo_conta' => '4511', 'tipo_dc' => 'D', 'valor' => 1], ['codigo_conta' => '6111', 'tipo_dc' => 'C', 'valor' => 1]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_ENCERRADO');
        $this->postJson('/api/contabilidade/encerramento/2025/passos/1', [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_ENCERRADO');
        $this->assertSame([['ano' => 2025, 'encerrado' => true, 'linhas' => 46, 'apuramento' => true]],
            $this->getJson('/api/contabilidade/encerramento', $this->s)->assertOk()->json('dados'));
    }

    #[Test]
    public function exige_a_sequencia_dos_exercicios_e_reabre_do_mais_recente_para_o_mais_antigo(): void
    {
        $this->lancar('2024-12-01', [['4511', 'D', 10], ['3111', 'C', 10]]);
        $this->apurar();
        $this->postJson('/api/contabilidade/encerramento/2025/encerrar', [], $this->s)->assertStatus(422)->assertJsonPath('erros.divergencias.0.tipo', 'SEQUENCIA')
            ->assertJsonPath('erros.divergencias.0.detalhes.anos_abertos', [2024]);
        $this->postJson('/api/contabilidade/encerramento/2024/encerrar', [], $this->s)->assertOk();
        $this->postJson('/api/contabilidade/encerramento/2025/encerrar', [], $this->s)->assertOk();

        $this->postJson('/api/contabilidade/encerramento/2024/reabrir', ['motivo' => 'Correcção'], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'EXERCICIOS_SEGUINTES_ENCERRADOS')->assertJsonPath('erros.anos', [2025]);
        $this->postJson('/api/contabilidade/encerramento/2025/reabrir', [], $this->s)->assertStatus(422);
        $semReabrir = $this->sessao(['encerramento_view', 'contab_apurar']);
        $this->postJson('/api/contabilidade/encerramento/2025/reabrir', ['motivo' => 'Correcção'], $semReabrir)->assertForbidden();
        $this->postJson('/api/contabilidade/encerramento/2025/reabrir', ['motivo' => 'Correcção de uma factura'], $this->s)->assertOk()->assertJsonPath('dados.encerrado', false);
        $this->postJson('/api/contabilidade/encerramento/2025/reabrir', ['motivo' => 'outra vez'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_NAO_ENCERRADO');
        $this->postJson('/api/contabilidade/encerramento/2024/reabrir', ['motivo' => 'Correcção'], $this->s)->assertOk();
    }

    #[Test]
    public function cancelar_o_apuramento_reabre_e_estorna_o_periodo_13(): void
    {
        $this->apurar();
        $this->postJson('/api/contabilidade/encerramento/2025/encerrar', [], $this->s)->assertOk();
        $r = $this->postJson('/api/contabilidade/encerramento/2025/cancelar-apuramento', ['motivo' => 'Refazer'], $this->s)->assertOk()->json('dados');
        $this->assertTrue($r['reaberto']);
        $this->assertCount(3, $r['estornados']);
        $this->assertSame('0.00', $this->saldo('889'));
        $this->assertSame('-1000.00', $this->saldo('6111'));
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $this->assertSame(0, LancamentoContabil::query()->where('periodo_id', 13)->whereNull('estorno_de_id')->whereNull('estornado_por_id')->count());
            $this->assertSame(72, LancamentoContabil::query()->where('periodo_id', 13)->count());
        });
        $this->assertFalse((bool) $this->getJson('/api/contabilidade/encerramento/2025', $this->s)->json('dados.encerrado'));
    }

    #[Test]
    public function contas_do_apuramento_em_falta_ou_totalizadoras(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            PlanoConta::query()->where('codigo', '821')->first()->delete();
            PlanoConta::query()->where('codigo', '669')->first()->update(['tipo' => 'T']);
        });
        $this->assertSame(['821'], $this->getJson('/api/contabilidade/encerramento/2025/passos/1', $this->s)->assertOk()->json('dados.contas_em_falta'));
        $this->postJson('/api/contabilidade/encerramento/2025/passos/1', [], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'CONTAS_APURAMENTO_EM_FALTA')->assertJsonPath('erros.contas', ['821']);
        $this->postJson('/api/contabilidade/encerramento/2025/passos/1', ['criar_contas_em_falta' => true], $this->s)->assertOk()->assertJsonPath('dados.contas_criadas', ['821']);
        $this->assertSame(['669'], $this->getJson('/api/contabilidade/encerramento/2025/passos/2', $this->s)->json('dados.contas_totalizadoras'));
        $this->postJson('/api/contabilidade/encerramento/2025/passos/2', ['criar_contas_em_falta' => true], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'CONTAS_APURAMENTO_TOTALIZADORAS');
    }

    #[Test]
    public function inventario_e_balanco_historico_divergentes(): void
    {
        $this->lancar('2025-01-05', [['2611', 'D', 250], ['4511', 'C', 250]]);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['4' => 1000, '8' => 200, '12' => 900, '22' => 500, '27' => 450] as $c => $v) {
                SaldoHistorico::create(['ano' => 2025, 'tipo' => 'DEMONSTRACAO_RESULTADOS', 'codigo' => (string) $c, 'valor' => $v]);
            }
            SaldoHistorico::create(['ano' => 2025, 'tipo' => 'FLUXO_CAIXA', 'codigo' => '4', 'valor' => 99999]);
        });
        $v = collect($this->getJson('/api/contabilidade/encerramento/2025/validacoes', $this->s)->assertOk()->json('dados.verificacoes'))->keyBy('tipo');
        $this->assertFalse($v['INVENTARIO']['ok']);
        $this->assertSame(['armazem' => '0.00', 'contabilidade' => '250.00'], $v['INVENTARIO']['detalhes']);
        $this->assertSame('250.00', $v['INVENTARIO']['diferenca']);
        $this->assertFalse($v['HISTORICO']['ok']);
        $this->assertSame(['activo' => '1200.00', 'passivo_capital' => '950.00'], $v['HISTORICO']['detalhes']);
        $this->assertTrue($v['IMOBILIZADO']['ok']);
        $this->assertTrue($v['SEQUENCIA']['ok']);

        // com stock: só os artigos que movimentam stock, ao custo médio (10 × 25 = 250); o serviço de 5 × 100 não conta
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $armazem = Armazem::create(['nome' => 'Principal', 'codigo' => 'PRINCIPAL', 'predefinido' => true])->id;
            foreach ([['MERC', true, 25, 10], ['SERV', false, 100, 5]] as [$c, $mov, $custo, $q]) {
                $p = Produto::create(['codigo' => $c, 'nome' => $c, 'movimenta_stock' => $mov, 'custo_medio' => $custo, 'preco_unitario' => 999]);
                StockArmazem::create(['armazem_id' => $armazem, 'produto_id' => $p->id, 'quantidade_stock' => $q]);
            }
        });
        $inv = collect($this->getJson('/api/contabilidade/encerramento/2025/validacoes', $this->s)->json('dados.verificacoes'))->firstWhere('tipo', 'INVENTARIO');
        $this->assertSame([true, '250.00'], [$inv['ok'], $inv['detalhes']['armazem']]);
    }

    #[Test]
    public function permissoes(): void
    {
        $ver = $this->sessao(['encerramento_view']);
        $this->getJson('/api/contabilidade/encerramento/2025', $ver)->assertOk();
        $this->getJson('/api/contabilidade/encerramento/2025/validacoes', $ver)->assertOk();
        $this->postJson('/api/contabilidade/encerramento/2025/passos/1', [], $ver)->assertForbidden();
        $this->postJson('/api/contabilidade/encerramento/2025/encerrar', [], $ver)->assertForbidden();
        $this->postJson('/api/contabilidade/encerramento/2025/cancelar-apuramento', ['motivo' => 'x'], $ver)->assertForbidden();
        $this->getJson('/api/contabilidade/encerramento/2025/passos/9', $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PASSO_INVALIDO');
    }
}
