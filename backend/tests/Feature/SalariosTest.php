<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LancamentoContabil;
use App\Models\MapeamentoContabilRH;
use App\Models\MapeamentoContabilSistemaRH;
use App\Models\PlanoConta;
use App\Models\TipoOrganizacaoRH;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Salários (parte 1a): ciclo do período, lançamentos, fotografia imutável, contabilização equilibrada e estorno. */
final class SalariosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    private string $mes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mes = now()->format('m/Y');
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['7211' => 'Remunerações', '7212' => 'Subsídios', '7221' => 'Encargos INSS', '3611' => 'Remunerações a pagar', '3421' => 'IRT', '3431' => 'INSS a pagar',
                '3612' => 'Adiantamentos'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores']);
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true']);
            $alim = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de alimentação', 'sujeito_inss' => false, 'irt' => 'conditional_30k']);
            $adi = InfotipoSalarial::create(['tipo' => 'DESCONTO', 'nome' => 'Adiantamento', 'sujeito_inss' => false, 'irt' => 'false']);
            $ana = Colaborador::create(['nome_completo' => 'Ana', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org->id, 'dias_uteis_mes' => 22]);
            $inativo = Colaborador::create(['nome_completo' => 'Rui', 'estado' => 'INACTIVO', 'tipo_organizacao_id' => $org->id, 'dias_uteis_mes' => 22]);
            foreach ([$ana, $inativo] as $c) {
                ContratoTrabalho::create(['colaborador_id' => $c->id, 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8,
                    'data_inicio' => now()->subYear()->toDateString(), 'remuneracoes' => [['infotype_id' => $base->id, 'value_month' => 300000], ['infotype_id' => $alim->id, 'value_month' => 40000]]]);
            }
            $this->ids = ['org' => $org->id, 'base' => $base->id, 'alim' => $alim->id, 'adi' => $adi->id, 'ana' => $ana->id];
        });
        $this->s = $this->sessao(['calcular_view', 'processamento_view', 'calcular_lancar', 'calcular_folha', 'rh_lanc_del', 'processamento_validate',
            'processamento_integrate', 'processamento_reopen', 'contab_lanc_del', 'rh_recibos_emitir']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function mapear(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['base' => '7211', 'alim' => '7212', 'adi' => '3612'] as $k => $conta) {
                MapeamentoContabilRH::create(['infotipo_salarial_id' => $this->ids[$k], 'tipo_organizacao_id' => $this->ids['org'], 'avencado' => false, 'numero_conta' => $conta]);
            }
            foreach (['NET_PAY_CREDIT' => '3611', 'IRT_CREDIT' => '3421', 'INSS_FUNC_CREDIT' => '3431', 'INSS_EMP_DEBIT' => '7221', 'INSS_EMP_CREDIT' => '3431'] as $c => $conta) {
                MapeamentoContabilSistemaRH::create(['codigo' => $c, 'tipo_organizacao_id' => $this->ids['org'], 'avencado' => false, 'numero_conta' => $conta]);
            }
        });
    }

    #[Test]
    public function ciclo_completo_com_fotografia_contabilizacao_e_estorno(): void
    {
        $p = $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => $this->mes], $this->s)->assertCreated()->assertJsonPath('dados.estado', 'ABERTO')->json('dados.id');
        $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => $this->mes], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_EXISTENTE');

        $imp = $this->postJson("/api/rh/salarios/periodos/{$p}/importar-contratos", [], $this->s)->assertOk()->assertJsonPath('dados.criados', 2)->json('dados');
        $this->assertCount(0, $imp['ignorados']);   // o inactivo nem entra na lista de activos
        $this->postJson("/api/rh/salarios/periodos/{$p}/importar-contratos", [], $this->s)->assertJsonPath('dados.criados', 0)->assertJsonPath('dados.ja_existentes', 2);
        $this->postJson("/api/rh/salarios/periodos/{$p}/lancamentos", ['colaborador_id' => $this->ids['ana'], 'infotipo_salarial_id' => $this->ids['adi'], 'valor' => 50000], $this->s)->assertCreated();

        // cálculo ao vivo: 340 000 bruto; INSS 9 000; isenção 30 000; base IRT 301 000 → IRT 49 440; líquido 340 000 − 9 000 − 49 440 − 50 000
        $this->getJson("/api/rh/salarios/periodos/{$p}", $this->s)->assertJsonPath('dados.fotografia', false)->assertJsonPath('dados.totais.liquido', '231560.00')
            ->assertJsonPath('dados.resultados.0.irt', '49440.00');

        $this->postJson("/api/rh/salarios/periodos/{$p}/validar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_ESTADO_INVALIDO');   // exige FECHADO
        $this->postJson("/api/rh/salarios/periodos/{$p}/encerrar", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'FECHADO');
        $this->postJson("/api/rh/salarios/periodos/{$p}/lancamentos", ['colaborador_id' => $this->ids['ana'], 'infotipo_salarial_id' => $this->ids['adi'], 'valor' => 1], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_ESTADO_INVALIDO');

        // fotografia imutável: mudar o contrato e a rubrica não altera o período encerrado (no legado mudava)
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => InfotipoSalarial::query()->whereKey($this->ids['base'])->update(['sujeito_inss' => false]));
        $this->getJson("/api/rh/salarios/periodos/{$p}", $this->s)->assertJsonPath('dados.fotografia', true)->assertJsonPath('dados.totais.inss_trabalhador', '9000.00');

        $this->getJson("/api/rh/salarios/periodos/{$p}/recibos/{$this->ids['ana']}", $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_NAO_VALIDADO');
        $this->postJson("/api/rh/salarios/periodos/{$p}/validar", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'VALIDADO');
        $this->getJson("/api/rh/salarios/periodos/{$p}/recibos/{$this->ids['ana']}", $this->s)->assertOk()->assertJsonPath('dados.liquido', '231560.00');

        $this->postJson("/api/rh/salarios/periodos/{$p}/contabilizar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MAPEAMENTO_EM_FALTA');
        $this->mapear();
        $lan = $this->postJson("/api/rh/salarios/periodos/{$p}/contabilizar", [], $this->s)->assertOk()->assertJsonPath('dados.contabilizado', true)->json('dados.numero_lan_contabilizacao');
        $linhas = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $lan)->orderBy('codigo_conta')->orderBy('tipo_dc')->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
        $this->assertEqualsCanonicalizing(['D 7211 300000.00', 'D 7212 40000.00', 'D 7221 24000.00', 'C 3612 50000.00', 'C 3611 231560.00', 'C 3421 49440.00',
            'C 3431 33000.00'], $linhas);   // INSS do trabalhador + patronal agregados na mesma conta (364 000 = 364 000)
        $this->assertStringStartsWith('SAL', $lan);

        $this->postJson("/api/rh/salarios/periodos/{$p}/reabrir", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_CONTABILIZADO');
        $this->postJson("/api/rh/salarios/periodos/{$p}/descontabilizar", ['motivo' => 'Erro nos subsídios'], $this->s)->assertOk()->assertJsonPath('dados.contabilizado', false);
        $this->postJson("/api/rh/salarios/periodos/{$p}/reabrir", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ABERTO');
        // reaberto, volta a calcular com os dados actuais (sem INSS na base agora)
        $this->getJson("/api/rh/salarios/periodos/{$p}", $this->s)->assertJsonPath('dados.fotografia', false)->assertJsonPath('dados.totais.inss_trabalhador', '0.00');
    }

    #[Test]
    public function permissoes_e_segregacao_basica(): void
    {
        $p = $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => $this->mes], $this->s)->json('dados.id');
        $so = $this->sessao(['calcular_view', 'calcular_lancar']);
        $this->postJson("/api/rh/salarios/periodos/{$p}/encerrar", [], $so)->assertForbidden();
        $this->postJson("/api/rh/salarios/periodos/{$p}/contabilizar", [], $so)->assertForbidden();
        $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => '13/2026'], $this->s)->assertStatus(422);
    }
}
