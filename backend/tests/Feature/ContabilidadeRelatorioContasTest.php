<?php

namespace Tests\Feature;

use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Relatório e Contas (ADR-055): números N/N-1, indicadores (ROE, ROA, ROS), composição e movimentos das notas, CMVMC,
 * alertas, anexos, Nota 4 por categoria e ciclo do registo (gravar → concluir só com exercício encerrado → reabrir).
 */
final class ContabilidadeRelatorioContasTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['221' => 'Mercadorias', '211' => 'Compras', '4311' => 'Banco', '511' => 'Capital', '611' => 'Vendas', '711' => 'CMV', '781' => 'Imposto', '7806' => 'Multas', '1141' => 'Equipamento'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $n = [];
            foreach (['4' => 'Imobilizações corpóreas', '8' => 'Existências', '10' => 'Disponibilidades', '12' => 'Capital', '22' => 'Vendas', '27' => 'CMVMC',
                '30' => 'Outros custos', '35' => 'Imposto'] as $c => $d) {
                $n[(string) $c] = NotaDemonstracao::create(['codigo' => (string) $c, 'descricao' => $d])->id;
            }
            $diario = DiarioContabil::create(['codigo' => 'OD', 'descricao' => 'Diversos'])->id;
            $l = fn ($data, $lan, $linhas) => collect($linhas)->each(fn ($x) => LancamentoContabil::create(['diario_id' => $diario, 'data_documento' => $data,
                'numero_lan' => $lan, 'codigo_conta' => $x[0], 'tipo_dc' => $x[1], 'valor' => $x[2], 'nota_demonstracao_id' => $n[$x[3]] ?? null]));
            $l('2025-01-02', 'OD2025000001', [['4311', 'D', 10000, '10'], ['511', 'C', 10000, '12']]);
            $l('2025-02-01', 'OD2025000002', [['221', 'D', 2000, '8'], ['4311', 'C', 2000, '10']]);
            $l('2026-01-15', 'OD2026000001', [['211', 'D', 3000, '8'], ['4311', 'C', 3000, '10']]);
            $l('2026-03-01', 'OD2026000002', [['4311', 'D', 8000, '10'], ['611', 'C', 8000, '22']]);
            $l('2026-12-31', 'OD2026000003', [['711', 'D', 4000, '27'], ['221', 'C', 1000, '8'], ['211', 'C', 3000, '8']]);
            $l('2026-12-31', 'OD2026000004', [['781', 'D', 1000, '35'], ['7806', 'D', 100, '30'], ['4311', 'C', 1100, '10']]);
            $l('2026-06-01', 'OD2026000005', [['1141', 'D', 1200, '4'], ['4311', 'C', 1200, '10']]);
            $cat = CategoriaAtivo::create(['nome' => 'Equipamento administrativo', 'taxa_anual' => 25]);
            AtivoImobilizado::create(['codigo' => 'AST-001', 'descricao' => 'Computador', 'categoria_ativo_id' => $cat->id, 'valor_aquisicao' => 1200, 'vida_util' => 48,
                'data_aquisicao' => '2026-06-01', 'estado' => 'ACTIVO', 'amortizacao_acumulada' => 175]);
        });
        $this->s = $this->sessao(['relatorio_contas_view', 'rc_editar', 'rc_concluir', 'rc_reabrir']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function calcula_numeros_indicadores_notas_e_anexos(): void
    {
        $r = $this->getJson('/api/contabilidade/relatorio-contas/2026', $this->s)->assertOk()->json('dados');
        $this->assertNull($r['registo']['id']);   // ainda não gravado: configuração por omissão
        $this->assertSame(25, $r['registo']['configuracao']['taxa_imposto']);
        $d = $r['dados'];
        $i = $d['n']['indicadores'];
        // RL = 8 000 − 4 000 − 100 − 1 000 = 2 900; CP = 10 000 + 2 900; Activo = 12 900 (sem passivo)
        $this->assertSame(['8000.00', '4000.00', '2900.00', '12900.00', '12900.00'], [$i['vendas_prestacoes'], $i['cmvmc'], $i['rl'], $i['capital_proprio'], $i['activo']]);
        $this->assertEqualsWithDelta(2900 / 12900, $i['roe'], 0.000001);
        $this->assertEqualsWithDelta(2900 / 12900, $i['roa'], 0.000001);
        $this->assertEqualsWithDelta(2900 / 8000, $i['ros'], 0.000001);
        $this->assertNull($i['liquidez_geral']);   // sem passivo corrente
        $this->assertSame(['ei' => '2000.00', 'compras' => '3000.00', 'ef' => '1000.00', 'custo' => '4000.00'], $d['n']['cmvmc']);
        $this->assertSame('100.00', $d['n']['multas']);
        $this->assertSame([['conta' => '1141', 'descricao' => 'Equipamento', 'n' => '1200.00', 'n1' => '0.00']], $d['composicao']['4']);
        $this->assertSame(['inicial' => '0.00', 'aumentos' => '1200.00', 'diminuicoes' => '0.00', 'final' => '1200.00'],
            array_intersect_key($d['movimentos']['4'][0], array_flip(['inicial', 'aumentos', 'diminuicoes', 'final'])));
        $this->assertSame('8000.00', collect($d['anexos']['razao_dezembro'])->firstWhere('conta', '61')['saldo_credor']);
        $this->assertSame([['categoria' => 'Equipamento administrativo', 'ativos' => 1, 'bruto' => '1200.00', 'acumulada' => '175.00', 'liquido' => '1025.00']],
            array_map(fn ($c) => array_intersect_key($c, array_flip(['categoria', 'ativos', 'bruto', 'acumulada', 'liquido'])), $d['nota_4_por_categoria']));
        $this->assertSame('AST-001', $d['anexos']['amortizacoes'][0]['codigo']);
        $this->assertSame(['info'], array_column($d['alertas'], 'tipo'));   // só o aviso de PROVISÓRIO: balanço equilibrado, RL notas = RL contas
        $this->assertSame('10000.00', $d['n1']['indicadores']['capital_proprio']);
        $this->getJson('/api/contabilidade/relatorio-contas/2026', $this->sessao(['lancamentos_view']))->assertForbidden();
    }

    #[Test]
    public function grava_conclui_so_com_o_exercicio_encerrado_e_reabre(): void
    {
        $this->putJson('/api/contabilidade/relatorio-contas/2025', ['configuracao' => ['director' => 'Director A', 'taxa_imposto' => 30],
            'textos' => ['economia' => ['html' => '<p>Economia 2025</p>', 'auto' => false]]], $this->s)->assertOk()->assertJsonPath('dados.estado', 'RASCUNHO');
        // o relatório de 2026 herda a configuração e os textos livres de 2025
        $r = $this->getJson('/api/contabilidade/relatorio-contas/2026', $this->s)->json('dados.registo');
        $this->assertSame(['Director A', 30], [$r['configuracao']['director'], $r['configuracao']['taxa_imposto']]);
        $this->assertSame(['html' => '<p>Economia 2025</p>', 'auto' => false, 'copiado' => 2025], $r['textos']['economia']);

        $this->putJson('/api/contabilidade/relatorio-contas/2026', ['notas_incluir' => ['4' => true]], $this->s)->assertOk();
        $this->postJson('/api/contabilidade/relatorio-contas/2026/concluir', [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_ABERTO');
        DB::table('configuracoes_sistema')->insert(['chave' => "closed_year_{$this->empresa->id}_2026", 'valor' => 'true']);
        $c = $this->postJson('/api/contabilidade/relatorio-contas/2026/concluir', [], $this->s)->assertOk()->json('dados');
        $this->assertSame('APROVADO', $c['registo']['estado']);
        $this->assertArrayNotHasKey('_fotografia', $c['registo']['configuracao']);
        $this->assertTrue($c['dados']['encerrado']);
        // a fotografia não muda com lançamentos posteriores
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', 'OD2026000002')->update(['valor' => 9000]));
        $this->getJson('/api/contabilidade/relatorio-contas/2026', $this->s)->assertJsonPath('dados.dados.n.indicadores.vendas_prestacoes', '8000.00');
        $this->putJson('/api/contabilidade/relatorio-contas/2026', ['notas_incluir' => ['4' => false]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RELATORIO_CONCLUIDO');
        $re = $this->postJson('/api/contabilidade/relatorio-contas/2026/reabrir', [], $this->s)->assertOk()->json('dados');
        $this->assertSame('RASCUNHO', $re['registo']['estado']);
        $this->assertSame('9000.00', $re['dados']['n']['indicadores']['vendas_prestacoes']);
        $this->postJson('/api/contabilidade/relatorio-contas/2026/reabrir', [], $this->sessao(['relatorio_contas_view']))->assertForbidden();
    }
}
