<?php

namespace Tests\Feature;

use App\Models\AditamentoAlteracaoProjeto;
use App\Models\Empresa;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Gestao\ServicoVendaProjetos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fecho da Versão Actual (ADR-071) — gestão de Projectos:
 *   - G1 (relatorios_gestao.js:470-542): venda contratada, aditamentos, venda global, facturado acumulado até ao fim do
 *     período (NC abatem), por facturar e % facturado; «Proveitos do período»;
 *   - G2 (ui_painel_modulos.js:445-526): KPIs de venda, faturado (% da venda global), por faturar e margem prevista; série
 *     «Venda global» e colunas na carteira.
 * Cenário fictício: encomenda 10 000 (sem IVA) + aditamento aprovado 2 000; FT 3 000 em Março, NC 500 em Março, FT 1 000 em
 * Abril (fora do período); orçamento 8 000.
 */
final class FiGestaoProjetosTest extends TestCase
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
    public function servico_por_lote_calcula_venda_global_e_facturado_ate_a_data(): void
    {
        $r = $this->naEmpresa(fn () => app(ServicoVendaProjetos::class)->porProjeto(Projeto::query()->get(), '2026-03-31'));
        $this->assertSame(['venda' => '10000.00', 'aditamentos' => '2000.00', 'venda_global' => '12000.00', 'faturado' => '2500.00', 'por_faturar' => '9500.00',
            'faturado_pct' => 20.83], $r[$this->projeto]);
        $tudo = $this->naEmpresa(fn () => app(ServicoVendaProjetos::class)->porProjeto([$this->projeto]));
        $this->assertSame('3500.00', $tudo[$this->projeto]['faturado']);
        $this->assertSame(['10000.00', '12000.00', '3500.00'], [ServicoVendaProjetos::totais($tudo)['venda'], ServicoVendaProjetos::totais($tudo)['venda_global'], ServicoVendaProjetos::totais($tudo)['faturado']]);
    }

    #[Test]
    public function relatorio_de_gestao_de_projectos_mostra_venda_facturacao_e_por_facturar(): void
    {
        $r = $this->getJson('/api/gestao/relatorios/projectos?'.http_build_query(['a_inicio' => '2026-03-01', 'a_fim' => '2026-03-31', 'comparacao' => 'homologo']),
            $this->sessao(['relatorios_gestao_view']))->assertOk()->json('dados');
        $k = collect($r['kpis'])->keyBy('chave');
        foreach (['venda' => 10000, 'venda_global' => 12000, 'facturado' => 2500, 'por_facturar' => 9500, 'facturado_pct' => 20.83, 'proveitos' => 2500] as $c => $v) {
            $this->assertEqualsWithDelta($v, (float) $k[$c]['a'], 0.01, $c);
        }
        $this->assertSame('Proveitos do período', $k['proveitos']['rotulo']);
        $this->assertTrue($k['venda_global']['actual']);
        $t = $r['tabelas'][0];
        $this->assertSame('Venda, facturação e rentabilidade por projecto', $t['titulo']);
        $this->assertSame(['nome', 'estado', 'venda', 'aditamentos', 'venda_global', 'faturado', 'por_faturar', 'faturado_pct', 'proveitos'], array_slice(array_column($t['colunas'], 'id'), 0, 9));
        $this->assertSame('PRJ-V1 Obra fictícia', $t['linhas'][0]['nome']);          // ordenado pela venda global
        $this->assertEqualsWithDelta(9500, (float) $t['linhas'][0]['por_faturar'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $t['linhas'][1]['venda_global'], 0.01);
    }

    #[Test]
    public function painel_de_projectos_tem_kpis_de_venda_e_margem_prevista(): void
    {
        $d = $this->getJson('/api/gestao/paineis/projetos?ano=2026&mes=3', $this->sessao(['all']))->assertOk()->json('dados');
        $k = collect($d['kpis'])->keyBy('id');
        foreach (['venda_contratada' => 10000, 'venda_global' => 12000, 'faturado_venda' => 2500, 'por_faturar' => 9500, 'margem_prevista' => 4000] as $c => $v) {
            $this->assertEqualsWithDelta($v, (float) $k[$c]['valor'], 0.01, $c);
        }
        $this->assertStringContainsString('20,8 % da venda global', $k['faturado_venda']['subtitulo']);
        $this->assertStringContainsString('33,3 % da venda global', $k['margem_prevista']['subtitulo']);
        $grafico = collect($d['graficos'])->firstWhere('id', 'orcamento_custo_proveito');
        $this->assertSame('venda_global', $grafico['series'][0]['id'] ?? null);
        $carteira = collect($d['tabelas'])->firstWhere('id', 'carteira');
        $linha = collect($carteira['linhas'])->firstWhere('nome', 'PRJ-V1 Obra fictícia');
        $this->assertEqualsWithDelta(9500, (float) $linha['por_faturar'], 0.01);
        $this->assertContains('venda_global', array_column($carteira['colunas'], 'id'));
    }
}
