<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\EmprestimoColaborador;
use App\Models\InfotipoSalarial;
use App\Models\LancamentoContabil;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PlanoConta;
use App\Models\PrestacaoEmprestimo;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Services\RH\ServicoEmprestimos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase B (Versão Actual) — empréstimos a colaboradores (emprestimos.js): pedido no portal → chefia → RH → plano francês →
 * desconto no processamento → conta corrente; e os separadores novos do portal (escala, contrato, empréstimos).
 * Dados fictícios.
 */
final class FbRhEmprestimosPortalTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->em(function () {
            foreach (['37211' => 'Empréstimos ao pessoal', '43111' => 'Banco fictício'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $desc = InfotipoSalarial::create(['tipo' => 'DESCONTO', 'nome' => 'Desconto de empréstimo', 'sujeito_inss' => false, 'irt' => 'false'])->id;
            $mk = fn (string $n, string $nif) => Colaborador::create(['nome_completo' => $n, 'nif' => $nif, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22,
                'data_admissao' => '2020-03-02'])->id;
            $ana = $mk('Ana Fictícia', '900000011LA011');
            $chefe = $mk('Chefe Fictício', '900000012LA012');
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'colaborador_responsavel_id' => $chefe, 'ativo' => true]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'ativo' => true]);
            Colaborador::query()->find($ana)->update(['unidade_organica_id' => $dep->id]);
            Colaborador::query()->find($chefe)->update(['unidade_organica_id' => $dir->id]);
            ContratoTrabalho::create(['colaborador_id' => $ana, 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2020-03-02',
                'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 300000]],
                'horario' => ['entrada' => '08:00', 'saida' => '17:00', 'periodos' => 1, 'intervalo_min' => 60, 'tolerancia_min' => 10, 'dias' => [1, 2, 3, 4, 5]]]);
            $diario = DiarioContabil::create(['codigo' => 'EMP', 'nome' => 'Empréstimos ao pessoal'])->id;
            $this->ids = ['base' => $base, 'desc' => $desc, 'ana' => $ana, 'chefe' => $chefe, 'diario' => $diario];
        });
        $rh = ['rh_portal_aprovar', 'rh_portal_gestao_view', 'rh_emprestimos_view', 'rh_emp_gerir', 'rh_emp_lancar', 'rh_emp_config', 'calcular_view', 'calcular_lancar', 'rh_lanc_del'];
        $this->s = ['ana' => $this->sessao(['rh_portal_usar'], $this->ids['ana']), 'chefe' => $this->sessao(['rh_portal_usar'], $this->ids['chefe']),
            'rh' => $this->sessao($rh), 'nada' => $this->sessao(['rh_portal_usar'])];
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function configurar(): void
    {
        $this->putJson('/api/rh/emprestimos/configuracao', ['conta_emprestimo' => '37211', 'conta_contrapartida' => '43111', 'diario_id' => $this->ids['diario'],
            'infotipo_desconto_id' => $this->ids['desc'], 'prestacoes_max' => 12, 'multiplo_salario_max' => 3, 'taxa_juro_padrao' => 0, 'exige_chefia' => true], $this->s['rh'])
            ->assertOk()->assertJsonPath('dados.por_configurar', []);
    }

    private function pedir(array $d): TestResponse
    {
        return $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'EMPRESTIMO', 'motivo' => 'Obras em casa'] + $d, $this->s['ana']);
    }

    #[Test]
    public function plano_frances_fecha_o_saldo_ao_centimo(): void
    {
        $p = ServicoEmprestimos::plano('100', 3, '0', '2026-11');
        $this->assertSame('33.33', $p['prestacao']);
        $this->assertSame(['33.33', '33.33', '33.34'], array_column($p['linhas'], 'total'));
        $this->assertSame(['2026-11', '2026-12', '2027-01'], array_column($p['linhas'], 'mes_ano'));
        $this->assertSame('0.00', end($p['linhas'])['saldo']);

        $p = ServicoEmprestimos::plano('100000', 12, '12', '2026-01');   // i = 1 % ao mês
        $this->assertSame('8884.88', $p['prestacao']);
        $this->assertSame('1000.00', $p['linhas'][0]['juro']);
        $this->assertSame('7884.88', $p['linhas'][0]['capital']);
        $this->assertSame('100000.00', array_reduce($p['linhas'], fn ($s, $l) => bcadd($s, $l['capital'], 2), '0.00'));
        $this->assertSame('0.00', end($p['linhas'])['saldo']);
        $this->assertSame(bcadd('100000.00', $p['total_juros'], 2), $p['total']);
    }

    #[Test]
    public function percurso_completo_do_pedido_ao_desconto_e_conta_corrente(): void
    {
        $proximo = now()->addMonthNoOverflow()->format('Y-m');
        $this->getJson('/api/rh/emprestimos', $this->s['nada'])->assertForbidden();
        $this->getJson('/api/rh/portal/emprestimos', $this->s['nada'])->assertForbidden();   // sem colaborador ligado
        $this->pedir(['capital' => 120000, 'prestacoes' => 3, 'inicio_desconto' => $proximo])->assertStatus(422)->assertJsonPath('codigo', 'EMPRESTIMOS_POR_CONFIGURAR');

        $this->putJson('/api/rh/emprestimos/configuracao', ['infotipo_desconto_id' => $this->ids['base'], 'prestacoes_max' => 12, 'multiplo_salario_max' => 3, 'taxa_juro_padrao' => 0,
            'exige_chefia' => true], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'RUBRICA_NAO_DESCONTO');
        $this->putJson('/api/rh/emprestimos/configuracao', ['prestacoes_max' => 12, 'multiplo_salario_max' => 3, 'taxa_juro_padrao' => 0, 'exige_chefia' => true], $this->s['ana'])->assertForbidden();
        $this->configurar();

        $this->getJson('/api/rh/portal/emprestimos', $this->s['ana'])->assertOk()->assertJsonPath('dados.limites.tecto', '900000.00')->assertJsonPath('dados.limites.prestacoes_max', 12);
        $this->pedir(['capital' => 900001, 'prestacoes' => 3, 'inicio_desconto' => $proximo])->assertStatus(422)->assertJsonPath('codigo', 'CAPITAL_ACIMA_DO_LIMITE');
        $this->pedir(['capital' => 120000, 'prestacoes' => 13, 'inicio_desconto' => $proximo])->assertStatus(422)->assertJsonPath('codigo', 'PRESTACOES_ACIMA_DO_MAXIMO');
        $this->pedir(['capital' => 120000, 'prestacoes' => 3, 'inicio_desconto' => now()->subMonthNoOverflow()->format('Y-m')])->assertStatus(422)->assertJsonPath('codigo', 'MES_NO_PASSADO');
        $this->getJson("/api/rh/portal/emprestimos/simular?capital=120000&prestacoes=3&inicio_desconto={$proximo}", $this->s['ana'])->assertOk()->assertJsonPath('dados.prestacao', '40000.00');

        $pedido = $this->pedir(['capital' => 120000, 'prestacoes' => 3, 'inicio_desconto' => $proximo])->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE_CHEFIA')
            ->assertJsonPath('dados.dados.valor_prestacao', '40000.00')->json('dados');
        $this->pedir(['capital' => 1000, 'prestacoes' => 1, 'inicio_desconto' => $proximo])->assertStatus(422)->assertJsonPath('codigo', 'EMPRESTIMO_EM_CURSO');
        $id = $pedido['emprestimo_id'];
        $this->assertMatchesRegularExpression('/^EMP \d{4}\/001$/', $pedido['dados']['numero']);

        // chefia → RH; o próprio não decide; aprovado gera o plano
        $this->postJson("/api/rh/portal/pedidos/{$pedido['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['ana'])->assertForbidden();
        $this->postJson("/api/rh/portal/pedidos/{$pedido['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['chefe'])->assertOk()->assertJsonPath('dados.estado', 'PENDENTE_RH');
        $this->assertSame('PENDENTE_RH', $this->em(fn () => EmprestimoColaborador::query()->find($id)->estado));
        $this->postJson("/api/rh/portal/pedidos/{$pedido['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['rh'])->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $e = $this->getJson("/api/rh/emprestimos/{$id}", $this->s['rh'])->assertOk()->assertJsonPath('dados.estado', 'APROVADO')->assertJsonPath('dados.em_divida', '120000.00')->json('dados');
        $this->assertCount(3, $e['prestacoes_plano']);
        $this->assertSame($proximo, $e['prestacoes_plano'][0]['mes_ano']);

        // conta corrente: D 37211 / C 43111 pelo capital, uma só vez
        $this->postJson("/api/rh/emprestimos/{$id}/conta-corrente", ['data' => now()->toDateString()], $this->s['ana'])->assertForbidden();
        $ref = $this->postJson("/api/rh/emprestimos/{$id}/conta-corrente", ['data' => now()->toDateString()], $this->s['rh'])->assertOk()->json('dados.lancamento_referencia');
        $linhas = $this->em(fn () => LancamentoContabil::query()->where('numero_lan', $ref)->orderBy('tipo_dc')->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
        $this->assertSame(['C 43111 120000.00', 'D 37211 120000.00'], $linhas);
        $this->postJson("/api/rh/emprestimos/{$id}/conta-corrente", [], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'JA_LANCADO');

        // desconto no processamento: importar, retirar o lançamento (a prestação volta a «por descontar»), anular
        $periodos = [];
        foreach ([0, 1, 2] as $k) {
            $m = now()->addMonthsNoOverflow($k + 1);
            $periodos[] = $this->em(fn () => PeriodoProcessamentoSalarial::create(['mes_ano' => $m->format('m/Y'), 'estado' => 'ABERTO', 'contabilizado' => false, 'modo_calculo' => 'ATUAL'])->id);
        }
        $this->getJson("/api/rh/salarios/periodos/{$periodos[0]}/emprestimos", $this->s['rh'])->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.total', '40000.00');
        $this->postJson("/api/rh/salarios/periodos/{$periodos[0]}/importar-emprestimos", [], $this->s['ana'])->assertForbidden();
        $this->postJson("/api/rh/salarios/periodos/{$periodos[0]}/importar-emprestimos", [], $this->s['rh'])->assertOk()->assertJsonPath('dados.lancados', 1)->assertJsonPath('dados.total', '40000.00');
        $this->postJson("/api/rh/salarios/periodos/{$periodos[0]}/importar-emprestimos", [], $this->s['rh'])->assertOk()->assertJsonPath('dados.lancados', 0);
        $linha = $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $periodos[0])->where('infotipo_salarial_id', $this->ids['desc'])->first());
        $this->assertSame('40000.00', (string) $linha->valor);
        $this->postJson("/api/rh/emprestimos/{$id}/cancelar", ['motivo' => 'Desistência do colaborador'], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'PRESTACOES_DESCONTADAS');
        $this->deleteJson("/api/rh/salarios/periodos/{$periodos[0]}/lancamentos/{$linha->id}", [], $this->s['rh'])->assertOk();
        $this->assertSame('PENDENTE', $this->em(fn () => PrestacaoEmprestimo::query()->where('emprestimo_id', $id)->where('numero', 1)->first()->estado));
        $this->postJson("/api/rh/salarios/periodos/{$periodos[0]}/importar-emprestimos", [], $this->s['rh'])->assertJsonPath('dados.lancados', 1);
        $this->postJson("/api/rh/salarios/periodos/{$periodos[0]}/anular-emprestimos", [], $this->s['rh'])->assertOk()->assertJsonPath('dados.repostas', 1);
        $this->assertSame(0, $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $periodos[0])->count()));

        // três meses descontados → LIQUIDADO
        foreach ($periodos as $p) {
            $this->postJson("/api/rh/salarios/periodos/{$p}/importar-emprestimos", [], $this->s['rh'])->assertJsonPath('dados.lancados', 1);
        }
        $this->getJson("/api/rh/emprestimos/{$id}", $this->s['rh'])->assertJsonPath('dados.estado', 'LIQUIDADO')->assertJsonPath('dados.em_divida', '0.00')->assertJsonPath('dados.descontadas', 3);
        $this->getJson('/api/rh/portal/emprestimos', $this->s['ana'])->assertOk()->assertJsonPath('dados.emprestimos.0.estado', 'LIQUIDADO')->assertJsonCount(3, 'dados.emprestimos.0.plano');
        // retirar o desconto do último mês reabre o empréstimo
        $this->postJson("/api/rh/salarios/periodos/{$periodos[2]}/anular-emprestimos", [], $this->s['rh'])->assertJsonPath('dados.repostas', 1);
        $this->getJson("/api/rh/emprestimos/{$id}", $this->s['rh'])->assertJsonPath('dados.estado', 'APROVADO')->assertJsonPath('dados.em_divida', '40000.00');
    }

    #[Test]
    public function recusa_cancelamento_e_pedido_sem_chefia(): void
    {
        $this->configurar();
        $proximo = now()->addMonthNoOverflow()->format('Y-m');
        $p = $this->pedir(['capital' => 60000, 'prestacoes' => 2, 'inicio_desconto' => $proximo])->assertCreated()->json('dados');
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/cancelar", [], $this->s['ana'])->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
        $this->assertSame('CANCELADO', $this->em(fn () => EmprestimoColaborador::query()->find($p['emprestimo_id'])->estado));

        // sem exigir a chefia, o pedido vai directamente ao RH; recusado com motivo
        $this->putJson('/api/rh/emprestimos/configuracao', ['conta_emprestimo' => '37211', 'conta_contrapartida' => '43111', 'diario_id' => $this->ids['diario'],
            'infotipo_desconto_id' => $this->ids['desc'], 'prestacoes_max' => 6, 'multiplo_salario_max' => 0, 'taxa_juro_padrao' => 12, 'exige_chefia' => false], $this->s['rh'])->assertOk();
        $p = $this->pedir(['capital' => 2000000, 'prestacoes' => 6, 'inicio_desconto' => $proximo])->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE_RH')->json('dados');
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'RECUSADO'], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'NOTA_EM_FALTA');
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'RECUSADO', 'nota' => 'Acima da política interna'], $this->s['rh'])->assertOk();
        $this->getJson('/api/rh/emprestimos?estado=RECUSADO', $this->s['rh'])->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.nome', 'Ana Fictícia')
            ->assertJsonPath('dados.0.taxa_juro', '12.0000');

        // RH cancela um empréstimo aprovado sem descontos
        $p = $this->pedir(['capital' => 30000, 'prestacoes' => 3, 'inicio_desconto' => $proximo])->assertCreated()->json('dados');
        $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", ['decisao' => 'APROVADO'], $this->s['rh'])->assertOk();
        $this->postJson("/api/rh/emprestimos/{$p['emprestimo_id']}/cancelar", ['motivo' => 'Desistência'], $this->s['ana'])->assertForbidden();
        $this->postJson("/api/rh/emprestimos/{$p['emprestimo_id']}/cancelar", ['motivo' => 'Desistência'], $this->s['rh'])->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
    }

    #[Test]
    public function portal_mostra_o_contrato_vigente_e_a_escala(): void
    {
        $c = $this->getJson('/api/rh/portal/contrato', $this->s['ana'])->assertOk()->json('dados.contrato');
        $this->assertSame('ACTIVO', $c['estado']);
        $this->assertSame('300000.00', $c['total_mensal']);
        $this->assertSame('13636.36', $c['valor_dia']);
        $this->assertSame(8.0, (float) $c['horario']['horas_previstas']);
        $this->assertSame('Salário Base', $c['remuneracoes'][0]['nome']);
        $this->getJson('/api/rh/portal/contrato', $this->s['nada'])->assertForbidden();

        // suspenso: o portal continua a mostrar o contrato (só consulta), com o período
        $this->em(fn () => ContratoTrabalho::query()->where('colaborador_id', $this->ids['ana'])->first()->forceFill(['estado' => 'SUSPENSO',
            'suspensao' => ['de' => '2020-03-02', 'ate' => null, 'motivo' => 'Doença ou acidente', 'artigo' => 'art. 267.º']])->save());
        $this->getJson('/api/rh/portal/contrato', $this->s['ana'])->assertOk()->assertJsonPath('dados.contrato.estado', 'SUSPENSO')
            ->assertJsonPath('dados.contrato.suspensao.artigo', 'art. 267.º');

        $this->postJson('/api/rh/assiduidade/escalas/aplicar', ['colaboradores' => [$this->ids['ana']], 'de' => '2025-06-07', 'entrada' => '08:00', 'saida' => '12:00'], $this->s['ana'])->assertForbidden();
        $e = $this->getJson('/api/rh/portal/escala?mes=2025-06', $this->s['ana'])->assertOk()->json('dados');
        $this->assertSame('2025-06', $e['mes']);
        $this->assertSame([], $e['dias']);
    }
}
