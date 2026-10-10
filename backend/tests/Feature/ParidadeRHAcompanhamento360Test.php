<?php

namespace Tests\Feature;

use App\Models\AutoavaliacaoColaborador;
use App\Models\AvaliacaoDesempenhoRH;
use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\ConfirmacaoAvaliacaoRH;
use App\Models\Empresa;
use App\Models\FeedbackAvaliacao360;
use App\Models\RespostaAvaliacao360;
use App\Models\TipoOrganizacaoRH;
use App\Services\RH\ServicoAcompanhamento360RH;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Paridade RH 6.5.10: «Acompanhamento do Ciclo 360º» — participação, reuniões de acompanhamento e comunicado. Dados fictícios. */
final class ParidadeRHAcompanhamento360Test extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Teste 360']);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n) => Colaborador::create(['nome_completo' => $n, 'nif' => "NIF360{$n}", 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org])->id;
            $chefe = $mk('Chefe');
            $ana = $mk('Ana');
            $rui = $mk('Rui');
            $c = CicloAvaliacao360::create(['nome' => 'Anual 2026', 'ano' => 2026, 'periodo' => 'ANUAL', 'estado' => 'ABERTO', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31',
                'prazos' => ['dias_contestacao' => 10], 'feedback' => ['periodicidade' => 'TRIMESTRAL'], 'comunicado' => ['titulo' => 'Abertura do ciclo', 'texto' => 'Texto do comunicado'],
                'participantes' => [
                    ['employee_id' => $chefe, 'nome' => 'Chefe', 'chefia_id' => null, 'pares' => [], 'subordinados' => [$ana, $rui]],
                    ['employee_id' => $ana, 'nome' => 'Ana', 'chefia_id' => $chefe, 'pares' => [$rui], 'subordinados' => []],
                    ['employee_id' => $rui, 'nome' => 'Rui', 'chefia_id' => $chefe, 'pares' => [$ana], 'subordinados' => []],
                ]]);
            AvaliacaoDesempenhoRH::create(['colaborador_id' => $ana, 'ano' => 2026, 'periodo' => 'ANUAL', 'ciclo_avaliacao_id' => $c->id, 'estado' => 'CONCLUIDA', 'pontuacao' => 4.2]);
            AutoavaliacaoColaborador::create(['colaborador_id' => $ana, 'ano' => 2026, 'periodo' => 'ANUAL', 'estado' => 'SUBMETIDA']);
            RespostaAvaliacao360::create(['uid' => (string) Str::uuid(), 'ciclo_avaliacao_id' => $c->id, 'colaborador_avaliado_id' => $ana, 'grupo' => 'PARES', 'notas' => []]);
            RespostaAvaliacao360::create(['uid' => (string) Str::uuid(), 'ciclo_avaliacao_id' => $c->id, 'colaborador_avaliado_id' => $chefe, 'grupo' => 'SUBORDINADOS', 'notas' => []]);
            FeedbackAvaliacao360::create(['ciclo_avaliacao_id' => $c->id, 'colaborador_id' => $ana, 'colaborador_chefia_id' => $chefe, 'periodo_referencia' => '2026-T1', 'data' => '2026-03-20',
                'acordos' => 'Acordos da reunião', 'confirmacao' => ['em' => '2026-03-21T10:00:00+01:00', 'comentario' => null]]);
            ConfirmacaoAvaliacaoRH::create(['ciclo_avaliacao_id' => $c->id, 'colaborador_id' => $ana, 'em' => '2026-02-01 09:00:00']);
            $this->ids = compact('chefe', 'ana', 'rui') + ['ciclo' => $c->id];
        });
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function acompanhamento_com_participacao_reunioes_e_comunicado(): void
    {
        $d = $this->getJson("/api/rh/paridade/avaliacao/ciclos/{$this->ids['ciclo']}/acompanhamento", $this->sessao(['rh_avaliacao_ciclo_view']))->assertOk()->json('dados');

        $ana = collect($d['participacao'])->firstWhere('colaborador_id', $this->ids['ana']);
        $this->assertSame(['Chefe', 'SUBMETIDA', 1, 1, 0, 0, 4.2], [$ana['chefia'], $ana['auto'], $ana['pares_recebidas'], $ana['pares_total'], $ana['subordinados_recebidas'], $ana['subordinados_total'], $ana['nota_chefia']]);
        $chefe = collect($d['participacao'])->firstWhere('colaborador_id', $this->ids['chefe']);
        $this->assertSame([1, 2, 'POR_AVALIAR'], [$chefe['subordinados_recebidas'], $chefe['subordinados_total'], $chefe['fase']]);

        $this->assertCount(1, $d['reunioes']['registadas']);
        $this->assertSame('2026-03-20', $d['reunioes']['registadas'][0]['data']);
        // trimestres já terminados (hoje > 30/06/2026): Ana tem T1; faltam Ana T2.., Rui T1.. (o chefe não tem chefia)
        $faltaRui = collect($d['reunioes']['em_falta'])->where('colaborador_id', $this->ids['rui'])->pluck('periodo_referencia')->all();
        $this->assertContains('2026-T1', $faltaRui);
        $this->assertNotContains('2026-T1', collect($d['reunioes']['em_falta'])->where('colaborador_id', $this->ids['ana'])->pluck('periodo_referencia')->all());
        $this->assertEmpty(collect($d['reunioes']['em_falta'])->where('colaborador_id', $this->ids['chefe'])->all());

        $this->assertSame('Abertura do ciclo', $d['comunicado']['titulo']);
        $this->assertCount(1, $d['comunicado']['confirmacoes']);
        $this->assertSame(2, $d['contadores']['comunicado']);
        $this->assertSame(count($d['reunioes']['em_falta']), $d['contadores']['reunioes']);
        $this->assertSame([3, 1, 2, 1], [$d['indicadores']['colaboradores'], $d['indicadores']['autoavaliacoes_submetidas'], $d['indicadores']['respostas_360'], $d['indicadores']['chefia_concluida']]);
    }

    #[Test]
    public function permissoes_e_isolamento_por_empresa(): void
    {
        $this->getJson("/api/rh/paridade/avaliacao/ciclos/{$this->ids['ciclo']}/acompanhamento", $this->sessao(['colaboradores_view']))->assertForbidden();

        $outra = $this->criarEmpresa(['nome' => 'Outra Empresa 360']);
        $perfil = $this->criarPerfil(['_v2' => true, 'rh_avaliacao_ciclo_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($outra->id);
        $this->getJson("/api/rh/paridade/avaliacao/ciclos/{$this->ids['ciclo']}/acompanhamento", $this->entrar($u) + ['X-Empresa-Id' => $outra->id])->assertNotFound();
    }

    #[Test]
    public function periodos_de_acompanhamento_pela_periodicidade(): void
    {
        $c = new CicloAvaliacao360(['data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'feedback' => ['periodicidade' => 'SEMESTRAL']]);
        $this->assertSame(['2026-S1', '2026-S2'], array_column(ServicoAcompanhamento360RH::periodosFeedback($c), 'ref'));
        $this->assertSame('2026-06-30', ServicoAcompanhamento360RH::periodosFeedback($c)[0]['fim']);
        $c->feedback = ['periodicidade' => 'MENSAL'];
        $this->assertCount(12, ServicoAcompanhamento360RH::periodosFeedback($c));
        $c->feedback = ['periodicidade' => 'NENHUM'];
        $this->assertSame([], ServicoAcompanhamento360RH::periodosFeedback($c));
    }
}
