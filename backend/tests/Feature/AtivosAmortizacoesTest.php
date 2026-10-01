<?php

namespace Tests\Feature;

use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Services\Ativos\CalculadoraAmortizacoes as C;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Activos (ADR-051): regra de cálculo das quotas, cálculo em rascunho, quota manual, integração no diário AM (um lançamento
 * equilibrado por período, agrupado por conta/UN/CC), integração individual, reabertura por estorno, verificação e mapas.
 * Activo A: 48 000 em 48 meses (quota 1 000) · Activo B: 1 000 em 3 meses (333,33 · 333,33 · 333,34).
 */
final class AtivosAmortizacoesTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000051']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['7315' => 'Amortizações do exercício', '1815' => 'Amortizações acumuladas', '68031' => 'Ganhos em imobilizações', '78031' => 'Perdas em imobilizações',
                '1141' => 'Equipamento administrativo'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['n29'] = NotaDemonstracao::create(['codigo' => '29', 'descricao' => 'Amortizações'])->id;
            $this->ids['n4'] = NotaDemonstracao::create(['codigo' => '4', 'descricao' => 'Imobilizações corpóreas'])->id;
        });
        $this->s = $this->sessao(['activos_view', 'activos_gerir', 'activos_eliminar', 'activos_cat_gerir', 'activos_amortizacoes_view', 'activos_amort_calcular',
            'activos_amort_integrar', 'activos_amort_anular', 'activos_mapa_view']);
        $this->ids['cat'] = $this->postJson('/api/ativos/categorias', ['nome' => 'Equipamento administrativo', 'taxa_anual' => 25, 'vida_util_padrao' => 48,
            'conta_gasto' => '7315', 'conta_amortizacao_acumulada' => '1815', 'conta_venda' => '68031', 'conta_perda' => '78031', 'conta_ativo' => '1141'], $this->s)
            ->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function bem(array $d): int
    {
        return $this->postJson('/api/ativos/bens', $d + ['categoria_ativo_id' => $this->ids['cat']], $this->s)->assertCreated()->json('dados.id');
    }

    /** Linhas activas (não estornadas) do diário AM por lançamento: "LAN|D conta valor". */
    private function lancamentos(): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->whereNull('estorno_de_id')->whereNull('estornado_por_id')
            ->orderBy('numero_lan')->orderBy('tipo_dc', 'desc')->get()->map(fn ($l) => "{$l->numero_lan}|{$l->numero_documento}|{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
    }

    #[Test]
    public function regra_de_calculo_reproduz_o_legado_com_as_correccoes(): void
    {
        $cat = new CategoriaAtivo(['taxa_anual' => 20]);
        $a = new AtivoImobilizado(['valor_aquisicao' => 1000, 'valor_residual' => 0, 'vida_util' => 3, 'data_aquisicao' => '2026-01-20', 'quota_fixa' => 0]);
        $this->assertNull(C::devida($a, $cat, 2025, 12, '0'));                       // antes do mês de aquisição
        $this->assertSame('333.33', C::devida($a, $cat, 2026, 1, '0'));              // o mês de aquisição conta inteiro
        $this->assertSame('333.34', C::devida($a, $cat, 2026, 3, '666.66'));         // último mês absorve o arredondamento
        $this->assertNull(C::devida($a, $cat, 2026, 4, '999.99'));                   // fora da vida útil
        $this->assertSame('100.00', C::devida($a, $cat, 2026, 2, '0', '100.00'));    // proposta (rascunho/manual)
        $this->assertSame('1.00', C::devida($a, $cat, 2026, 2, '999.00'));           // limitada ao valor por amortizar

        $meio = new AtivoImobilizado(['valor_aquisicao' => '759717.42', 'vida_util' => 84, 'data_aquisicao' => '2020-10-01', 'acumulado_fim_ano' => 2025]);
        $this->assertNull(C::devida($meio, $cat, 2025, 12, '0'));                    // ano coberto pela amortização inicial
        $this->assertSame('9044.26', C::devida($meio, $cat, 2026, 1, '569560.15')); // half-up exacto (o legado dava 9044,25)

        $fixa = new AtivoImobilizado(['valor_aquisicao' => 5000, 'vida_util' => 48, 'data_aquisicao' => '2026-01-01', 'quota_fixa' => 250]);
        $this->assertSame('250.00', C::devida($fixa, $cat, 2026, 5, '0'));
        $semVida = new AtivoImobilizado(['valor_aquisicao' => 12000, 'vida_util' => 0, 'data_aquisicao' => '2026-01-01']);
        $this->assertSame('200.00', C::devida($semVida, $cat, 2030, 1, '0'));        // pela taxa anual (o legado nunca amortizava)
        $this->assertSame('250.00', C::devida($semVida, null, 2030, 1, '0'));        // sem categoria: 25 %
        $residual = new AtivoImobilizado(['valor_aquisicao' => 1200, 'valor_residual' => 200, 'vida_util' => 10, 'data_aquisicao' => '2026-01-01']);
        $this->assertSame('100.00', C::devida($residual, $cat, 2026, 1, '0'));
        $this->assertNull(C::devida($residual, $cat, 2026, 5, '1000.00'));           // base esgotada
    }

    #[Test]
    public function calcula_em_rascunho_integra_por_periodo_e_reabre_por_estorno(): void
    {
        $a = $this->bem(['descricao' => 'Computador', 'data_aquisicao' => '2026-01-15', 'valor_aquisicao' => 48000, 'vida_util' => 48]);
        $b = $this->bem(['descricao' => 'Impressora', 'data_aquisicao' => '2026-01-10', 'valor_aquisicao' => 1000, 'vida_util' => 3]);
        $this->getJson("/api/ativos/bens/{$a}", $this->s)->assertOk()->assertJsonPath('dados.codigo', 'AST-001');

        $pend = $this->getJson('/api/ativos/amortizacoes/pendentes', $this->s)->assertOk()->json('dados.por_calcular');
        $this->assertSame(['periodo' => '01-2026', 'ativos' => 2, 'valor' => '1333.33'], $pend[0]);

        $r = $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['03-2026', '01-2026', '02-2026', '04-2026']], $this->s)->assertOk()->json('dados');
        $this->assertSame(['01-2026', '1333.33'], [$r[0]['periodo'], $r[0]['total']]);
        $this->assertSame(['03-2026', '1333.34'], [$r[2]['periodo'], $r[2]['total']]);
        $this->assertSame(['04-2026', 1, '1000.00'], [$r[3]['periodo'], $r[3]['criados'], $r[3]['total']]);

        // quota manual em rascunho, limitada ao valor por amortizar
        $this->putJson('/api/ativos/amortizacoes/quota', ['ativo_imobilizado_id' => $a, 'periodo' => '02-2026', 'valor' => 1200], $this->s)->assertOk();
        $this->putJson('/api/ativos/amortizacoes/quota', ['ativo_imobilizado_id' => $b, 'periodo' => '03-2026', 'valor' => 500], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'QUOTA_EXCEDE_BASE');
        // recalcular mantém o rascunho manual (como o legado)
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['02-2026']], $this->s)->assertOk()->assertJsonPath('dados.0.total', '1533.33');

        $pre = $this->getJson('/api/ativos/amortizacoes/pre-visualizacao?periodo=01-2026', $this->s)->assertOk()->json('dados');
        $this->assertSame(['7315', 'D', '1333.33'], [$pre['linhas'][0]['codigo_conta'], $pre['linhas'][0]['tipo_dc'], $pre['linhas'][0]['valor']]);
        $this->assertSame('AM-01-2026', $pre['numero_documento']);
        $this->assertArrayHasKey('unidade_negocio_codigo', $pre['linhas'][0]);   // códigos de UN/CC (ADR-064)
        $this->assertArrayHasKey('centro_custo_codigo', $pre['linhas'][0]);

        $int = $this->postJson('/api/ativos/amortizacoes/integrar', ['periodos' => ['02-2026', '01-2026']], $this->s)->assertOk()->json('dados');
        $this->assertSame(['AM2026000001', 'AM2026000002'], array_column($int, 'numero_lan'));
        $this->assertSame([
            'AM2026000001|AM-01-2026|D 7315 1333.33', 'AM2026000001|AM-01-2026|C 1815 1333.33',
            'AM2026000002|AM-02-2026|D 7315 1533.33', 'AM2026000002|AM-02-2026|C 1815 1533.33',
        ], $this->lancamentos());
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $l = LancamentoContabil::query()->where('numero_lan', 'AM2026000001')->get()->keyBy('tipo_dc');
            $this->assertSame([$this->ids['n29'], $this->ids['n4'], 'AMORTIZACOES'], [$l['D']->nota_demonstracao_id, $l['C']->nota_demonstracao_id, $l['D']->tipo_origem]);
        });
        $this->getJson("/api/ativos/bens/{$a}", $this->s)->assertJsonPath('dados.amortizacao_acumulada', '2200.00')->assertJsonPath('dados.bloqueado', true);
        $this->getJson('/api/ativos/amortizacoes?periodo=01-2026', $this->s)->assertJsonPath('dados.estado', 'INTEGRADO');

        // bloqueios: quota integrada, campos de cálculo e eliminação
        $this->putJson('/api/ativos/amortizacoes/quota', ['ativo_imobilizado_id' => $a, 'periodo' => '01-2026', 'valor' => 900], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'QUOTA_INTEGRADA');
        $this->putJson("/api/ativos/bens/{$a}", ['valor_aquisicao' => 50000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ATIVO_COM_AMORTIZACOES');
        $this->putJson("/api/ativos/bens/{$a}", ['descricao' => 'Computador portátil'], $this->s)->assertOk();
        $this->deleteJson("/api/ativos/bens/{$a}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ATIVO_COM_AMORTIZACOES');

        // reabrir = estorno; as quotas saem e podem ser recalculadas
        $re = $this->postJson('/api/ativos/amortizacoes/reabrir', ['periodos' => ['02-2026'], 'motivo' => 'Quota manual errada'], $this->s)->assertOk()->json('dados.0');
        $this->assertSame([2, ['AM2026000003']], [$re['retirados'], $re['estornos']]);
        $this->assertSame(['AM2026000001|AM-01-2026|D 7315 1333.33', 'AM2026000001|AM-01-2026|C 1815 1333.33'], $this->lancamentos());
        $this->getJson("/api/ativos/bens/{$a}", $this->s)->assertJsonPath('dados.amortizacao_acumulada', '1000.00');
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['02-2026']], $this->s)->assertOk()->assertJsonPath('dados.0.total', '1333.33');
        $this->getJson('/api/ativos/amortizacoes?periodo=02-2026', $this->s)->assertJsonPath('dados.estado', 'CALCULADO');
        $this->postJson('/api/ativos/amortizacoes/reabrir', ['periodos' => ['05-2026']], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_SEM_AMORTIZACOES');

        $v = $this->getJson('/api/ativos/amortizacoes/verificacao', $this->s)->assertOk()->json('dados');
        $this->assertSame([[], []], [$v['quotas_divergentes'], $v['acumulados_divergentes']]);

        // mapas: o anual mostra rascunhos assinalados; o fiscal só as quotas integradas
        $mapa = $this->getJson('/api/ativos/mapas/amortizacoes?ano=2026', $this->s)->assertOk()->json('dados');
        $linhaA = collect($mapa['linhas'])->firstWhere('ativo_imobilizado_id', $a);
        $this->assertSame([true, false, '4000.00'], [$linhaA['meses'][1]['contabilizado'], $linhaA['meses'][2]['contabilizado'], $linhaA['ano']]);
        $this->assertSame(['1333.34', '5000.00'], [$mapa['totais']['meses'][3], $mapa['totais']['ano']]);
        $fiscal = collect($this->getJson('/api/ativos/mapas/fiscal?ano=2026', $this->s)->assertOk()->json('dados.linhas'))->firstWhere('ativo_imobilizado_id', $a);
        $this->assertSame(['1000.00', '47000.00', '1141'], [$fiscal['exercicio'], $fiscal['liquido'], $fiscal['conta']]);
        $this->assertSame([], $this->getJson('/api/ativos/mapas/fiscal?ano=2025', $this->s)->json('dados.linhas'));
    }

    #[Test]
    public function integracao_individual_gera_um_lancamento_equilibrado_por_mes(): void
    {
        $c = $this->bem(['descricao' => 'Viatura', 'data_aquisicao' => '2025-11-05', 'valor_aquisicao' => 3600, 'vida_util' => 36]);
        $r = $this->postJson("/api/ativos/bens/{$c}/amortizacoes/integrar", ['ate' => '01-2026'], $this->s)->assertOk()->json('dados');
        $this->assertSame(['11-2025', '12-2025', '01-2026'], array_column($r, 'periodo'));
        $this->assertSame(['AM2025000001', 'AM2025000002', 'AM2026000001'], array_column($r, 'numero_lan'));
        foreach (collect($this->lancamentos())->groupBy(fn ($l) => explode('|', $l)[0]) as $linhas) {
            $this->assertSame(['D 7315 100.00', 'C 1815 100.00'], $linhas->map(fn ($l) => explode('|', $l)[2])->all());
        }
        $this->postJson("/api/ativos/bens/{$c}/amortizacoes/integrar", ['ate' => '01-2026'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_RASCUNHOS');
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['01-2026']], $this->s)->assertOk()->assertJsonPath('dados.0.criados', 0);
        $this->getJson("/api/ativos/bens/{$c}", $this->s)->assertJsonPath('dados.amortizacao_acumulada', '300.00');
    }

    #[Test]
    public function integracao_exige_contas_na_categoria_e_permissoes(): void
    {
        $semContas = $this->postJson('/api/ativos/categorias', ['nome' => 'Sem contas', 'taxa_anual' => 10], $this->s)->assertCreated()->json('dados.id');
        $this->bem(['descricao' => 'Mesa', 'data_aquisicao' => '2026-01-01', 'valor_aquisicao' => 1200, 'vida_util' => 12, 'categoria_ativo_id' => $semContas]);
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['01-2026']], $this->s)->assertOk();
        $this->postJson('/api/ativos/amortizacoes/integrar', ['periodos' => ['01-2026']], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CATEGORIA_SEM_CONTAS');
        $this->assertSame([], $this->lancamentos());

        $consulta = $this->sessao(['activos_amortizacoes_view']);
        $this->getJson('/api/ativos/amortizacoes?periodo=01-2026', $consulta)->assertOk()->assertJsonPath('dados.estado', 'CALCULADO');
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['01-2026']], $consulta)->assertForbidden();
        $this->postJson('/api/ativos/amortizacoes/integrar', ['periodos' => ['01-2026']], $consulta)->assertForbidden();
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['13-2026']], $this->s)->assertStatus(422);
    }
}
