<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade com o legado (verificação visual de 2026-10-08, 13.4.1 e 13.6.1): colunas «Data Aquisição» e «Amort. Acumulada»
 * do ecrã de amortizações, lista dos períodos registados para a «Reabertura em Massa» e Mapa Fiscal do ano.
 * Dados fictícios: um activo de 48 000 Kz em 48 meses (quota 1 000).
 */
final class ParidadeActivosFiscalTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000099']);
        $this->prepararPlano($this->empresa);
        $this->s = $this->sessao($this->empresa, ['activos_view', 'activos_gerir', 'activos_cat_gerir', 'activos_amortizacoes_view', 'activos_amort_calcular',
            'activos_amort_integrar', 'activos_amort_anular', 'activos_mapa_view']);
        $this->cat = $this->categoria($this->s);
    }

    private function prepararPlano(Empresa $e): void
    {
        app(ContextoEmpresa::class)->executarComo($e->id, function () {
            foreach (['7315' => 'Amortizações do exercício', '1815' => 'Amortizações acumuladas', '68031' => 'Ganhos', '78031' => 'Perdas', '1141' => 'Equipamento'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            NotaDemonstracao::create(['codigo' => '29', 'descricao' => 'Amortizações']);
            NotaDemonstracao::create(['codigo' => '4', 'descricao' => 'Imobilizações corpóreas']);
        });
    }

    private function sessao(Empresa $e, array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($e->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $e->id];
    }

    private function categoria(array $s): int
    {
        return $this->postJson('/api/ativos/categorias', ['nome' => 'Equipamento administrativo', 'taxa_anual' => 25, 'vida_util_padrao' => 48,
            'conta_gasto' => '7315', 'conta_amortizacao_acumulada' => '1815', 'conta_venda' => '68031', 'conta_perda' => '78031', 'conta_ativo' => '1141'], $s)
            ->assertCreated()->json('dados.id');
    }

    private function bem(): int
    {
        return $this->postJson('/api/ativos/bens', ['descricao' => 'Computador de teste', 'data_aquisicao' => '2026-01-15', 'valor_aquisicao' => 48000, 'vida_util' => 48,
            'categoria_ativo_id' => $this->cat], $this->s)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function periodo_traz_data_de_aquisicao_e_amortizacao_acumulada_da_ficha(): void
    {
        $a = $this->bem();
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['01-2026', '02-2026']], $this->s)->assertOk();
        $this->postJson('/api/ativos/amortizacoes/integrar', ['periodos' => ['01-2026']], $this->s)->assertOk();

        $l = collect($this->getJson('/api/ativos/amortizacoes?periodo=02-2026', $this->s)->assertOk()->json('dados.linhas'))->firstWhere('ativo_imobilizado_id', $a);
        $this->assertSame('2026-01-15', $l['data_aquisicao']);
        $this->assertSame('1000.00', $l['amortizacao_acumulada']);   // só a quota integrada de 01-2026
        $this->assertSame('RASCUNHO', $l['estado']);
    }

    #[Test]
    public function lista_os_periodos_registados_do_mais_recente_para_o_mais_antigo(): void
    {
        $this->bem();
        $this->getJson('/api/ativos/amortizacoes/periodos-registados', $this->s)->assertOk()->assertJsonPath('dados', []);
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['01-2026', '02-2026', '03-2026']], $this->s)->assertOk();
        $this->postJson('/api/ativos/amortizacoes/integrar', ['periodos' => ['01-2026']], $this->s)->assertOk();

        $p = $this->getJson('/api/ativos/amortizacoes/periodos-registados', $this->s)->assertOk()->json('dados');
        $this->assertSame(['03-2026', '02-2026', '01-2026'], array_column($p, 'periodo'));
        $this->assertSame(['periodo' => '01-2026', 'ativos' => 1, 'integrados' => 1, 'rascunhos' => 0, 'valor' => '1000.00'], $p[2]);
        $this->assertSame(1, $p[0]['rascunhos']);

        // reabertura em massa dos períodos listados (serviço existente: estorno + anulação)
        $this->postJson('/api/ativos/amortizacoes/reabrir', ['periodos' => array_column($p, 'periodo')], $this->s)->assertOk();
        $this->getJson('/api/ativos/amortizacoes/periodos-registados', $this->s)->assertOk()->assertJsonPath('dados', []);
    }

    #[Test]
    public function periodos_registados_exige_permissao_e_isola_a_empresa(): void
    {
        $this->bem();
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['01-2026']], $this->s)->assertOk();

        $semPermissao = $this->sessao($this->empresa, ['activos_view']);
        $this->getJson('/api/ativos/amortizacoes/periodos-registados', $semPermissao)->assertForbidden();

        $outra = $this->criarEmpresa(['nif' => '5417000098']);
        $s2 = $this->sessao($outra, ['activos_amortizacoes_view']);
        $this->getJson('/api/ativos/amortizacoes/periodos-registados', $s2)->assertOk()->assertJsonPath('dados', []);
    }

    #[Test]
    public function mapa_fiscal_do_ano_tem_as_colunas_do_modelo(): void
    {
        $a = $this->bem();
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['01-2026', '02-2026']], $this->s)->assertOk();
        $this->postJson('/api/ativos/amortizacoes/integrar', ['periodos' => ['01-2026']], $this->s)->assertOk();

        $d = $this->getJson('/api/ativos/mapas/fiscal?ano=2026', $this->s)->assertOk()->json('dados');
        $l = collect($d['linhas'])->firstWhere('ativo_imobilizado_id', $a);
        $this->assertSame([1, 2026, 1, 2026], [$l['mes_aquisicao'], $l['ano_aquisicao'], $l['mes_inicio_utilizacao'], $l['ano_inicio_utilizacao']]);
        $this->assertSame('1141', $l['conta']);                      // conta do activo da categoria (sem lançamento de compra)
        $this->assertSame(['48000.00', '4.0', '25.00', '0.00', '1000.00', '1000.00', '47000.00'],
            [$l['valor_aquisicao'], $l['anos_vida'], $l['taxa_categoria'], $l['anteriores'], $l['exercicio'], $l['acumuladas'], $l['liquido']]);
        $this->assertSame('1000.00', $d['totais']['exercicio']);    // só quotas integradas (o rascunho de 02-2026 fica de fora)
        $this->getJson('/api/ativos/mapas/fiscal?ano=2026', $this->sessao($this->empresa, ['activos_view']))->assertForbidden();
    }
}
