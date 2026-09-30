<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LinhaOrcamento;
use App\Models\OrcamentoAnual;
use App\Models\PlanoConta;
use App\Models\RubricaOrcamental;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Orcamento\ServicoRubricasOrcamentais;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Orçamento parte 3 (ADR-046): previsões deslizantes, cenários e análise de desvios.
 * Real do ano corrente: vendas 300 (Jan), 600 (Fev), 900 (Mar). Orçamento aprovado: vendas 100/mês, pessoal 50/mês.
 */
final class OrcamentoPlaneamentoTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private int $ano;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ano = (int) now()->format('Y');
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3111' => 'Clientes', '4311' => 'Banco', '611' => 'Vendas', '7211' => 'Remunerações'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            app(ServicoRubricasOrcamentais::class)->criarBase('EXPLORACAO');
            $dia = app(LocalizadorLancamentos::class)->diario('FC', 'Vendas')->id;
            foreach ([1 => 300, 2 => 600, 3 => 900] as $m => $v) {
                app(ServicoLancamentos::class)->criar(['diario_id' => $dia, 'data_documento' => sprintf('%04d-%02d-10', $this->ano, $m), 'numero_documento' => "FT{$m}", 'descricao' => 'Venda',
                    'linhas' => [['codigo_conta' => '3111', 'tipo_dc' => 'D', 'valor' => $v], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => $v]]]);
            }
            $this->ids['P01'] = RubricaOrcamental::query()->where('codigo', 'P01')->value('id');
            $this->ids['C02'] = RubricaOrcamental::query()->where('codigo', 'C02')->value('id');
            $o = OrcamentoAnual::create(['ano' => $this->ano, 'tipo' => 'EXPLORACAO', 'nome' => 'Orçamento', 'versao' => 1, 'estado' => 'APROVADO']);
            LinhaOrcamento::create(['orcamento_anual_id' => $o->id, 'rubrica_orcamental_id' => $this->ids['P01'], 'valores' => array_fill(0, 12, 100), 'total' => 1200]);
            LinhaOrcamento::create(['orcamento_anual_id' => $o->id, 'rubrica_orcamental_id' => $this->ids['C02'], 'valores' => array_fill(0, 12, 50), 'total' => 600]);
            $this->ids['orcamento'] = $o->id;
        });
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys(['orc_previsoes_view', 'orc_previsoes_edit', 'orc_cenarios_view',
            'orc_cenarios_edit', 'orc_editar', 'orc_controlo_view'], true))->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function previsao_por_tendencia_revisoes_e_fecho_estimado(): void
    {
        $s = $this->s;
        $ref = sprintf('%04d-03', $this->ano);
        $p = $this->postJson('/api/orcamento/previsoes', ['tipo' => 'EXPLORACAO', 'mes_referencia' => $ref, 'metodo' => 'TENDENCIA'], $s)->assertCreated()->json('dados.id');
        $this->postJson('/api/orcamento/previsoes', ['tipo' => 'EXPLORACAO', 'mes_referencia' => $ref], $s)->assertStatus(422)->assertJsonPath('codigo', 'PREVISAO_EXISTENTE');
        $r = $this->getJson("/api/orcamento/previsoes/{$p}", $s)->assertOk()->json('dados');
        $v = collect($r['linhas'])->firstWhere('codigo', 'P01');
        $abr = sprintf('%04d-04', $this->ano);
        $this->assertEquals(600, $v['previsao'][$abr]);                  // (300 + 600 + 900) / 3
        $this->assertEquals([300, 600, 900], $v['real_recente']);
        $this->assertEquals(7200, $v['fecho_estimado']);                  // 1 800 real + 9 × 600 previstos
        $this->assertEquals(1200, $v['orcado']);

        $this->postJson("/api/orcamento/previsoes/{$p}/revisao", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'REVISAO_EM_CURSO');
        // editar e publicar; publicada não se elimina
        $this->putJson("/api/orcamento/previsoes/{$p}", ['linhas' => [['rubrica_orcamental_id' => $this->ids['P01'], 'valores' => [$abr => 650] + $v['previsao']]]], $s)->assertOk();
        $this->postJson("/api/orcamento/previsoes/{$p}/publicar", [], $s)->assertOk()->assertJsonPath('dados.estado', 'PUBLICADA');
        $this->deleteJson("/api/orcamento/previsoes/{$p}", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'PREVISAO_PUBLICADA');
        // nova revisão: os meses em comum vêm da anterior; o novo mês (Abril do ano seguinte) é semeado pela tendência
        $r2 = $this->postJson("/api/orcamento/previsoes/{$p}/revisao", [], $s)->assertCreated()->assertJsonPath('dados.revisao', 2)->json('dados.id');
        $v2 = collect($this->getJson("/api/orcamento/previsoes/{$r2}", $s)->json('dados.linhas'))->firstWhere('codigo', 'P01')['previsao'];
        $this->assertEquals(600, $v2[sprintf('%04d-05', $this->ano)]);
        $this->assertArrayNotHasKey($abr, $v2);
        $this->assertEquals(500, $v2[sprintf('%04d-04', $this->ano + 1)]);   // (600 + 900 + 0) / 3 — Abril ainda sem real
    }

    #[Test]
    public function cenarios_geram_versao_e_analise_de_desvio(): void
    {
        $s = $this->s;
        $o = $this->ids['orcamento'];
        $this->postJson("/api/orcamento/orcamentos/{$o}/cenarios/padrao", [], $s)->assertOk()->assertJsonCount(3, 'dados');
        $otimista = collect($this->getJson("/api/orcamento/orcamentos/{$o}/cenarios", $s)->json('dados'))->firstWhere('tipo', 'OTIMISTA')['id'];
        $c = $this->getJson("/api/orcamento/cenarios/{$otimista}", $s)->assertOk()->json('dados');
        $l = collect($c['linhas'])->keyBy('codigo');
        // vendas: volume +10 % × preço +5 % = 1,155; pessoal (fixo): +5 % = 1,05
        $this->assertEquals([1386, 630, 600, 756], [$l['P01']['cenario'], $l['C02']['cenario'], $c['resultado_base'], $c['resultado_cenario']]);
        $v = $this->postJson("/api/orcamento/cenarios/{$otimista}/gerar-versao", [], $s)->assertCreated()->assertJsonPath('dados.versao', 2)->assertJsonPath('dados.estado', 'RASCUNHO')->json('dados.id');
        $this->assertEquals(115.5, collect($this->getJson("/api/orcamento/orcamentos/{$v}", $s)->json('dados.linhas'))->firstWhere('rubrica_orcamental_id', $this->ids['P01'])['valores'][0]);

        // desvio de vendas Jan–Mar: +200, +500, +800 → concentrado nos 2 maiores meses (87 %) → pontual
        $d = $this->getJson("/api/orcamento/orcamentos/{$o}/desvios/{$this->ids['P01']}?de=1&ate=3", $s)->assertOk()->json('dados');
        $this->assertEquals([1500, 'PONTUAL', 0], [$d['desvio_total'], $d['classificacao'], $d['desfavoraveis']]);
        $this->assertEquals(['611', 1800, 0], [$d['contas'][0]['conta'], $d['contas'][0]['real'], $d['contas'][0]['anterior']]);
        $this->assertCount(3, $d['maiores_movimentos']);
    }
}
