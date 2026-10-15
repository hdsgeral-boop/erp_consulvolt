<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\TipoOrganizacaoRH;
use App\Services\RH\RegrasContrato;
use App\Services\RH\ServicoEstadoContrato;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase B (Versão Actual) — suspensão e cessação do contrato (contrato_estado.js; Lei 12/23) e o efeito em quem é
 * processado na folha (regras_salariais.js:95). Dados fictícios.
 */
final class FbRhContratoEstadoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $mk = fn (string $n, string $nif) => Colaborador::create(['nome_completo' => $n, 'nif' => $nif, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22])->id;
            $ana = $mk('Ana Fictícia', '900000001LA001');
            $rui = $mk('Rui Fictício', '900000002LA002');
            $ct = fn (int $c) => ContratoTrabalho::create(['colaborador_id' => $c, 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2018-01-10',
                'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 300000]]])->id;
            $this->ids = ['org' => $org, 'base' => $base, 'ana' => $ana, 'rui' => $rui, 'ct_ana' => $ct($ana), 'ct_rui' => $ct($rui)];
        });
        $this->s = [
            'tudo' => $this->sessao(['contratos_view', 'contratos_new', 'contratos_suspender', 'contratos_terminate', 'calcular_view', 'calcular_folha']),
            'ver' => $this->sessao(['contratos_view', 'contratos_new']),
        ];
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function periodo(string $mesAno): int
    {
        return $this->em(fn () => PeriodoProcessamentoSalarial::create(['mes_ano' => $mesAno, 'estado' => 'ABERTO', 'contabilizado' => false, 'modo_calculo' => 'ATUAL'])->id);
    }

    #[Test]
    public function antiguidade_e_regras_de_compensacao_da_lei_12_23(): void
    {
        // art. 311.º: anos completos + 1 se a fracção for ≥ 3 meses
        $this->assertSame(8, ServicoEstadoContrato::antiguidadeAnos('2018-01-10', '2026-03-09'));   // 8 anos e 1 mês
        $this->assertSame(9, ServicoEstadoContrato::antiguidadeAnos('2018-01-10', '2026-04-10'));   // 8 anos e 3 meses
        $this->assertSame(0, ServicoEstadoContrato::antiguidadeAnos('2026-01-10', '2025-01-01'));
        $this->assertSame('900000.00', ServicoEstadoContrato::valorCompensacao('ART307', '300000.00', 6));    // 0,5 × SB × 6
        $this->assertSame('2100000.00', ServicoEstadoContrato::valorCompensacao('ART308', '300000.00', 9));   // SB × 5 + 0,5 × SB × 4
        $this->assertSame('900000.00', ServicoEstadoContrato::valorCompensacao('ART310', '300000.00', 2));    // mínimo 3 × SB
        $this->assertSame('1500000.00', ServicoEstadoContrato::valorCompensacao('ART310', '300000.00', 5));
    }

    #[Test]
    public function suspender_exige_permissao_propria_e_tira_do_processamento_o_mes_todo_suspenso(): void
    {
        $url = "/api/rh/contratos/{$this->ids['ct_ana']}/suspender";
        $this->postJson($url, ['de' => '2026-05-01', 'motivo_codigo' => 'DOENCA'], $this->s['ver'])->assertForbidden();
        $this->postJson($url, ['de' => '2026-05-01', 'motivo_codigo' => 'INVENTADO'], $this->s['tudo'])->assertStatus(422);
        $this->postJson($url, ['de' => '2026-05-10', 'ate' => '2026-05-01', 'motivo_codigo' => 'DOENCA'], $this->s['tudo'])->assertStatus(422)->assertJsonPath('codigo', 'DATAS_INVALIDAS');
        $this->postJson($url, ['de' => '2026-05-01', 'ate' => '2026-06-10', 'motivo_codigo' => 'DOENCA', 'observacoes' => 'Baixa médica'], $this->s['tudo'])->assertOk()
            ->assertJsonPath('dados.estado', 'SUSPENSO')->assertJsonPath('dados.suspensao.artigo', 'art. 267.º')->assertJsonPath('dados.suspensao.lado', 'Trabalhador');

        // Maio: suspenso o mês todo → fora; Junho: suspensão parcial → entra com aviso; Julho: fora da suspensão → entra
        $maio = $this->postJson("/api/rh/salarios/periodos/{$this->periodo('05/2026')}/importar-contratos", [], $this->s['tudo'])->assertOk()->json('dados');
        $this->assertSame(1, $maio['criados']);   // só o Rui
        $this->assertTrue(collect($maio['ignorados'])->contains(fn ($x) => str_contains($x, 'Ana Fictícia: contrato suspenso durante todo o mês')));
        $junho = $this->postJson("/api/rh/salarios/periodos/{$this->periodo('06/2026')}/importar-contratos", [], $this->s['tudo'])->assertOk()->json('dados');
        $this->assertSame(2, $junho['criados']);
        $this->assertTrue(collect($junho['avisos'])->contains(fn ($x) => str_contains($x, 'suspenso de 2026-06-01 a 2026-06-10')));
        $this->postJson("/api/rh/salarios/periodos/{$this->periodo('07/2026')}/importar-contratos", [], $this->s['tudo'])->assertJsonPath('dados.criados', 2);

        // editar não reactiva; o formulário não suspende nem cessa
        $dados = ['colaborador_id' => $this->ids['ana'], 'data_inicio' => '2018-01-10', 'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 310000]]];
        $this->putJson("/api/rh/contratos/{$this->ids['ct_ana']}", $dados + ['estado' => 'ACTIVO'], $this->s['tudo'])->assertOk()->assertJsonPath('dados.estado', 'SUSPENSO');
        $this->putJson("/api/rh/contratos/{$this->ids['ct_rui']}", array_merge($dados, ['colaborador_id' => $this->ids['rui'], 'estado' => 'SUSPENSO']), $this->s['tudo'])
            ->assertStatus(422)->assertJsonPath('codigo', 'USAR_ACTO_CONTRATO');

        $this->postJson("/api/rh/contratos/{$this->ids['ct_ana']}/reactivar", ['observacoes' => 'Alta médica'], $this->s['ver'])->assertForbidden();
        $r = $this->postJson("/api/rh/contratos/{$this->ids['ct_ana']}/reactivar", ['observacoes' => 'Alta médica'], $this->s['tudo'])->assertOk()
            ->assertJsonPath('dados.estado', 'ACTIVO')->assertJsonPath('dados.suspensao', null)->json('dados');
        $this->assertSame('Alta médica', $r['suspensoes'][0]['terminada_observacoes']);
        $this->assertSame('2026-06-10', $r['suspensoes'][0]['ate']);
    }

    #[Test]
    public function cessar_com_compensacao_inactiva_o_colaborador_e_processa_ate_a_cessacao(): void
    {
        $c = $this->ids['ct_ana'];
        $this->getJson("/api/rh/contratos/{$c}/compensacao?motivo_codigo=DESP_OBJECTIVO&data=2026-05-15", $this->s['ver'])->assertForbidden();
        $this->getJson("/api/rh/contratos/{$c}/compensacao?motivo_codigo=DESP_OBJECTIVO&data=2026-05-15", $this->s['tudo'])->assertOk()
            ->assertJsonPath('dados.anos', 9)->assertJsonPath('dados.salario_base', '300000.00')->assertJsonPath('dados.valor', '2100000.00')
            ->assertJsonPath('dados.artigo', 'art. 308.º')->assertJsonPath('dados.aplicavel', true);
        $this->getJson("/api/rh/contratos/{$c}/compensacao?motivo_codigo=DESP_DISCIPLINAR&data=2026-05-15", $this->s['tudo'])->assertJsonPath('dados.valor', '0.00')
            ->assertJsonPath('dados.aplicavel', false);

        // um processamento posterior com lançamentos é mostrado a quem decide
        $agosto = $this->periodo('08/2026');
        $this->em(fn () => LinhaFolhaSalarial::create(['periodo_processamento_salarial_id' => $agosto, 'colaborador_id' => $this->ids['ana'], 'infotipo_salarial_id' => $this->ids['base'],
            'valor' => '300000.00', 'origem' => 'MANUAL']));
        $this->postJson("/api/rh/contratos/{$c}/cessar", ['data' => '2017-12-31', 'motivo_codigo' => 'DESP_OBJECTIVO'], $this->s['tudo'])->assertStatus(422)->assertJsonPath('codigo', 'DATAS_INVALIDAS');
        $r = $this->postJson("/api/rh/contratos/{$c}/cessar", ['data' => '2026-05-15', 'motivo_codigo' => 'DESP_OBJECTIVO', 'compensacao_valor' => 2000000,
            'observacoes' => 'Extinção do posto'], $this->s['tudo'])->assertOk()->assertJsonPath('dados.estado', 'CESSADO')->json('dados');
        $this->assertSame('2026-05-15', substr($r['data_fim'], 0, 10));
        $this->assertSame('2000000.00', $r['cessacao']['compensacao_valor']);
        $this->assertSame('2100000.00', $r['cessacao']['compensacao_proposta']);
        $this->assertSame('08/2026', $r['cessacao']['processamentos_posteriores'][0]['periodo']);
        $colab = $this->em(fn () => Colaborador::query()->find($this->ids['ana']));
        $this->assertSame('INACTIVO', $colab->estado);
        $this->assertSame('2026-05-15', $colab->data_cessacao->toDateString());
        $this->postJson("/api/rh/contratos/{$c}/cessar", ['data' => '2026-05-15', 'motivo_codigo' => 'REVOGACAO'], $this->s['tudo'])->assertStatus(422)->assertJsonPath('codigo', 'CONTRATO_CESSADO');
        $this->postJson("/api/rh/contratos/{$c}/suspender", ['de' => '2026-06-01', 'motivo_codigo' => 'DOENCA'], $this->s['tudo'])->assertStatus(422)->assertJsonPath('codigo', 'CONTRATO_CESSADO');

        // Maio (mês da cessação): entra, com aviso; Junho: já não entra
        $maio = $this->postJson("/api/rh/salarios/periodos/{$this->periodo('05/2026')}/importar-contratos", [], $this->s['tudo'])->assertOk()->json('dados');
        $this->assertSame(2, $maio['criados']);
        $this->assertTrue(collect($maio['avisos'])->contains(fn ($x) => str_contains($x, 'cessado em 2026-05-15')));
        $this->postJson("/api/rh/salarios/periodos/{$this->periodo('06/2026')}/importar-contratos", [], $this->s['tudo'])->assertJsonPath('dados.criados', 1);

        // editar um contrato cessado mantém o estado e não deixa mudar a data de fim
        $dados = ['colaborador_id' => $this->ids['ana'], 'data_inicio' => '2018-01-10', 'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 300000]]];
        $this->putJson("/api/rh/contratos/{$c}", $dados + ['data_fim' => '2026-05-15', 'estado' => 'ACTIVO'], $this->s['tudo'])->assertOk()->assertJsonPath('dados.estado', 'CESSADO');
        $this->putJson("/api/rh/contratos/{$c}", $dados + ['data_fim' => null], $this->s['tudo'])->assertStatus(422)->assertJsonPath('codigo', 'CONTRATO_CESSADO');

        // anular a cessação: motivo obrigatório; repõe a data de fim anterior (sem fim) e o colaborador
        $this->postJson("/api/rh/contratos/{$c}/anular-cessacao", ['observacoes' => 'x'], $this->s['tudo'])->assertStatus(422);
        $a = $this->postJson("/api/rh/contratos/{$c}/anular-cessacao", ['observacoes' => 'Acordo de reintegração'], $this->s['tudo'])->assertOk()
            ->assertJsonPath('dados.estado', 'ACTIVO')->assertJsonPath('dados.data_fim', null)->assertJsonPath('dados.cessacao', null)->json('dados');
        $this->assertSame('Acordo de reintegração', $a['cessacoes_anuladas'][0]['anulada_observacoes']);
        $this->assertSame('ACTIVO', $this->em(fn () => Colaborador::query()->find($this->ids['ana']))->estado);
    }

    #[Test]
    public function anular_cessacao_recusa_quando_ja_ha_outro_contrato_por_cessar(): void
    {
        $c = $this->ids['ct_rui'];
        $this->postJson("/api/rh/contratos/{$c}/cessar", ['data' => '2026-03-31', 'motivo_codigo' => 'CAD_TERMO', 'inactivar_colaborador' => false], $this->s['tudo'])->assertOk()
            ->assertJsonPath('dados.cessacao.compensacao_artigo', 'art. 307.º');
        $this->assertSame('ACTIVO', $this->em(fn () => Colaborador::query()->find($this->ids['rui']))->estado);   // à escolha de quem regista
        $this->postJson('/api/rh/contratos', ['colaborador_id' => $this->ids['rui'], 'data_inicio' => '2026-04-01', 'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'],
            'valor_mes' => 320000]]], $this->s['tudo'])->assertCreated();
        $this->postJson("/api/rh/contratos/{$c}/anular-cessacao", ['observacoes' => 'Engano no registo'], $this->s['tudo'])->assertStatus(422)->assertJsonPath('codigo', 'OUTRO_CONTRATO_ACTIVO');

        $this->getJson("/api/rh/contratos/{$c}/estado", $this->s['ver'])->assertOk()->assertJsonPath('dados.estado', 'CESSADO')->assertJsonPath('dados.cessacao.motivo_codigo', 'CAD_TERMO');
        $this->getJson('/api/rh/contratos/fundamentos', $this->s['ver'])->assertOk()->assertJsonCount(12, 'dados.suspensao')->assertJsonCount(15, 'dados.cessacao');
        $this->getJson('/api/rh/contratos?estado=CESSADO', $this->s['ver'])->assertOk()->assertJsonCount(1, 'dados');
    }

    #[Test]
    public function contrato_em_vigor_e_vigente(): void
    {
        $this->em(function () {
            $c = ContratoTrabalho::query()->find($this->ids['ct_ana']);
            $c->forceFill(['estado' => 'SUSPENSO', 'suspensao' => ['de' => '2026-05-02', 'ate' => null]])->save();
            $lista = collect([$c->refresh()]);
            $this->assertNotNull(RegrasContrato::emVigor($lista, '2026-05-01', '2026-05-31'));   // dia 1 ainda não está suspenso
            $this->assertNull(RegrasContrato::emVigor($lista, '2026-06-01', '2026-06-30'));
            $this->assertSame($c->id, RegrasContrato::vigente($lista, '2026-06-15')?->id);   // para consulta, mesmo suspenso
            $this->assertTrue(RegrasContrato::suspensoEm($c, '2026-05-02'));
            $this->assertFalse(RegrasContrato::suspensoEm($c, '2026-05-01'));
        });
    }
}
