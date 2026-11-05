<?php

namespace Tests\Feature;

use App\Models\AditamentoAlteracaoProjeto;
use App\Models\Empresa;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Projetos\FormulasFinanceirasProjeto;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Separador «Financeiro» do projecto: fórmulas EVM (caso conhecido), painel, plano de facturação e associação de facturas.
 * Cenário fictício: encomenda 10 000 + aditamento aprovado 2 000; FT 3 000 e 1 000, NC 500 (facturado 3 500); orçamento 8 000.
 */
final class FinanceiroProjetoTest extends TestCase
{
    private Empresa $empresa;

    private int $projeto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417200045']);
        $this->naEmpresa(function () {
            $cli = Terceiro::create(['nome' => 'Cliente fictício', 'tipo' => 'CLIENTE'])->id;
            $v = fn (string $tipo, string $num, string $data, $liq) => Venda::create(['tipo_documento' => $tipo, 'numero_documento' => $num, 'data_emissao' => "{$data} 10:00:00",
                'total_liquido' => $liq, 'total_imposto' => round($liq * 0.14, 2), 'total_bruto' => round($liq * 1.14, 2), 'cliente_id' => $cli]);
            $ne = $v('NE', 'NE T/1', '2026-01-15', 10000);
            $ft1 = $v('FT', 'FT T/1', '2026-03-10', 3000);
            $nc = $v('NC', 'NC T/1', '2026-03-20', 500);
            $ft2 = $v('FT', 'FT T/2', '2026-04-10', 1000);
            $anulada = $v('FT', 'FT T/3', '2026-03-11', 9999);
            $anulada->update(['estado' => 'ANULADO']);
            foreach ([[$ft1->id, $ne->id], [$ft2->id, $ne->id], [$anulada->id, $ne->id], [$nc->id, $ft1->id]] as [$a, $b]) {
                DB::table('vendas_documentos_relacionados')->insert(['empresa_id' => $this->empresa->id, 'venda_id' => $a, 'venda_relacionada_id' => $b]);
            }
            $p = Projeto::create(['codigo' => 'PRJ-V1', 'nome' => 'Obra fictícia', 'estado' => 'ACTIVO', 'tipo' => 'EXTERNO', 'cliente_id' => $cli, 'encomenda_venda_id' => $ne->id]);
            $this->projeto = $p->id;
            Projeto::create(['codigo' => 'PRJ-I1', 'nome' => 'Projecto interno', 'estado' => 'ACTIVO']);
            LinhaOrcamentoProjeto::create(['projeto_id' => $p->id, 'montante' => 8000]);
            AditamentoAlteracaoProjeto::create(['projeto_id' => $p->id, 'descricao' => 'Trabalhos a mais', 'montante' => 2000, 'estado' => 'APROVADO']);
            AditamentoAlteracaoProjeto::create(['projeto_id' => $p->id, 'descricao' => 'Proposta', 'montante' => 700, 'estado' => 'PENDENTE']);
        });
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function formulas_de_valor_acrescentado_caso_conhecido(): void
    {
        // BAC 100 000 · 50 % planeado · 40 % executado · AC 50 000
        $e = FormulasFinanceirasProjeto::evm(100000, 50, 40, 50000);
        $this->assertSame([50000.0, 40000.0, -10000.0, -10000.0, 0.8, 0.8], [$e['pv'], $e['ev'], $e['cv'], $e['sv'], $e['cpi'], $e['spi']]);
        $this->assertSame([125000.0, 75000.0, -25000.0, 1.2], [$e['eac'], $e['etc'], $e['vac'], $e['tcpi']]);
        // sem custo real ou sem orçamento não se inventa nada
        $s = FormulasFinanceirasProjeto::evm(100000, 50, 40, 0);
        $this->assertNull($s['cpi']);
        $this->assertNull($s['eac']);
        $z = FormulasFinanceirasProjeto::evm(0, null, null, 100);
        $this->assertNull($z['pv']);
        $this->assertNull($z['ev']);
        $this->assertSame(['reconhecida' => 6000.0, 'sobre_facturacao' => -2500.0], FormulasFinanceirasProjeto::receita(12000, 50, 3500));
        $this->assertSame(300.0, FormulasFinanceirasProjeto::retencao(3000, 10));
        $this->assertSame(50.0, FormulasFinanceirasProjeto::pctPlaneada(['2026-01', '2026-02', '2026-03'], [10, 50, 100], '2026-02-15'));
        $codigos = array_column(FormulasFinanceirasProjeto::alertas($e, 110), 'codigo');
        $this->assertEqualsCanonicalizing(['ORCAMENTO_EXCEDIDO', 'CPI_BAIXO', 'SPI_BAIXO', 'EAC_ACIMA_BAC'], $codigos);
    }

    #[Test]
    public function painel_plano_de_facturacao_e_associacao_de_facturas(): void
    {
        $s = $this->sessao(['projectos_carteira_view', 'proj_gerir']);
        $u = "/api/projetos/{$this->projeto}/financeiro";
        $i = $this->getJson($u, $s)->assertOk()->json('dados.indicadores');
        $this->assertEquals([12000, 3500, 8500, 8000, 3500], [$i['venda_global'], $i['facturado'], $i['saldo_a_facturar'], $i['orcamento'], $i['margem_bruta']]);

        $l = $this->postJson("{$u}/plano", ['descricao' => 'Sinal 30 %', 'montante' => 3000, 'data_prevista' => '2026-03-01', 'retencao_pct' => 10], $s)->assertCreated()->assertJsonPath('dados.estado', 'PLANEADO')->json('dados');
        $this->postJson("{$u}/plano", ['descricao' => 'Fim da obra', 'montante' => 5000, 'data_prevista' => '2099-12-01'], $s)->assertCreated();
        $porAssociar = $this->getJson($u, $s)->json('dados.facturas_por_associar');
        $this->assertCount(2, $porAssociar);   // FT T/1 e FT T/2 (a anulada e a NC ficam de fora)
        $ft1 = collect($porAssociar)->firstWhere('documento', 'FT T/1')['venda_id'];

        $this->postJson("{$u}/plano/{$l['id']}/factura", ['venda_id' => $ft1], $s)->assertOk()->assertJsonPath('dados.estado', 'FACTURADO')->assertJsonPath('dados.numero_documento', 'FT T/1');
        $anulada = $this->naEmpresa(fn () => Venda::query()->where('numero_documento', 'FT T/3')->value('id'));
        $this->postJson("{$u}/plano/{$l['id']}/factura", ['venda_id' => $anulada], $s)->assertStatus(422);
        $this->putJson("{$u}/plano/{$l['id']}", ['descricao' => 'x', 'montante' => 1], $s)->assertStatus(422)->assertJsonPath('codigo', 'PRESTACAO_NAO_EDITAVEL');

        $p = $this->getJson($u, $s)->json('dados');
        $this->assertCount(1, $p['facturas_por_associar']);
        $this->assertEquals(300, $p['indicadores']['retencao']);   // 10 % de 3 000 facturados
        $this->assertNotNull($p['fluxo_previsto']);   // há prestação planeada futura
        $this->assertNotEmpty($p['ocultos']);         // sem custo real: CPI/EAC escondidos com explicação
        $this->deleteJson("{$u}/plano/{$l['id']}/factura", [], $s)->assertOk()->assertJsonPath('dados.estado', 'PLANEADO');
        $this->postJson("{$u}/plano/{$l['id']}/cancelar", [], $s)->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
    }

    #[Test]
    public function projecto_interno_nao_tem_plano_de_facturacao_e_permissoes(): void
    {
        $interno = $this->naEmpresa(fn () => Projeto::query()->where('codigo', 'PRJ-I1')->value('id'));
        $s = $this->sessao(['projectos_carteira_view', 'proj_gerir']);
        $this->postJson("/api/projetos/{$interno}/financeiro/plano", ['descricao' => 'x', 'montante' => 10], $s)->assertStatus(422)->assertJsonPath('codigo', 'PROJECTO_INTERNO');
        $this->assertNull($this->getJson("/api/projetos/{$interno}/financeiro", $s)->assertOk()->json('dados.indicadores.facturado'));
        $leitor = $this->sessao(['projectos_carteira_view']);
        $this->postJson("/api/projetos/{$this->projeto}/financeiro/plano", ['descricao' => 'x', 'montante' => 10], $leitor)->assertForbidden();
    }
}
