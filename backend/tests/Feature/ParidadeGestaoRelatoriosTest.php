<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Relatórios de Gestão — regra dos destaques ≥ 20 % igual à do sistema anterior (verificação visual de 2026-10-08,
 * linha 1.2.1). O antigo não mostrava «Artigos sem movimento −100 % (2 → 0)» nem «Desvio orçamental +20,97 %»:
 *   - os acertos de saldo inicial da migração (documento_tipo MIGRACAO) não são movimentos do negócio;
 *   - o desvio orçamental usa o custo acumulado do razão do projecto (as compras imputadas não entram, como no legado).
 * Cenário: Março 2026 (A) × Março 2025 (B, homólogo).
 */
final class ParidadeGestaoRelatoriosTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'relatorios_gestao_view' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->h = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function kpis(array $dados): array
    {
        return collect($dados['kpis'])->keyBy('chave')->all();
    }

    private const PERIODO = ['a_inicio' => '2026-03-01', 'a_fim' => '2026-03-31', 'comparacao' => 'homologo'];

    #[Test]
    public function acertos_da_migracao_nao_contam_como_movimento_nos_artigos_sem_movimento(): void
    {
        $this->naEmpresa(function () {
            $p = Produto::create(['codigo' => 'ART-T1', 'nome' => 'Artigo de teste', 'movimenta_stock' => true, 'quantidade_stock' => 5, 'custo_medio' => 10]);
            DB::table('movimentos_inventario')->insert([
                ['empresa_id' => $this->empresa->id, 'produto_id' => $p->id, 'tipo' => 'AJUSTE', 'quantidade' => 5, 'data' => '2026-03-10 10:00:00', 'documento_tipo' => 'MIGRACAO', 'referencia' => 'Saldo inicial de migração'],
            ]);
        });

        $r = $this->getJson('/api/gestao/relatorios/stock?'.http_build_query(self::PERIODO), $this->h)->assertOk()->json('dados');
        $k = $this->kpis($r)['sem_mov'];
        $this->assertSame([1, 1], [$k['a'], $k['b']]);
        $resumo = $this->getJson('/api/gestao/relatorios/resumo?'.http_build_query(self::PERIODO), $this->h)->assertOk()->json('dados');
        $this->assertNotContains('sem_mov', array_column($resumo['destaques'], 'chave'));

        // um movimento real no período A continua a contar
        $this->naEmpresa(fn () => DB::table('movimentos_inventario')->insert(['empresa_id' => $this->empresa->id, 'produto_id' => Produto::query()->value('id'),
            'tipo' => 'SAIDA', 'quantidade' => -1, 'data' => '2026-03-15 10:00:00', 'documento_tipo' => 'GUIA']));
        $r = $this->getJson('/api/gestao/relatorios/stock?'.http_build_query(self::PERIODO), $this->h)->assertOk()->json('dados');
        $this->assertSame(0, $this->kpis($r)['sem_mov']['a']);
    }

    #[Test]
    public function desvio_orcamental_usa_so_o_custo_acumulado_do_razao_como_no_legado(): void
    {
        $this->naEmpresa(function () {
            $p = Projeto::create(['codigo' => 'PRJ-T1', 'nome' => 'Projecto de teste', 'estado' => 'ACTIVO']);
            LinhaOrcamentoProjeto::create(['projeto_id' => $p->id, 'montante' => 1000]);
            RazaoAnaliticoProjeto::create(['projeto_id' => $p->id, 'rubrica' => 'GERAL', 'modulo_origem' => 'OUTRO', 'natureza' => 'CUSTO', 'data' => '2026-03-05', 'montante' => 100]);
            // compra imputada: entra nos custos do período, não no custo acumulado do desvio
            $f = FaturaCompra::create(['numero_fatura' => 'FC-T1', 'data' => '2026-03-06', 'estado' => 'PENDENTE', 'projeto_id' => $p->id, 'montante_total' => 500]);
            ItemCompra::create(['tipo_documento_origem' => 'FATURA', 'fatura_compra_id' => $f->id, 'projeto_id' => $p->id, 'quantidade' => 1, 'preco_unitario' => 500, 'total' => 500, 'total_kz' => 500]);
        });

        $r = $this->getJson('/api/gestao/relatorios/projectos?'.http_build_query(self::PERIODO), $this->h)->assertOk()->json('dados');
        $k = $this->kpis($r);
        $this->assertEqualsWithDelta(-90.0, $k['desvio']['a'], 0.001);       // 100 ÷ 1 000 − 1 (sem os 500 da compra)
        $this->assertEqualsWithDelta(-100.0, $k['desvio']['b'], 0.001);      // nada até Março 2025
        $this->assertEqualsWithDelta(-90.0, $r['tabelas'][0]['linhas'][0]['desvio'], 0.001);
        $this->assertSame('600.00', $k['custos']['a']);                      // custos do período incluem a compra
    }
}
