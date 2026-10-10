<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\RubricaOrcamental;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Orcamento\ServicoParidadeOrcamento;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade do Orçamento com o sistema anterior (verificação visual, secção 11): lista com dimensões, total das rubricas e
 * última acção pela ordem do legado, orçamento por omissão do Controlo e causas do desvio («Porquê?»). Valores fictícios.
 * Ano corrente: venda 600 (Jan, cliente A); pessoal 300 (Jan, fornecedor X) e 200 (Fev, sem terceiro). Ano anterior: pessoal 400 (Jan).
 */
final class ParidadeOrcamentoTest extends TestCase
{
    private Empresa $empresa;

    private array $s = [];

    private int $ano;

    private int $un;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ano = (int) now()->format('Y');
        $this->empresa = $this->criarEmpresa();
        $ano = $this->ano;
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($ano) {
            foreach (['3111' => 'Clientes', '3211' => 'Fornecedores', '4311' => 'Banco', '611' => 'Vendas', '7211' => 'Remunerações'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->un = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Loja'])->id;
            $cliente = Terceiro::create(['nome' => 'Cliente A', 'nif' => '5000009901', 'tipo' => Terceiro::CLIENTE])->id;
            $fornecedor = Terceiro::create(['nome' => 'Fornecedor X', 'nif' => '5000009902', 'tipo' => Terceiro::FORNECEDOR])->id;
            $lan = app(ServicoLancamentos::class);
            $dia = fn (string $c) => app(LocalizadorLancamentos::class)->diario($c, $c)->id;
            $post = fn (string $diario, string $data, string $doc, array $linhas) => $lan->criar(['diario_id' => $dia($diario), 'data_documento' => $data, 'numero_documento' => $doc,
                'descricao' => $doc, 'linhas' => array_map(fn ($l) => ['codigo_conta' => $l[0], 'tipo_dc' => $l[1], 'valor' => $l[2], 'terceiro_id' => $l[3] ?? null], $linhas)]);
            $post('FC', "{$ano}-01-10", 'FT1', [['3111', 'D', 600, $cliente], ['611', 'C', 600, $cliente]]);
            $post('BD', "{$ano}-01-25", 'SAL1', [['7211', 'D', 300, $fornecedor], ['4311', 'C', 300]]);
            $post('BD', "{$ano}-02-25", 'SAL2', [['7211', 'D', 200], ['4311', 'C', 200]]);
            $post('BD', ($ano - 1).'-01-25', 'SAL0', [['7211', 'D', 400], ['4311', 'C', 400]]);
        });
        $tudo = ['orc_rubricas_view', 'orc_orcamentos_view', 'orc_controlo_view', 'orc_rubricas_edit', 'orc_editar', 'orc_submeter', 'orc_aprovar', 'orc_hierarquia'];
        foreach (['a' => $tudo, 'b' => $tudo, 'lista' => ['orc_orcamentos_view']] as $k => $perm) {
            $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($perm, true))->id]);
            $u->empresas()->attach($this->empresa->id);
            $this->s[$k] = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
        }
    }

    private function rub(string $codigo): int
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => RubricaOrcamental::query()->where('codigo', $codigo)->value('id'));
    }

    private function meses(float $v): array
    {
        return array_fill(0, 12, $v);
    }

    #[Test]
    public function chaves_ordenam_como_o_locale_compare_do_legado(): void
    {
        $this->assertLessThan(0, ServicoParidadeOrcamento::compararChaves('2026|EXPLORACAO||', '2026|EXPLORACAO|4|'));
        $this->assertLessThan(0, ServicoParidadeOrcamento::compararChaves('2026|EXPLORACAO|10|', '2026|EXPLORACAO|4|'));
        $this->assertLessThan(0, ServicoParidadeOrcamento::compararChaves('2026|EXPLORACAO||', '2026|EXPLORACAO|||P3'));
        $this->assertSame(0, ServicoParidadeOrcamento::compararChaves('2026|TESOURARIA||', '2026|TESOURARIA||'));
    }

    #[Test]
    public function lista_com_dimensoes_totais_ultima_accao_e_orcamento_por_omissao(): void
    {
        [$a, $b] = [$this->s['a'], $this->s['b']];
        $this->postJson('/api/orcamento/rubricas/base', ['tipo' => 'EXPLORACAO'], $a)->assertOk();
        $this->postJson('/api/orcamento/rubricas/base', ['tipo' => 'TESOURARIA'], $a)->assertOk();
        // filho por UN criado antes do da empresa: a ordem do legado põe «Toda a empresa» primeiro e o filho por baixo do pai
        $empresa = $this->postJson('/api/orcamento/orcamentos', ['ano' => $this->ano, 'tipo' => 'EXPLORACAO'], $a)->assertCreated()->json('dados.id');
        $filho = $this->postJson('/api/orcamento/orcamentos', ['ano' => $this->ano, 'tipo' => 'EXPLORACAO', 'unidade_negocio_id' => $this->un, 'orcamento_pai_id' => $empresa], $a)
            ->assertCreated()->json('dados.id');
        $tes = $this->postJson('/api/orcamento/orcamentos', ['ano' => $this->ano, 'tipo' => 'TESOURARIA'], $a)->assertCreated()->json('dados.id');
        $seguinte = $this->postJson('/api/orcamento/orcamentos', ['ano' => $this->ano + 1, 'tipo' => 'EXPLORACAO'], $a)->assertCreated()->json('dados.id');
        $this->putJson("/api/orcamento/orcamentos/{$empresa}/valores", ['linhas' => [['rubrica_orcamental_id' => $this->rub('P01'), 'valores' => $this->meses(100)],
            ['rubrica_orcamental_id' => $this->rub('C02'), 'valores' => $this->meses(250)]]], $a)->assertOk();

        $r = $this->getJson('/api/orcamento/paridade/orcamentos', $a)->assertOk()->json('dados');
        $this->assertSame([$seguinte, $empresa, $filho, $tes], array_column($r['orcamentos'], 'id'));
        $this->assertSame($seguinte, $r['por_omissao']);   // nenhum aprovado: o primeiro da lista
        $l = collect($r['orcamentos'])->keyBy('id');
        $this->assertSame(['Toda a empresa', 0, '4200.00', 'CRIADO'], [$l[$empresa]['dimensoes'], $l[$empresa]['nivel'], $l[$empresa]['total_rubricas'], $l[$empresa]['ultima_accao']['accao']]);
        $this->assertSame(['UN UN1', 1], [$l[$filho]['dimensoes'], $l[$filho]['nivel']]);
        $this->assertSame("{$this->ano} · Exploração · Toda a empresa · v1 (Rascunho)", $l[$empresa]['rotulo']);
        $this->assertSame("{$this->ano} · Tesouraria · Toda a empresa · v1 (Rascunho)", $l[$tes]['rotulo']);

        // aprovado: passa a ser o orçamento por omissão do Controlo e a última acção mostra quem aprovou
        $this->postJson("/api/orcamento/orcamentos/{$empresa}/submeter", [], $a)->assertOk();
        $this->assertSame('SUBMETIDO', collect($this->getJson('/api/orcamento/paridade/orcamentos', $a)->json('dados.orcamentos'))->firstWhere('id', $empresa)['ultima_accao']['accao']);
        $this->postJson("/api/orcamento/orcamentos/{$empresa}/aprovar", [], $b)->assertOk();
        $r = $this->getJson('/api/orcamento/paridade/orcamentos?tipo=EXPLORACAO', $a)->assertOk()->json('dados');
        $this->assertSame($empresa, $r['por_omissao']);
        $this->assertSame([$seguinte, $empresa, $filho], array_column($r['orcamentos'], 'id'));
        $this->assertSame('APROVADO', collect($r['orcamentos'])->firstWhere('id', $empresa)['ultima_accao']['accao']);
        $this->assertSame([$empresa, $filho], array_column($this->getJson("/api/orcamento/paridade/orcamentos?ano={$this->ano}&tipo=EXPLORACAO", $a)->json('dados.orcamentos'), 'id'));
        $this->getJson('/api/orcamento/paridade/orcamentos?tipo=OUTRO', $a)->assertStatus(422);

        // causas do desvio de pessoal (Jan–Fev): conta com descrição, comparação com o ano anterior, maiores movimentos e terceiros
        $c = $this->getJson("/api/orcamento/paridade/orcamentos/{$empresa}/desvios/{$this->rub('C02')}?de=1&ate=2", $a)->assertOk()->json('dados');
        $this->assertSame('0.00', $c['desvio_total']);   // 300 + 200 − 2 × 250
        $this->assertSame(['7211', 'Remunerações', '500.00', '400.00', '100.00'], [(string) $c['contas'][0]['conta'], $c['contas'][0]['descricao'], $c['contas'][0]['real'], $c['contas'][0]['anterior'], $c['contas'][0]['variacao']]);
        $this->assertSame(['300.00', '200.00'], array_column($c['maiores_movimentos'], 'valor_rubrica'));
        $this->assertSame([['Fornecedor X', '300.00', 60], ['Sem terceiro', '200.00', 40]], array_map(fn ($t) => [$t['nome'], $t['valor'], (int) $t['peso_pct']], $c['terceiros']));
        $this->assertSame(['de' => 1, 'ate' => 2, 'ano' => $this->ano], $c['periodo']);
    }

    #[Test]
    public function causas_protegidas_por_permissao_tipo_e_empresa(): void
    {
        $a = $this->s['a'];
        $this->postJson('/api/orcamento/rubricas/base', ['tipo' => 'EXPLORACAO'], $a)->assertOk();
        $this->postJson('/api/orcamento/rubricas/base', ['tipo' => 'TESOURARIA'], $a)->assertOk();
        $o = $this->postJson('/api/orcamento/orcamentos', ['ano' => $this->ano, 'tipo' => 'EXPLORACAO'], $a)->assertCreated()->json('dados.id');
        $tes = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => RubricaOrcamental::query()->where('tipo', 'TESOURARIA')->value('id'));

        // sem permissão de controlo: 403; rubrica de outro tipo: 404; mês inválido: 422
        $this->getJson("/api/orcamento/paridade/orcamentos/{$o}/desvios/{$this->rub('C02')}", $this->s['lista'])->assertStatus(403);
        $this->getJson('/api/orcamento/paridade/orcamentos', $this->s['lista'])->assertOk();
        $this->getJson("/api/orcamento/paridade/orcamentos/{$o}/desvios/{$tes}", $a)->assertStatus(404);
        $this->getJson("/api/orcamento/paridade/orcamentos/{$o}/desvios/{$this->rub('C02')}?ate=13", $a)->assertStatus(422);

        // outra empresa não vê o orçamento (404)
        $outra = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'orc_controlo_view' => true, 'orc_orcamentos_view' => true])->id]);
        $u->empresas()->attach($outra->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $outra->id];
        $this->getJson("/api/orcamento/paridade/orcamentos/{$o}/desvios/{$this->rub('C02')}", $s)->assertStatus(404);
        $this->assertSame([], $this->getJson('/api/orcamento/paridade/orcamentos', $s)->assertOk()->json('dados.orcamentos'));
    }
}
