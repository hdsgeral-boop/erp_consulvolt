<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Decisões do utilizador (ronda 2): 22 (totais do balancete com «sem saldo zero») e 20 (plano de contas para o encerramento). */
final class ContabilidadeDecisoesRonda2Test extends TestCase
{
    private Empresa $empresa;

    private DiarioContabil $diario;

    private array $cab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['111', 'M'], ['311', 'M'], ['611', 'M'], ['62', 'T'], ['6211', 'M'], ['619', 'M'], ['769', 'T'], ['7691', 'M']] as [$c, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => "Conta {$c}", 'tipo' => $t]);
            }
            $this->diario = DiarioContabil::create(['codigo' => 'OD', 'descricao' => 'OPERAÇÕES DIVERSAS']);
        });
        $perfil = $this->criarPerfil(['_v2' => true, 'lancamentos_post' => true, 'contab_mapa_balancete_view' => true, 'relatorios_contabeis_view' => true,
            'encerramento_view' => true, 'contab_apurar' => true, 'contab_plano_gerir' => true, 'config_manutencao_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->cab = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function lancar(string $d, string $c, string $valor): void
    {
        $this->postJson('/api/contabilidade/lancamentos', ['diario_id' => $this->diario->id, 'data_documento' => '2026-03-01',
            'linhas' => [['codigo_conta' => $d, 'tipo_dc' => 'D', 'valor' => $valor], ['codigo_conta' => $c, 'tipo_dc' => 'C', 'valor' => $valor]]], $this->cab)->assertCreated();
    }

    #[Test]
    public function balancete_sem_saldo_zero_mantem_os_totais_de_todas_as_contas(): void
    {
        $this->lancar('111', '611', '1000.00');
        $this->lancar('311', '111', '1000.00');   // 111 fica com saldo zero
        $url = '/api/contabilidade/relatorios/balancete?data_inicio=2026-01-01&data_fim=2026-12-31';
        $todos = $this->getJson($url, $this->cab)->assertOk()->json('dados');
        $sem = $this->getJson($url.'&sem_saldo_zero=1', $this->cab)->assertOk()->json('dados');

        $this->assertNotNull(collect($todos['linhas'])->firstWhere('codigo_conta', '111'));
        $this->assertNull(collect($sem['linhas'])->firstWhere('codigo_conta', '111'));
        $this->assertSame('2000.00', $sem['totais']['debito']);
        $this->assertSame($todos['totais'], $sem['totais']);
    }

    #[Test]
    public function diagnostico_e_correccao_assistida_do_plano_para_o_encerramento(): void
    {
        $this->lancar('111', '611', '500.00');
        DB::table('lancamentos_contabeis')->insert(['empresa_id' => $this->empresa->id, 'diario_id' => $this->diario->id, 'data_documento' => '2026-04-01',
            'codigo_conta' => '62', 'tipo_dc' => 'D', 'valor' => 10, 'numero_lan' => 'OD2026999999']);   // movimento antigo numa totalizadora
        DB::table('lancamentos_contabeis')->insert(['empresa_id' => $this->empresa->id, 'diario_id' => $this->diario->id, 'data_documento' => '2026-04-01',
            'codigo_conta' => '111', 'tipo_dc' => 'C', 'valor' => 10, 'numero_lan' => 'OD2026999999']);

        $d = $this->getJson('/api/contabilidade/encerramento/2026/plano', $this->cab)->assertOk()->json('dados');
        $contas = collect($d['contas'])->keyBy('codigo');
        $this->assertFalse($d['pronto']);
        $this->assertSame('CRIAR', $contas['821']['accao']);        // 8xx em falta
        $this->assertSame('MANUAL', $contas['62']['accao']);        // totalizadora com subcontas (6211)
        $this->assertSame(1, $contas['62']['movimentos_ano']);

        // a validação de dados também o mostra
        $v = collect($this->getJson('/api/sistema/validacoes', $this->cab)->assertOk()->json('dados'))->firstWhere('codigo', 'plano_contas_apuramento');
        $this->assertGreaterThan(0, $v['ocorrencias']);

        $this->postJson('/api/contabilidade/encerramento/2026/plano/corrigir', ['converter' => ['62']], $this->cab)
            ->assertStatus(422)->assertJsonPath('codigo', 'CORRECCAO_PLANO_INVALIDA');
        $r = $this->postJson('/api/contabilidade/encerramento/2026/plano/corrigir', ['criar' => ['821', '881', '8219', '889']], $this->cab)->assertOk();
        $this->assertSame(['821', '881', '8219', '889'], $r->json('dados.criadas'));
        $restantes = collect($r->json('dados.diagnostico.contas'))->pluck('codigo')->all();
        $this->assertNotContains('821', $restantes);
        $this->assertTrue(app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PlanoConta::query()->where('codigo', '889')->where('tipo', 'M')->exists()));
    }
}
