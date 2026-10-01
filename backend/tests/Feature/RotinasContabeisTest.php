<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\ReconciliacaoBancaria;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rotinas contabilísticas (ADR-056): Imposto de Selo, capitalização de obras internas, compensação automática, transferência de
 * saldos, actualização em massa, histórico, anulação por estorno e pré-visualização da limpeza de reconciliações.
 */
final class RotinasContabeisTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $d = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000562']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['1141', '3111', '3772', '4311', '4511', '4811', '6111', '6511', '7211', '75311', '3471', '2113', '2114'] as $c) {
                PlanoConta::create(['codigo' => $c, 'descricao' => "Conta {$c}", 'tipo' => 'M']);
            }
            PlanoConta::create(['codigo' => '31', 'descricao' => 'Terceiros', 'tipo' => 'T']);
            foreach (['VD', 'CB', 'CX', 'OD'] as $c) {
                $this->d[$c] = DiarioContabil::create(['codigo' => $c, 'nome' => "Diário {$c}"])->id;
            }
            $this->d['t1'] = Terceiro::create(['nome' => 'Cliente A', 'nif' => '5000000001'])->id;
            $this->d['t2'] = Terceiro::create(['nome' => 'Cliente B', 'nif' => '5000000002'])->id;
            $this->d['nota'] = NotaDemonstracao::create(['codigo' => '9', 'descricao' => 'Outros'])->id;
        });
        $this->s = $this->sessao(['contab_rotinas_view', 'contab_rotinas_exec', 'contab_rotinas_anular', 'imposto_selo_view', 'selo_lancar']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    /** @return list<int> ids das linhas */
    private function lancar(string $diario, string $data, array $linhas, ?string $documento = null): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoLancamentos::class)->criar([
            'diario_id' => $this->d[$diario], 'data_documento' => $data, 'numero_documento' => $documento,
            'linhas' => array_map(fn ($l) => ['codigo_conta' => $l[0], 'tipo_dc' => $l[1], 'valor' => $l[2], 'terceiro_id' => $l[3] ?? null, 'numero_documento' => $l[4] ?? null], $linhas),
        ])->pluck('id')->all());
    }

    private function emContexto(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    #[Test]
    public function imposto_de_selo_a_1_por_cento_sem_duplicar_o_mes(): void
    {
        $this->lancar('VD', '2026-03-05', [['4511', 'D', '10000.50'], ['6111', 'C', '10000.50']]);
        $this->lancar('CB', '2026-03-06', [['4311', 'D', 2345.17], ['3111', 'C', 2345.17, $this->d['t1']]]);
        $this->lancar('CB', '2026-03-07', [['4811', 'D', 1000], ['3111', 'C', 1000, $this->d['t1']]]);
        $this->lancar('CX', '2026-03-08', [['4511', 'D', 500], ['6111', 'C', 500]]);
        $this->lancar('CX', '2026-03-09', [['4311', 'D', 700], ['6111', 'C', 700]]);
        $this->lancar('VD', '2026-04-01', [['4511', 'D', 999], ['6111', 'C', 999]]);
        $estornada = $this->lancar('VD', '2026-03-10', [['4511', 'D', 5000], ['6111', 'C', 5000]]);
        $this->emContexto(fn () => app(ServicoLancamentos::class)->estornar(LancamentoContabil::query()->find($estornada[0]), 'anulada'));

        $r = $this->getJson('/api/contabilidade/rotinas/imposto-selo?mes=3&ano=2026', $this->s)->assertOk()->json('dados');
        $this->assertSame(['13345.67', 3, '500.00', 1, '13845.67', '138.46', 'IS-32026', '2026-03-31'],
            [$r['base_vd_cb'], $r['movimentos_vd_cb'], $r['base_cx'], $r['movimentos_cx'], $r['base_total'], $r['imposto'], $r['documento'], $r['data_documento']]);
        $l = $this->postJson('/api/contabilidade/rotinas/imposto-selo', ['mes' => 3, 'ano' => 2026], $this->s)->assertOk()->json('dados');
        $this->assertSame('AC2026000001', $l['numero_lan']);
        $this->emContexto(function () {
            $linhas = LancamentoContabil::query()->where('numero_documento', 'IS-32026')->orderBy('id')->get();
            $this->assertSame(['75311 D 138.46', '3471 C 138.46'], $linhas->map(fn ($x) => "{$x->codigo_conta} {$x->tipo_dc} {$x->valor}")->all());
            $this->assertSame('IS-32026', $linhas[0]->referencia);
        });
        $this->postJson('/api/contabilidade/rotinas/imposto-selo', ['mes' => 3, 'ano' => 2026], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SELO_JA_LANCADO');
        $this->postJson('/api/contabilidade/rotinas/imposto-selo', ['mes' => 7, 'ano' => 2026], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SELO_SEM_BASE');
        $this->assertCount(1, $this->getJson('/api/contabilidade/rotinas/imposto-selo/historico', $this->s)->assertOk()->json('dados'));
        $this->postJson('/api/contabilidade/rotinas/imposto-selo', ['mes' => 4, 'ano' => 2026], $this->sessao(['imposto_selo_view']))->assertForbidden();
    }

    #[Test]
    public function capitalizacao_de_obras_internas_com_historico_e_anulacao(): void
    {
        $this->emContexto(function () {
            $obra = Projeto::create(['codigo' => 'OBR-1', 'nome' => 'Obra interna', 'tipo' => 'INTERNO', 'estado' => 'ACTIVO'])->id;
            $this->d['externa'] = Projeto::create(['codigo' => 'PRJ-2', 'nome' => 'Externa', 'tipo' => 'EXTERNO', 'estado' => 'ACTIVO'])->id;
            $this->d['obra'] = $obra;
            foreach ([['CUSTO', 1000, null, '2026-05-03'], ['CUSTO_REAL', null, 250.5, '2026-05-20'], ['PROVEITO', 800, null, '2026-05-21'], ['CUSTO', 99, null, '2026-06-01']] as [$n, $v, $m, $dt]) {
                RazaoAnaliticoProjeto::create(['projeto_id' => $obra, 'natureza' => $n, 'valor' => $v, 'montante' => $m, 'data' => $dt]);
            }
        });
        $this->assertSame(['OBR-1'], array_column($this->getJson('/api/contabilidade/rotinas/capitalizacao/obras', $this->s)->assertOk()->json('dados'), 'codigo'));
        $pedido = ['mes' => '2026-05', 'projetos' => [$this->d['obra']], 'conta_debito' => '1141', 'conta_credito' => '6511'];
        $this->postJson('/api/contabilidade/rotinas/capitalizacao', ['conta_debito' => '7211'] + $pedido, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTAS_CAPITALIZACAO_INVALIDAS');
        $this->postJson('/api/contabilidade/rotinas/capitalizacao', ['projetos' => [$this->d['externa']]] + $pedido, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_INVALIDA');
        $r = $this->postJson('/api/contabilidade/rotinas/capitalizacao', $pedido, $this->s)->assertOk()->json('dados');
        $this->assertSame(['CAP-202605', '2026-05-31', '1250.50', 2], [$r['documento'], $r['data_documento'], $r['total'], $r['movimentos']]);
        $this->postJson('/api/contabilidade/rotinas/capitalizacao', $pedido, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CAPITALIZACAO_DUPLICADA');
        $this->postJson('/api/contabilidade/rotinas/capitalizacao', ['mes' => '2026-07'] + $pedido, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_CUSTOS_A_CAPITALIZAR');

        $h = collect($this->getJson('/api/contabilidade/rotinas/historico', $this->s)->assertOk()->json('dados'))->firstWhere('codigo', 'CAP-202605');
        $this->assertSame(['CAPITALIZACAO', '1250.50', 'EXECUTADA'], [$h['tipo'], $h['valor_total'], $h['estado']]);
        $this->postJson('/api/contabilidade/rotinas/anular', ['codigo' => 'CAP-202605', 'motivo' => 'Erro de mês'], $this->s)->assertOk()->assertJsonPath('dados.estornados', [$r['numero_lan']]);
        $this->assertSame('ANULADA', collect($this->getJson('/api/contabilidade/rotinas/historico', $this->s)->json('dados'))->firstWhere('codigo', 'CAP-202605')['estado']);
        $this->postJson('/api/contabilidade/rotinas/capitalizacao', $pedido, $this->s)->assertOk();
    }

    #[Test]
    public function compensacao_automatica_com_regularizacao_e_anulacao(): void
    {
        $fatura = $this->lancar('VD', '2026-02-01', [['3111', 'D', 1000, $this->d['t1'], 'FT 1'], ['6111', 'C', 1000]]);
        $recibo = $this->lancar('CB', '2026-02-10', [['4311', 'D', 1000], ['3111', 'C', 1000, $this->d['t1'], 'FT 1']]);
        $fatura2 = $this->lancar('VD', '2026-02-02', [['3111', 'D', '500.40', $this->d['t1'], 'FT 2'], ['6111', 'C', '500.40']]);
        $recibo2 = $this->lancar('CB', '2026-02-11', [['4311', 'D', 500], ['3111', 'C', 500, $this->d['t2'], 'FT 2']]);
        $this->lancar('OD', '2026-02-12', [['7211', 'D', 100, $this->d['t1'], 'X'], ['7211', 'C', 100, $this->d['t2'], 'X']]);

        $pares = $this->getJson('/api/contabilidade/rotinas/compensacao/pares', $this->s)->assertOk()->json('dados');
        $this->assertCount(2, $pares);
        $porDoc = collect($pares)->keyBy(fn ($p) => $p['debito']['numero_documento']);
        $this->assertSame([$fatura[0], $recibo[1], '0.00', true], [$porDoc['FT 1']['debito']['id'], $porDoc['FT 1']['credito']['id'], $porDoc['FT 1']['diferenca'], $porDoc['FT 1']['exacto']]);
        $this->assertSame(['0.40', false], [$porDoc['FT 2']['diferenca'], $porDoc['FT 2']['exacto']]);

        $pedido = array_map(fn ($p) => ['debito_id' => $p['debito']['id'], 'credito_id' => $p['credito']['id']], $pares);
        $pedido[] = ['debito_id' => $fatura[0], 'credito_id' => $recibo2[1]];
        $r = $this->postJson('/api/contabilidade/rotinas/compensacao', ['pares' => $pedido], $this->s)->assertOk()->json('dados');
        $this->assertCount(2, $r['compensados']);
        $this->assertSame(1, $r['regularizacoes']);
        $this->assertSame('Uma das linhas já está compensada.', $r['recusados'][0]['motivo']);
        $reg = collect($r['compensados'])->firstWhere('regularizacao', '!=', null);
        $this->emContexto(function () use ($reg, $fatura2) {
            $linhas = LancamentoContabil::query()->where('numero_lan', $reg['regularizacao'])->orderBy('id')->get();
            $this->assertSame(['3772 D 0.40', '3111 C 0.40'], $linhas->map(fn ($x) => "{$x->codigo_conta} {$x->tipo_dc} {$x->valor}")->all());
            $this->assertSame('Regularização Automática (Rotina) - Doc: FT 2', $linhas[0]->descricao);
            $this->assertSame($reg['codigo'], $linhas[1]->reconciliacao_codigo);
            $this->assertNull($linhas[0]->reconciliacao_codigo);
            $this->assertSame($reg['codigo'], LancamentoContabil::query()->find($fatura2[0])->reconciliacao_codigo);
        });
        $this->assertCount(2, collect($this->getJson('/api/contabilidade/rotinas/historico', $this->s)->json('dados'))->where('tipo', 'COMPENSACAO'));

        $a = $this->postJson('/api/contabilidade/rotinas/anular', ['codigo' => $reg['codigo'], 'motivo' => 'Par errado'], $this->s)->assertOk()->json('dados');
        $this->assertSame([3, [$reg['regularizacao']]], [$a['linhas_libertadas'], $a['estornados']]);
        $this->emContexto(function () use ($reg, $fatura2) {
            $this->assertNull(LancamentoContabil::query()->find($fatura2[0])->reconciliacao_codigo);
            $this->assertSame(2, LancamentoContabil::query()->where('numero_lan', $reg['regularizacao'])->whereNotNull('estornado_por_id')->count());
            $this->assertSame('ANULADA', ReconciliacaoBancaria::query()->where('reconciliacao_codigo', $reg['codigo'])->value('estado'));
        });
        $this->postJson('/api/contabilidade/rotinas/anular', ['codigo' => $reg['codigo'], 'motivo' => 'de novo'], $this->s)->assertStatus(404);
    }

    #[Test]
    public function transferencia_de_saldos_compensa_a_origem_e_anula_por_estorno(): void
    {
        $this->lancar('OD', '2026-03-03', [['2113', 'D', 300], ['4511', 'C', 300]]);
        $this->lancar('OD', '2026-03-04', [['2113', 'D', 200], ['4511', 'C', 200]]);
        $this->lancar('OD', '2026-03-05', [['4511', 'D', 100], ['2113', 'C', 100]]);
        $this->lancar('OD', '2026-04-05', [['2113', 'D', 999], ['4511', 'C', 999]]);

        $this->assertSame([['conta_origem' => '2113', 'saldo' => '400.00', 'nota_demonstracao_id' => null, 'natureza' => 'DEVEDOR']],
            $this->postJson('/api/contabilidade/rotinas/transferencia/saldos', ['mes' => '2026-03', 'contas' => ['2113']], $this->s)->assertOk()->json('dados'));
        $r = $this->postJson('/api/contabilidade/rotinas/transferencia', ['diario_id' => $this->d['OD'], 'mes' => '2026-03',
            'linhas' => [['conta_origem' => '2113', 'conta_destino' => '2114', 'nota_destino_id' => $this->d['nota']]]], $this->s)->assertOk()->json('dados');
        $t = $r['transferencias'][0];
        $this->assertSame(['TRF-202603', '2026-03-31', '400.00', 3], [$r['documento'], $r['data_documento'], $t['saldo'], $t['movimentos_compensados']]);
        $this->emContexto(function () use ($t) {
            $linhas = LancamentoContabil::query()->where('numero_lan', $t['numero_lan'])->orderBy('id')->get();
            $this->assertSame(['2113 C 400.00', '2114 D 400.00'], $linhas->map(fn ($x) => "{$x->codigo_conta} {$x->tipo_dc} {$x->valor}")->all());
            $this->assertSame([$t['codigo'], null], [$linhas[0]->reconciliacao_codigo, $linhas[1]->reconciliacao_codigo]);
            $this->assertSame($this->d['nota'], $linhas[1]->nota_demonstracao_id);
            $this->assertSame(4, LancamentoContabil::query()->where('reconciliacao_codigo', $t['codigo'])->count());
        });
        $this->assertSame('0.00', $this->postJson('/api/contabilidade/rotinas/transferencia/saldos', ['mes' => '2026-03', 'contas' => ['2113']], $this->s)->json('dados.0.saldo'));

        $a = $this->postJson('/api/contabilidade/rotinas/anular', ['codigo' => $t['codigo'], 'motivo' => 'Destino errado'], $this->s)->assertOk()->json('dados');
        $this->assertSame([4, [$t['numero_lan']]], [$a['linhas_libertadas'], $a['estornados']]);
        $this->assertSame('400.00', $this->postJson('/api/contabilidade/rotinas/transferencia/saldos', ['mes' => '2026-03', 'contas' => ['2113']], $this->s)->json('dados.0.saldo'));
    }

    #[Test]
    public function actualizacao_em_massa_valida_regista_e_anula(): void
    {
        $l = $this->lancar('OD', '2026-03-03', [['2113', 'D', 300], ['4511', 'C', 300]]);
        $outro = $this->lancar('OD', '2026-03-04', [['2113', 'D', 50], ['4511', 'C', 50]]);
        $r = $this->postJson('/api/contabilidade/rotinas/actualizacao-massa', ['linhas' => [
            ['lancamento_id' => $l[0], 'campo_a_modificar' => 'description', 'novo_valor' => 'Nova descrição'],
            ['lancamento_id' => $l[0], 'campo_a_modificar' => 'account_code', 'novo_valor' => '2114'],
            ['lancamento_id' => $l[1], 'campo_a_modificar' => 'nota_demonstracao_id', 'novo_valor' => $this->d['nota']],
            ['lancamento_id' => $l[1], 'campo_a_modificar' => 'account_code', 'novo_valor' => '31'],
            ['lancamento_id' => $l[1], 'campo_a_modificar' => 'value', 'novo_valor' => 1],
            ['lancamento_id' => 999999, 'campo_a_modificar' => 'description', 'novo_valor' => 'x'],
            ['lancamento_id' => $l[1], 'campo_a_modificar' => 'third_party_id', 'novo_valor' => 999999],
        ]], $this->s)->assertOk()->json('dados');
        $this->assertSame(3, $r['actualizadas']);
        $this->assertSame([5, 6, 7, 8], array_column($r['erros'], 'linha'));
        $this->emContexto(function () use ($l) {
            $x = LancamentoContabil::query()->find($l[0]);
            $this->assertSame(['Nova descrição', '2114'], [$x->descricao, $x->codigo_conta]);
            $this->assertSame($this->d['nota'], LancamentoContabil::query()->find($l[1])->nota_demonstracao_id);
        });

        $this->postJson('/api/contabilidade/rotinas/actualizacao-massa', ['linhas' => [
            ['lancamento_id' => $outro[0], 'campo_a_modificar' => 'journal_id', 'novo_valor' => $this->d['VD']],
        ]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTOS_DESEQUILIBRADOS');
        $this->emContexto(fn () => $this->assertSame($this->d['OD'], LancamentoContabil::query()->find($outro[0])->diario_id));

        $this->postJson('/api/contabilidade/rotinas/anular', ['codigo' => $r['codigo'], 'motivo' => 'Ficheiro errado'], $this->s)->assertOk()->assertJsonPath('dados.alteracoes_revertidas', 3);
        $this->emContexto(function () use ($l) {
            $x = LancamentoContabil::query()->find($l[0]);
            $this->assertSame('2113', $x->codigo_conta);
            $this->assertNull(LancamentoContabil::query()->find($l[1])->nota_demonstracao_id);
        });
        $this->postJson('/api/contabilidade/rotinas/actualizacao-massa', ['linhas' => [['lancamento_id' => $l[0], 'campo_a_modificar' => 'description', 'novo_valor' => 'y']]],
            $this->sessao(['contab_rotinas_view']))->assertForbidden();
    }

    #[Test]
    public function limpeza_de_reconciliacoes_so_pre_visualiza(): void
    {
        $a = $this->lancar('CB', '2026-03-03', [['4311', 'D', 300], ['6111', 'C', 300]]);
        $b = $this->lancar('CB', '2026-03-04', [['4311', 'D', 50], ['6111', 'C', 50]]);
        $this->emContexto(function () use ($a, $b) {
            LancamentoContabil::query()->whereKey($a[0])->update(['reconciliacao_codigo' => 'AUTO_RECON_1_1']);
            LancamentoContabil::query()->whereKey($b[0])->update(['reconciliacao_codigo' => 'REC-OFICIAL']);
            ReconciliacaoBancaria::create(['reconciliacao_codigo' => 'REC-OFICIAL', 'estado' => 'CONCILIADO_BANCO']);
        });
        $r = $this->getJson('/api/contabilidade/rotinas/limpeza-reconciliacoes', $this->s)->assertOk()->json('dados');
        $this->assertSame([$a[0]], array_column($r['linhas'], 'id'));
        $this->emContexto(fn () => $this->assertSame('AUTO_RECON_1_1', LancamentoContabil::query()->find($a[0])->reconciliacao_codigo));
    }
}
