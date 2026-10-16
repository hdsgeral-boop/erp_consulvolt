<?php

namespace Tests\Feature;

use App\Models\AditamentoAlteracaoProjeto;
use App\Models\Empresa;
use App\Models\FaturaCompra;
use App\Models\ItemCompra;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\LinhaRevisaoProjeto;
use App\Models\MarcoProjeto;
use App\Models\PedidoCompra;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\RevisaoMensalProjeto;
use App\Models\TarefaProjeto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FJ — separador «Resumo» do projecto em paridade com o legado (projectos_dashboard.js:78-457), com dados fictícios:
 *   - custo realizado: subempreitada UMA vez — pela factura do auto quando ela existe (mesmo projecto e fornecedor),
 *     senão pelo valor do auto (factura apagada, id de outro documento, sem documento); mão de obra do razão e
 *     equipamentos; as linhas de pedido de compra não são custo (o legado somava auto + documento: duplicava);
 *   - tarefas: concluída = estado CONCLUIDA ou 100 %; a coluna personalizada FAZENDO é «em curso»;
 *   - venda global (sem IVA) = encomenda + aditamentos aprovados; facturado = FT − NC; margem prevista e real;
 *   - prazo: ritmo = execução ÷ % do prazo decorrido;
 *   - peso dos equipamentos medido contra o custo realizado;
 *   - fases da WBS pela ordem de criação (como o Dexie do legado), não pela data;
 *   - isolamento por empresa e permissões.
 */
final class FjResumoProjectoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417900011']);
        $this->naEmpresa(function () {
            $cli = Terceiro::create(['nome' => 'Cliente fictício', 'tipo' => Terceiro::CLIENTE])->id;
            $f1 = Terceiro::create(['nome' => 'Empreiteiro A', 'tipo' => Terceiro::FORNECEDOR])->id;
            $f2 = Terceiro::create(['nome' => 'Empreiteiro B', 'tipo' => Terceiro::FORNECEDOR])->id;
            $v = fn (string $tipo, string $num, float $liq) => Venda::create(['tipo_documento' => $tipo, 'numero_documento' => $num, 'data_emissao' => '2026-09-10 10:00:00',
                'total_liquido' => $liq, 'total_imposto' => round($liq * 0.14, 2), 'total_bruto' => round($liq * 1.14, 2), 'cliente_id' => $cli]);
            $ne = $v('NE', 'NE FJ/1', 100000);
            $ft = $v('FT', 'FT FJ/1', 60000);
            $nc = $v('NC', 'NC FJ/1', 5000);
            foreach ([[$ft->id, $ne->id], [$nc->id, $ft->id]] as [$a, $b]) {
                DB::table('vendas_documentos_relacionados')->insert(['empresa_id' => $this->empresa->id, 'venda_id' => $a, 'venda_relacionada_id' => $b]);
            }
            $p = Projeto::create(['codigo' => 'FJ-1', 'nome' => 'Obra fictícia', 'estado' => 'ACTIVO', 'tipo' => 'EXTERNO', 'cliente_id' => $cli, 'encomenda_venda_id' => $ne->id]);
            $this->ids['p'] = $p->id;
            $this->ids['interno'] = Projeto::create(['codigo' => 'FJ-I', 'nome' => 'Interno', 'estado' => 'ACTIVO', 'tipo' => 'INTERNO'])->id;
            LinhaOrcamentoProjeto::create(['projeto_id' => $p->id, 'rubrica' => 'SUBCONTRATOS', 'montante' => 80000]);
            AditamentoAlteracaoProjeto::create(['projeto_id' => $p->id, 'descricao' => 'Trabalhos a mais', 'montante' => 10000, 'estado' => 'APROVADO']);
            AditamentoAlteracaoProjeto::create(['projeto_id' => $p->id, 'descricao' => 'Proposta', 'montante' => 5000, 'estado' => 'PENDENTE']);

            // fases criadas pela ordem 1, 2 — a segunda com data alvo mais cedo
            $m1 = MarcoProjeto::create(['projeto_id' => $p->id, 'nome' => 'Fase criada primeiro', 'data' => '2026-12-31'])->id;
            $m2 = MarcoProjeto::create(['projeto_id' => $p->id, 'nome' => 'Fase criada depois', 'data' => '2026-01-31'])->id;
            $this->ids['marcos'] = [$m1, $m2];

            // tarefas: 1 concluída, 1 em curso a 100 % (conta como concluída), 1 em curso, 1 FAZENDO (em curso), 1 pendente
            $ini = now()->subDays(30)->toDateString();
            $fim = now()->addDays(30)->toDateString();
            foreach ([['CONCLUIDA', null, 100, $m1], ['EM_CURSO', null, 100, $m1], ['EM_CURSO', null, 50, $m2], ['EM_CURSO', 'FAZENDO', 25, $m2], ['PENDENTE', null, 0, $m2]] as $i => [$e, $orig, $pct, $m]) {
                TarefaProjeto::create(['projeto_id' => $p->id, 'marco_projeto_id' => $m, 'nome' => "Tarefa {$i}", 'estado' => $e, 'estado_original' => $orig ?? $e,
                    'percentagem_execucao' => $pct, 'data_inicio' => $ini, 'data_fim' => $fim]);
            }

            // autos de subempreitada (09/2026)
            $rev = RevisaoMensalProjeto::create(['projeto_id' => $p->id, 'mes' => 9, 'ano' => 2026, 'estado' => 'PROCESSADO']);
            $fat = FaturaCompra::create(['fornecedor_id' => $f1, 'numero_fatura' => 'AUTO-FJ-A', 'data' => '2026-09-30', 'estado' => 'PENDENTE', 'projeto_id' => $p->id]);
            ItemCompra::create(['tipo_documento_origem' => 'FATURA', 'fatura_compra_id' => $fat->id, 'quantidade' => 1, 'preco_unitario' => 20000, 'projeto_id' => $p->id]);
            $auto = fn ($terceiro, $valor, $doc) => LinhaRevisaoProjeto::create(['revisao_mensal_projeto_id' => $rev->id, 'tipo' => LinhaRevisaoProjeto::SUBEMPREITADA,
                'terceiro_id' => $terceiro, 'valor_calculado' => $valor, 'documento_gerado_id' => $doc]);
            $auto($f1, 20000, (string) $fat->id);   // factura válida: conta pela factura (20 000), não duas vezes
            $auto($f1, 7000, '999999');             // factura apagada no legado: conta o auto
            $auto($f2, 3000, (string) $fat->id);    // id de um documento de outro fornecedor: conta o auto
            $auto(null, 1500, null);                // sem documento: conta o auto

            // razão: mão de obra (auto interno) e equipamento; pedido de compra sem preço não é custo
            RazaoAnaliticoProjeto::create(['projeto_id' => $p->id, 'rubrica' => 'MAO_DE_OBRA', 'modulo_origem' => 'RH', 'tipo_documento_origem' => 'AUTO_INTERNO',
                'natureza' => 'CUSTO_REAL', 'natureza_original' => 'custo_real', 'data' => '2026-09-30', 'valor' => 10000, 'montante' => 10000]);
            RazaoAnaliticoProjeto::create(['projeto_id' => $p->id, 'rubrica' => 'CUSTOS_EQUIPAMENTO', 'modulo_origem' => 'ATIVOS', 'natureza' => 'CUSTO',
                'natureza_original' => 'custo', 'data' => '2026-09-15', 'montante' => 2000, 'documento_origem_id' => 'MAQ-FJ1', 'descricao' => 'Retro (4h a 500/h)']);
            $ped = PedidoCompra::create(['projeto_id' => $p->id, 'estado' => 'APROVADO']);
            ItemCompra::create(['tipo_documento_origem' => 'PEDIDO', 'pedido_compra_id' => $ped->id, 'quantidade' => 3, 'projeto_id' => $p->id]);
        });
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    #[Test]
    public function custo_realizado_conta_a_subempreitada_uma_vez(): void
    {
        $r = $this->getJson("/api/projetos/{$this->ids['p']}/resumo", $this->sessao(['projectos_carteira_view']))->assertOk()->json('dados');
        $i = $r['indicadores'];
        // 20 000 (factura) + 7 000 + 3 000 + 1 500 (autos sem factura válida) + 10 000 (mão de obra) + 2 000 (equipamento)
        $this->assertSame('43500.00', $i['custo']);
        $this->assertSame(['AUTOS_SUBEMPREITADAS' => '31500.00', 'AUTOS_MAO_OBRA' => '10000.00', 'EQUIPAMENTOS' => '2000.00'], $r['origem_custos']);
        $this->assertSame(['36500.00', 54.38], [$i['disponivel'], $i['consumo_pct']]);
        $this->assertSame('0.00', $i['compromissos']);
    }

    #[Test]
    public function tarefas_por_estado_e_ritmo_do_prazo(): void
    {
        $i = $this->getJson("/api/projetos/{$this->ids['p']}/resumo", $this->sessao(['proj_execucao']))->assertOk()->json('dados.indicadores');
        $this->assertSame(['PENDENTE' => 1, 'EM_CURSO' => 2, 'CONCLUIDA' => 2, 'BLOQUEADA' => 0], $i['estados']);
        $this->assertSame([61, 31, 30], [$i['prazo']['duracao_dias'], $i['prazo']['decorridos_dias'], $i['prazo']['restantes_dias']]);
        $this->assertSame(50.82, $i['prazo']['tempo_pct']);
        $this->assertSame(round($i['execucao'] / (31 / 61 * 100), 4), $i['prazo']['desempenho']);
    }

    #[Test]
    public function venda_global_facturado_e_margens_nos_externos(): void
    {
        $s = $this->sessao(['projectos_carteira_view']);
        $i = $this->getJson("/api/projetos/{$this->ids['p']}/resumo", $s)->assertOk()->json('dados.indicadores');
        $this->assertSame(['100000.00', '10000.00', '110000.00', '55000.00', 50.0],
            [$i['venda'], $i['aditamentos_aprovados'], $i['venda_global'], $i['faturado'], $i['faturado_pct']]);
        // prevista = venda global − orçamento; real = facturado − custo realizado
        $this->assertSame(['30000.00', 27.27, '11500.00', 20.91], [$i['margem_prevista'], $i['margem_prevista_pct'], $i['margem_real'], $i['margem_real_pct']]);
        // projecto interno: sem venda nem margem
        $interno = $this->getJson("/api/projetos/{$this->ids['interno']}/resumo", $s)->assertOk()->json('dados.indicadores');
        $this->assertArrayNotHasKey('venda_global', $interno);
    }

    #[Test]
    public function peso_dos_equipamentos_e_ordem_das_fases(): void
    {
        $s = $this->sessao(['projectos_carteira_view']);
        $e = $this->getJson("/api/projetos/{$this->ids['p']}/equipamentos", $s)->assertOk()->json('dados');
        $this->assertSame('43500.00', $e['custo_projeto']);
        $this->assertSame(4.6, $e['por_equipamento'][0]['peso_pct']);
        $grupos = $this->getJson("/api/projetos/{$this->ids['p']}/wbs", $s)->assertOk()->json('dados.grupos');
        $this->assertSame([...$this->ids['marcos'], null], array_map(fn ($g) => $g['marco']['id'] ?? null, $grupos));
        $this->assertSame(['Fase criada primeiro', 'Fase criada depois'],
            array_column($this->getJson("/api/projetos/{$this->ids['p']}/resumo", $s)->json('dados.execucao_por_marco'), 'marco'));
    }

    #[Test]
    public function autorizacao_e_isolamento_por_empresa(): void
    {
        $p = $this->ids['p'];
        $this->getJson("/api/projetos/{$p}/resumo", $this->sessao(['vendas_view']))->assertForbidden();
        $outra = $this->criarEmpresa(['nif' => '5417900029']);
        $this->getJson("/api/projetos/{$p}/resumo", $this->sessao(['projectos_carteira_view'], $outra))->assertNotFound();
        $this->getJson("/api/projetos/{$p}/equipamentos", $this->sessao(['projectos_carteira_view'], $outra))->assertNotFound();
    }
}
