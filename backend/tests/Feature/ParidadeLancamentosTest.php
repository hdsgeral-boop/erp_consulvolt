<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade do ecrã Lançamentos com o sistema anterior (verificacao_visual/RELATORIO.md 8.1.1–8.1.3): colunas novas da
 * lista, filtros por listas coladas e por datas, pré-visualização da alteração em massa, «Descontabilizar Selecionados»,
 * documento de origem, «Editar Lançamento Completo» (estorno + novo) e destinos da transferência. Dados fictícios.
 */
final class ParidadeLancamentosTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private DiarioContabil $diario;

    private array $cab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
        foreach ([$this->empresa, $this->outra] as $e) {
            app(ContextoEmpresa::class)->executarComo($e->id, function () use ($e) {
                foreach ([['111', 'Caixa fictícia'], ['211', 'Clientes'], ['611', 'Vendas de teste']] as [$c, $d]) {
                    PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
                }
                $diario = DiarioContabil::create(['codigo' => 'OD', 'descricao' => 'OPERAÇÕES DIVERSAS']);
                if ($e->is($this->empresa)) {
                    $this->diario = $diario;
                }
            });
        }
        $this->cab = $this->sessao(['lancamentos_view', 'lancamentos_post', 'lancamentos_bulk_notes', 'lancamentos_editar', 'contab_lanc_transferir', 'contab_lanc_del']);
    }

    private function sessao(array $permissoes, array $empresas = []): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresas ?: [$this->empresa->id, $this->outra->id]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    /** @return array{0: int, 1: int, 2: string} ids das linhas D e C e o n.º do lançamento */
    private function lancar(string $valor = '1000.00', string $data = '2026-03-15', ?string $documento = null): array
    {
        $r = $this->postJson('/api/contabilidade/lancamentos', ['diario_id' => $this->diario->id, 'data_documento' => $data, 'descricao' => 'Teste', 'numero_documento' => $documento,
            'linhas' => [['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => $valor], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => $valor]]], $this->cab)
            ->assertCreated();

        return [$r->json('dados.linhas.0.id'), $r->json('dados.linhas.1.id'), $r->json('dados.numero_lan')];
    }

    private function linha(int $id): object
    {
        return DB::table('lancamentos_contabeis')->where('id', $id)->first();
    }

    #[Test]
    public function lista_devolve_nome_da_conta_projecto_url_e_datas_sem_n_mais_1(): void
    {
        [$d] = $this->lancar();
        $this->lancar('5.00');
        DB::table('lancamentos_contabeis')->where('id', $d)->update(['url_documento' => 'https://exemplo.invalid/doc.pdf', 'codigo_projeto' => 'PRJ-01']);

        DB::enableQueryLog();
        $r = $this->getJson('/api/contabilidade/lancamentos?codigo_conta=1', $this->cab)->assertOk()->assertJsonCount(2, 'dados');
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();
        $linha = collect($r->json('dados'))->firstWhere('id', $d);
        $this->assertSame('Caixa fictícia', $linha['conta_descricao']);
        $this->assertSame('PRJ-01', $linha['codigo_projeto']);
        $this->assertSame('https://exemplo.invalid/doc.pdf', $linha['url_documento']);
        $this->assertNotNull($linha['criado_em']);
        $this->assertArrayHasKey('taxa_cambio_manual', $linha);
        // 2 lançamentos ou 200: o número de consultas não cresce com as linhas (subconsulta + eager loading)
        $this->lancar('7.00');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/contabilidade/lancamentos?codigo_conta=1', $this->cab)->assertOk()->assertJsonCount(3, 'dados');
        $this->assertSame($consultas, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    #[Test]
    public function filtra_por_listas_coladas_e_por_datas_do_documento_e_do_lancamento(): void
    {
        [, , $n1] = $this->lancar('10.00', '2026-03-15', 'DOC-A');
        [, , $n2] = $this->lancar('20.00', '2026-03-16', 'DOC-B');
        $this->lancar('30.00', '2026-03-17', 'DOC-C');

        $this->getJson('/api/contabilidade/lancamentos?'.http_build_query(['numero_documento' => "DOC-A\nDOC-B"]), $this->cab)->assertOk()->assertJsonCount(4, 'dados');
        $this->getJson('/api/contabilidade/lancamentos?'.http_build_query(['numero_lan' => "{$n1}, {$n2}"]), $this->cab)->assertOk()->assertJsonCount(4, 'dados');
        $this->getJson('/api/contabilidade/lancamentos?'.http_build_query(['filtro_contas' => "111\n211"]), $this->cab)->assertOk()->assertJsonCount(3, 'dados');
        // listas demasiado longas recusadas (limite de entrada)
        $this->getJson('/api/contabilidade/lancamentos?'.http_build_query(['numero_documento' => implode(',', range(1, 501))]), $this->cab)
            ->assertStatus(422)->assertJsonPath('codigo', 'FILTRO_LISTA_DEMASIADO_LONGA');

        DB::table('lancamentos_contabeis')->where('numero_documento', 'DOC-A')->update(['data_lancamento' => '2026-01-10 00:00:00', 'criado_em' => '2026-01-12 10:00:00']);
        $this->getJson('/api/contabilidade/lancamentos?data_doc_inicio=2026-01-01&data_doc_fim=2026-01-10', $this->cab)->assertOk()->assertJsonCount(2, 'dados');
        $this->getJson('/api/contabilidade/lancamentos?criado_inicio=2026-01-12&criado_fim=2026-01-12', $this->cab)->assertOk()->assertJsonCount(2, 'dados');
    }

    #[Test]
    public function previsao_da_alteracao_em_massa_conta_as_linhas_filtradas(): void
    {
        $this->lancar();
        $this->lancar('5.00');
        $this->postJson('/api/contabilidade/lancamentos/classificacao/previsao', ['filtros' => ['codigo_conta' => '6']], $this->cab)
            ->assertOk()->assertJsonPath('dados.linhas', 2)->assertJsonPath('dados.excede', false);
        $this->postJson('/api/contabilidade/lancamentos/classificacao/previsao', ['filtros' => ['codigo_conta' => '6']], $this->sessao(['lancamentos_view']))->assertForbidden();
    }

    #[Test]
    public function descontabiliza_por_estorno_os_lancamentos_manuais_seleccionados(): void
    {
        [$d1, $c1] = $this->lancar('100.00');
        [$d2] = $this->lancar('200.00');

        $sim = $this->postJson('/api/contabilidade/lancamentos/descontabilizar', ['ids' => [$d1, $c1, $d2], 'simular' => true], $this->cab)->assertOk();
        $sim->assertJsonPath('dados.a_estornar', 2)->assertJsonPath('dados.bloqueados', 0);
        $this->assertNull($this->linha($d1)->estornado_por_id);   // a simulação não grava

        $this->postJson('/api/contabilidade/lancamentos/descontabilizar', ['ids' => [$d1, $d2]], $this->cab)->assertStatus(422);   // motivo obrigatório
        $this->postJson('/api/contabilidade/lancamentos/descontabilizar', ['ids' => [$d1, $d2], 'motivo' => 'Duplicados por engano'], $this->cab)
            ->assertOk()->assertJsonCount(2, 'dados.estornados');
        $this->assertNotNull($this->linha($d1)->estornado_por_id);
        $this->assertNotNull($this->linha($d2)->estornado_por_id);
        $this->assertSame(8, DB::table('lancamentos_contabeis')->where('empresa_id', $this->empresa->id)->count());   // nada apagado: 4 + 4 de estorno
        $this->assertTrue(app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('acao', 'Descontabilização em massa')->exists()));

        // de novo: já estornados → nada a fazer
        $this->postJson('/api/contabilidade/lancamentos/descontabilizar', ['ids' => [$d1], 'motivo' => 'Outra vez'], $this->cab)
            ->assertStatus(422)->assertJsonPath('codigo', 'DESCONTABILIZACAO_NADA_A_FAZER');
        // permissão própria (contab_lanc_del)
        $this->postJson('/api/contabilidade/lancamentos/descontabilizar', ['ids' => [$d1], 'motivo' => 'Sem permissão'], $this->sessao(['lancamentos_view', 'lancamentos_post']))->assertForbidden();
    }

    #[Test]
    public function bloqueia_tudo_se_um_lancamento_for_de_um_modulo_ou_de_exercicio_encerrado(): void
    {
        [$manual] = $this->lancar('100.00');
        [$modulo] = $this->lancar('50.00');
        DB::table('lancamentos_contabeis')->where('id', '>=', $modulo)->where('empresa_id', $this->empresa->id)->update(['tipo_origem' => 'VENDAS']);

        $this->postJson('/api/contabilidade/lancamentos/descontabilizar', ['ids' => [$manual, $modulo], 'simular' => true], $this->cab)
            ->assertOk()->assertJsonPath('dados.bloqueados', 1)->assertJsonPath('dados.a_estornar', 1);
        $r = $this->postJson('/api/contabilidade/lancamentos/descontabilizar', ['ids' => [$manual, $modulo], 'motivo' => 'Tentativa'], $this->cab)
            ->assertStatus(422)->assertJsonPath('codigo', 'DESCONTABILIZACAO_BLOQUEADA');
        $this->assertStringContainsString('Vendas', $r->json('mensagem'));
        $this->assertNull($this->linha($manual)->estornado_por_id);   // atómico: nada foi estornado

        // origem do legado: venda contabilizada com o mesmo n.º de documento
        [$legado] = $this->lancar('30.00', '2026-03-15', 'FT-TESTE/1');
        DB::table('lancamentos_contabeis')->where('numero_documento', 'FT-TESTE/1')->update(['tipo_origem' => null]);   // linha migrada do legado
        DB::table('vendas')->insert(['empresa_id' => $this->empresa->id, 'numero_documento' => 'FT-TESTE/1', 'tipo_documento' => 'FT', 'contabilizado' => true]);
        $this->getJson("/api/contabilidade/lancamentos/{$legado}/origem", $this->cab)->assertOk()
            ->assertJsonPath('dados.origem.tipo_origem', 'VENDAS')->assertJsonPath('dados.origem.ecra', 'vendas_faturacao')->assertJsonPath('dados.manual', false);

        // exercício encerrado
        [$antigo] = $this->lancar('5.00', '2025-06-01');
        DB::table('configuracoes_sistema')->insert(['chave' => "closed_year_{$this->empresa->id}_2025", 'valor' => 'true']);
        $this->postJson('/api/contabilidade/lancamentos/descontabilizar', ['ids' => [$antigo], 'simular' => true], $this->cab)
            ->assertOk()->assertJsonPath('dados.bloqueados', 1)->assertJsonPath('dados.lancamentos.0.estado', 'BLOQUEADO');
    }

    #[Test]
    public function origem_de_um_lancamento_manual_e_url_so_http(): void
    {
        [$d] = $this->lancar();
        DB::table('lancamentos_contabeis')->where('id', $d)->update(['url_documento' => 'javascript:alert(1)']);
        $this->getJson("/api/contabilidade/lancamentos/{$d}/origem", $this->cab)->assertOk()
            ->assertJsonPath('dados.manual', true)->assertJsonPath('dados.origem', null)->assertJsonPath('dados.url_documento', null);
        DB::table('lancamentos_contabeis')->where('id', $d)->update(['url_documento' => 'https://exemplo.invalid/x']);
        $this->getJson("/api/contabilidade/lancamentos/{$d}/origem", $this->cab)->assertOk()->assertJsonPath('dados.url_documento', 'https://exemplo.invalid/x');
        // isolamento: a linha de outra empresa não existe para esta
        $alheia = app(ContextoEmpresa::class)->executarComo($this->outra->id, function () {
            $diario = DiarioContabil::query()->value('id');

            return DB::table('lancamentos_contabeis')->insertGetId(['empresa_id' => $this->outra->id, 'diario_id' => $diario, 'data_documento' => '2026-03-15',
                'numero_lan' => 'OD2026999999', 'codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => 1]);
        });
        $this->getJson("/api/contabilidade/lancamentos/{$alheia}/origem", $this->cab)->assertNotFound();
    }

    #[Test]
    public function editar_lancamento_completo_estorna_o_original_e_grava_o_corrigido(): void
    {
        [$d, , $numero] = $this->lancar('100.00');
        $novo = ['diario_id' => $this->diario->id, 'data_documento' => '2026-03-15', 'descricao' => 'Corrigido', 'motivo' => 'Valor errado',
            'linhas' => [['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => '120.00'], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => '120.00']]];
        $r = $this->postJson("/api/contabilidade/lancamentos/{$d}/corrigir", $novo, $this->cab)->assertCreated();
        $this->assertNotSame($numero, $r->json('dados.numero_lan'));
        $this->assertSame('120.00', $r->json('dados.debito'));
        $this->assertNotNull($this->linha($d)->estornado_por_id);

        // desequilibrado: recusado e nada muda
        [$d2] = $this->lancar('50.00');
        $mau = ['linhas' => [['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => '10.00'], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => '9.00']]] + $novo;
        $this->postJson("/api/contabilidade/lancamentos/{$d2}/corrigir", $mau, $this->cab)->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTO_DESEQUILIBRADO');
        $this->assertNull($this->linha($d2)->estornado_por_id);

        // lançamento de um módulo: só a classificação se edita aqui
        DB::table('lancamentos_contabeis')->where('id', $d2)->update(['tipo_origem' => 'COMPRAS_FATURA']);
        $this->postJson("/api/contabilidade/lancamentos/{$d2}/corrigir", $novo, $this->cab)->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTO_DE_MODULO');
        // sem permissão: recusa antes de validar
        $this->postJson("/api/contabilidade/lancamentos/{$d2}/corrigir", [], $this->sessao(['lancamentos_view']))->assertForbidden();
    }

    #[Test]
    public function destinos_da_transferencia_so_com_acesso_e_permissao(): void
    {
        $this->getJson('/api/contabilidade/lancamentos/transferir/destinos', $this->cab)->assertOk()
            ->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.id', $this->outra->id);
        $this->getJson('/api/contabilidade/lancamentos/transferir/destinos', $this->sessao(['lancamentos_view', 'lancamentos_post', 'contab_lanc_transferir'], [$this->empresa->id]))
            ->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson('/api/contabilidade/lancamentos/transferir/destinos', $this->sessao(['lancamentos_view']))->assertForbidden();

        // acesso à outra empresa mas sem poder registar lançamentos: lista vazia e transferência recusada
        [$d] = $this->lancar();
        $semLancar = $this->sessao(['lancamentos_view', 'contab_lanc_transferir']);
        $this->getJson('/api/contabilidade/lancamentos/transferir/destinos', $semLancar)->assertOk()->assertJsonCount(0, 'dados');
        $this->postJson("/api/contabilidade/lancamentos/{$d}/transferir", ['empresa_destino_id' => $this->outra->id, 'motivo' => 'Sem permissão no destino'], $semLancar)
            ->assertStatus(403)->assertJsonPath('codigo', 'SEM_PERMISSAO_DESTINO');
        $this->assertNull($this->linha($d)->estornado_por_id);
    }
}
