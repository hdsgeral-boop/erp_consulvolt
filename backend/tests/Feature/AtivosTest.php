<?php

namespace Tests\Feature;

use App\Exceptions\ErroNegocio;
use App\Models\CentroCusto;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Activos (ADR-051): categorias, cadastro (código automático, bloqueios, edição e eliminação em massa, importação), aquisições
 * pendentes (inventariar e ligar a uma linha 11/12), transferências de centro de custo, afectações a projectos, manutenções e
 * abates/vendas contabilizados com mais/menos-valia e anulação por estorno.
 */
final class AtivosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000052']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['7315' => 'Amortizações do exercício', '1815' => 'Amortizações acumuladas', '68031' => 'Ganhos em imobilizações', '78031' => 'Perdas em imobilizações',
                '1141' => 'Equipamento administrativo', '3211' => 'Fornecedores', '3721' => 'Devedores diversos'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['forn'] = Terceiro::create(['nome' => 'Fornecedor de equipamento', 'tipo' => 'FORNECEDOR', 'codigo_conta' => '3211'])->id;
            $this->ids['cli'] = Terceiro::create(['nome' => 'Comprador', 'tipo' => 'CLIENTE', 'codigo_conta' => '3721'])->id;
            $this->ids['cc1'] = CentroCusto::create(['codigo' => 'ADM', 'descricao' => 'Administração'])->id;
            $this->ids['cc2'] = CentroCusto::create(['codigo' => 'OBR', 'descricao' => 'Obras'])->id;
            $this->ids['p1'] = Projeto::create(['codigo' => 'PRJ-1', 'nome' => 'Obra 1'])->id;
            $this->ids['p2'] = Projeto::create(['codigo' => 'PRJ-2', 'nome' => 'Obra 2'])->id;
        });
        $this->s = $this->sessao(['activos_view', 'activos_gerir', 'activos_eliminar', 'activos_cat_gerir', 'activos_manut', 'activos_amort_calcular',
            'activos_amort_integrar', 'activos_amort_anular', 'activos_abater', 'activos_inventariar']);
        $this->ids['cat'] = $this->postJson('/api/ativos/categorias', ['nome' => 'Equipamento administrativo', 'taxa_anual' => 25, 'vida_util_padrao' => 48,
            'conta_gasto' => '7315', 'conta_amortizacao_acumulada' => '1815', 'conta_venda' => '68031', 'conta_perda' => '78031'], $this->s)->assertCreated()->json('dados.id');
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

    /** Lançamento de compra D 1141 / C 3211 (fornecedor); devolve o id da linha a débito. */
    private function compra(string $valor, string $data = '2026-01-10'): int
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoLancamentos::class)->criar([
            'diario_id' => app(LocalizadorLancamentos::class)->diario('CP', 'Compras')->id, 'data_documento' => $data, 'numero_documento' => 'FT F/1',
            'linhas' => [['codigo_conta' => '1141', 'tipo_dc' => 'D', 'valor' => $valor, 'terceiro_id' => $this->ids['forn']],
                ['codigo_conta' => '3211', 'tipo_dc' => 'C', 'valor' => $valor, 'terceiro_id' => $this->ids['forn']]],
        ])->firstWhere('tipo_dc', 'D')->id);
    }

    private function linhasDe(string $documento): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_documento', $documento)
            ->whereNull('estorno_de_id')->whereNull('estornado_por_id')->orderBy('id')->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
    }

    #[Test]
    public function categorias_validam_contas_e_nao_se_eliminam_em_uso(): void
    {
        $this->postJson('/api/ativos/categorias', ['nome' => 'X', 'conta_gasto' => '1815'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INVALIDA');
        $this->postJson('/api/ativos/categorias', ['nome' => 'X', 'conta_gasto' => '7399'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        $this->postJson('/api/ativos/categorias', ['nome' => 'equipamento ADMINISTRATIVO'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CATEGORIA_DUPLICADA');
        $this->bem(['descricao' => 'Mesa', 'valor_aquisicao' => 100]);
        $this->deleteJson("/api/ativos/categorias/{$this->ids['cat']}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->getJson('/api/ativos/categorias', $this->s)->assertOk()->assertJsonPath('dados.0.ativos', 1);
        $this->postJson('/api/ativos/categorias', ['nome' => 'Y'], $this->sessao(['activos_view']))->assertForbidden();
    }

    #[Test]
    public function cadastro_codigo_automatico_bloqueios_e_operacoes_em_massa(): void
    {
        $a = $this->bem(['descricao' => 'Secretária', 'valor_aquisicao' => 1200, 'vida_util' => 12, 'data_aquisicao' => '2026-01-01']);
        $b = $this->bem(['descricao' => 'Cadeira', 'valor_aquisicao' => 300, 'data_aquisicao' => '2026-01-01']);
        $this->getJson("/api/ativos/bens/{$b}", $this->s)->assertJsonPath('dados.codigo', 'AST-002')->assertJsonPath('dados.vida_util', 48)
            ->assertJsonPath('dados.estado', 'ACTIVO');
        $this->postJson('/api/ativos/bens', ['codigo' => 'AST-001', 'descricao' => 'Outro', 'categoria_ativo_id' => $this->ids['cat']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');
        $this->postJson('/api/ativos/bens', ['descricao' => 'Z', 'categoria_ativo_id' => $this->ids['cat'], 'valor_aquisicao' => 100, 'valor_residual' => 200], $this->s)
            ->assertStatus(422);
        $this->putJson("/api/ativos/bens/{$b}", ['estado' => 'ABATIDO'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_ABATE');
        $this->putJson("/api/ativos/bens/{$b}", ['estado' => 'INACTIVO'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'INACTIVO');

        // com quotas calculadas, a edição em massa dos campos de cálculo é recusada para todos
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['01-2026']], $this->s)->assertOk();
        $this->postJson('/api/ativos/bens/edicao-massa', ['ids' => [$a, $b], 'campos' => ['vida_util' => 24]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'EDICAO_MASSA_RECUSADA');
        $this->getJson("/api/ativos/bens/{$b}", $this->s)->assertJsonPath('dados.vida_util', 48);
        $this->postJson('/api/ativos/bens/edicao-massa', ['ids' => [$a, $b], 'campos' => ['centro_custo_id' => $this->ids['cc1'], 'descricao' => 'Mobiliário']], $this->s)
            ->assertOk()->assertJsonPath('dados.actualizados', 2);

        // eliminar: os rascunhos saem com o activo; com quotas integradas é recusado
        $this->postJson('/api/ativos/amortizacoes/integrar', ['periodos' => ['01-2026']], $this->s)->assertOk();
        $this->postJson('/api/ativos/bens/eliminar', ['ids' => [$a, $b]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ATIVO_COM_AMORTIZACOES');
        $this->deleteJson("/api/ativos/bens/{$b}", [], $this->s)->assertOk();
        $this->getJson('/api/ativos/bens', $this->s)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.bloqueado', true)
            ->assertJsonPath('dados.0.valor_liquido', '1100.00');
        $this->postJson('/api/ativos/bens', ['codigo' => 'AST-002', 'descricao' => 'Reuso', 'categoria_ativo_id' => $this->ids['cat']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');
        $this->deleteJson("/api/ativos/bens/{$a}", [], $this->sessao(['activos_gerir']))->assertForbidden();
    }

    #[Test]
    public function importacao_cria_categorias_e_respeita_a_decisao_sobre_existentes(): void
    {
        $this->bem(['codigo' => 'MIG-1', 'descricao' => 'Antigo', 'valor_aquisicao' => 500]);
        $linhas = [
            ['codigo' => 'MIG-1', 'descricao' => 'Antigo renomeado', 'categoria' => 'Viaturas', 'valor_aquisicao' => 999],
            ['codigo' => 'MIG-2', 'descricao' => 'Viatura', 'categoria' => 'Viaturas', 'valor_aquisicao' => 2400000, 'vida_util' => 48, 'anos_amortizados' => 2,
                'amortizacao_acumulada' => 1200000, 'ano_amortizacao_acumulada' => 2025, 'data_aquisicao' => '2024-01-15'],
            ['codigo' => 'mig-2', 'descricao' => 'Repetida'],
            ['codigo' => '', 'descricao' => 'Sem código'],
        ];
        $sim = $this->postJson('/api/ativos/bens/importar', ['linhas' => $linhas, 'simular' => true], $this->s)->assertOk()->json('dados');
        $this->assertSame([1, 1, ['MIG-1 — Antigo']], [$sim['novos'], $sim['repetidos'], $sim['existentes']]);

        $r = $this->postJson('/api/ativos/bens/importar', ['linhas' => $linhas, 'decisao' => 'ACTUALIZAR'], $this->s)->assertOk()->json('dados');
        $this->assertSame([1, 1, ['Viaturas']], [$r['importados'], $r['actualizados'], $r['categorias_criadas']]);
        $bens = collect($this->getJson('/api/ativos/bens', $this->s)->json('dados'))->keyBy('codigo');
        $this->assertSame(['Antigo renomeado', '500.00'], [$bens['MIG-1']['descricao'], $bens['MIG-1']['valor_aquisicao']]);
        $this->assertSame([24, '1200000.00', 2025, '2024-01-15'], [$bens['MIG-2']['vida_util_restante'], $bens['MIG-2']['amortizacao_acumulada'],
            $bens['MIG-2']['acumulado_fim_ano'], substr($bens['MIG-2']['data_aquisicao'], 0, 10)]);
        $this->assertSame('25.0000', collect($this->getJson('/api/ativos/categorias', $this->s)->json('dados'))->firstWhere('nome', 'Viaturas')['taxa_anual']);
    }

    #[Test]
    public function aquisicoes_pendentes_inventariam_e_ligam_sem_exceder_a_linha(): void
    {
        $linha = $this->compra('100000');
        $p = $this->getJson('/api/ativos/aquisicoes-pendentes', $this->s)->assertOk()->json('dados');
        $this->assertSame(['100000.00', 'PENDENTE', 'Fornecedor de equipamento'], [$p['total_por_inventariar'], $p['linhas'][0]['estado'], $p['linhas'][0]['terceiro']]);

        $item = ['descricao' => 'Portátil', 'categoria_ativo_id' => $this->ids['cat'], 'valor_aquisicao' => 60000, 'vida_util' => 36];
        $this->postJson("/api/ativos/aquisicoes-pendentes/{$linha}/inventariar", ['itens' => [$item, $item]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXCEDE_AQUISICAO');
        $r = $this->postJson("/api/ativos/aquisicoes-pendentes/{$linha}/inventariar", ['itens' => [['valor_aquisicao' => 40000] + $item, ['valor_aquisicao' => 40000, 'codigo' => 'PT-2'] + $item]], $this->s)
            ->assertCreated()->json('dados');
        $this->assertSame(['2026-01-10', $this->ids['forn'], $linha, '20000.00'], [substr($r['ativos'][0]['data_aquisicao'], 0, 10), $r['ativos'][0]['fornecedor_id'],
            $r['ativos'][0]['lancamento_contabil_id'], $r['por_inventariar']]);
        $this->assertSame('PARCIAL', $this->getJson('/api/ativos/aquisicoes-pendentes', $this->s)->json('dados.linhas.0.estado'));

        $grande = $this->bem(['descricao' => 'Monitor', 'valor_aquisicao' => 30000]);
        $certo = $this->bem(['descricao' => 'Teclado', 'valor_aquisicao' => 20000]);
        $this->postJson("/api/ativos/aquisicoes-pendentes/{$linha}/ligar", ['ids' => [$grande]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXCEDE_AQUISICAO');
        $this->postJson("/api/ativos/aquisicoes-pendentes/{$linha}/ligar", ['ids' => [$certo]], $this->s)->assertOk()->assertJsonPath('dados.por_inventariar', '0.00');
        $this->getJson("/api/ativos/bens/{$certo}", $this->s)->assertJsonPath('dados.fornecedor_id', $this->ids['forn'])->assertJsonPath('dados.origem.codigo_conta', '1141');
        $this->assertSame([], $this->getJson('/api/ativos/aquisicoes-pendentes', $this->s)->json('dados.linhas'));

        // a compra ligada a activos não se estorna (pré-condição do estorno, ADR-016)
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($linha) {
            try {
                app(ServicoLancamentos::class)->estornar(LancamentoContabil::query()->findOrFail($linha), 'teste');
                $this->fail('O estorno devia ser recusado.');
            } catch (ErroNegocio $e) {
                $this->assertSame('LANCAMENTO_COM_ATIVOS', $e->codigo);
            }
        });
    }

    #[Test]
    public function transferencias_afetacoes_e_manutencoes(): void
    {
        $a = $this->bem(['descricao' => 'Gerador', 'valor_aquisicao' => 5000, 'centro_custo_id' => $this->ids['cc1']]);
        $this->postJson("/api/ativos/bens/{$a}/transferencias", ['centro_custo_destino_id' => $this->ids['cc1'], 'data' => '2026-03-01'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MESMO_CENTRO_CUSTO');
        $this->postJson("/api/ativos/bens/{$a}/transferencias", ['centro_custo_destino_id' => $this->ids['cc2'], 'data' => '2026-03-01', 'projeto_id' => $this->ids['p1']], $this->s)
            ->assertCreated()->assertJsonPath('dados.centro_custo_origem_id', $this->ids['cc1'])->assertJsonPath('dados.codigo_projeto', 'PRJ-1');
        $this->getJson("/api/ativos/bens/{$a}", $this->s)->assertJsonPath('dados.centro_custo_id', $this->ids['cc2'])->assertJsonCount(1, 'dados.transferencias');

        $this->postJson('/api/ativos/afetacoes', ['projeto_id' => $this->ids['p1'], 'ativo_imobilizado_id' => $a, 'data_inicio' => '2026-03-01', 'data_fim' => '2026-06-30'], $this->s)
            ->assertCreated();
        $this->postJson('/api/ativos/afetacoes', ['projeto_id' => $this->ids['p2'], 'ativo_imobilizado_id' => $a, 'data_inicio' => '2026-06-01'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'AFETACAO_SOBREPOSTA');
        $f = $this->postJson('/api/ativos/afetacoes', ['projeto_id' => $this->ids['p2'], 'ativo_imobilizado_id' => $a, 'data_inicio' => '2026-07-01'], $this->s)
            ->assertCreated()->json('dados.id');
        $this->putJson("/api/ativos/afetacoes/{$f}", ['data_fim' => '2026-05-01'], $this->s)->assertStatus(422);
        $this->getJson("/api/ativos/afetacoes?projeto_id={$this->ids['p2']}", $this->s)->assertOk()->assertJsonCount(1, 'dados');

        $m = $this->postJson('/api/ativos/manutencoes', ['ativo_imobilizado_id' => $a, 'tipo' => 'PREVENTIVA', 'data' => '2026-04-01', 'descricao' => 'Revisão'], $this->s)
            ->assertCreated()->assertJsonPath('dados.estado', 'PLANEADA')->json('dados.id');
        $this->postJson('/api/ativos/manutencoes', ['ativo_imobilizado_id' => $a, 'tipo' => 'CORRECTIVA', 'data' => '2026-04-02', 'custo' => 1500], $this->s)
            ->assertCreated()->assertJsonPath('dados.estado', 'CONCLUIDA');
        $this->postJson("/api/ativos/manutencoes/{$m}/executar", ['resolucao' => 'Filtros trocados', 'custo' => 800], $this->s)->assertOk()
            ->assertJsonPath('dados.estado', 'CONCLUIDA')->assertJsonPath('dados.custo', '800.00');
        $this->postJson("/api/ativos/manutencoes/{$m}/executar", ['resolucao' => 'De novo'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MANUTENCAO_CONCLUIDA');
        $this->deleteJson("/api/ativos/manutencoes/{$m}", [], $this->s)->assertOk();
        $this->assertCount(1, $this->getJson('/api/ativos/manutencoes', $this->s)->json('dados'));
    }

    #[Test]
    public function abate_e_venda_contabilizam_mais_e_menos_valia_e_anulam_por_estorno(): void
    {
        $linha = $this->compra('1200', '2026-01-05');
        $a = $this->postJson("/api/ativos/aquisicoes-pendentes/{$linha}/inventariar", ['itens' => [['descricao' => 'Servidor', 'categoria_ativo_id' => $this->ids['cat'],
            'valor_aquisicao' => 1200, 'vida_util' => 12, 'centro_custo_id' => $this->ids['cc1']]]], $this->s)->assertCreated()->json('dados.ativos.0.id');
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['01-2026', '02-2026', '03-2026']], $this->s)->assertOk();

        $venda = ['ativo_imobilizado_id' => $a, 'tipo' => 'VENDA', 'data' => '2026-03-31', 'valor' => 1000, 'terceiro_id' => $this->ids['cli'], 'conta_terceiro' => '3721'];
        $this->postJson('/api/ativos/abates', $venda, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ATIVO_COM_RASCUNHOS');
        $this->postJson('/api/ativos/amortizacoes/integrar', ['periodos' => ['01-2026', '02-2026', '03-2026']], $this->s)->assertOk();
        $this->postJson('/api/ativos/abates', ['conta_terceiro' => null] + $venda, $this->s)->assertStatus(422);
        $this->postJson('/api/ativos/abates', ['data' => '2026-02-15'] + $venda, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'QUOTAS_POSTERIORES');

        $sim = $this->postJson('/api/ativos/abates/simulacao', $venda, $this->s)->assertOk()->json('dados');
        $this->assertSame(['300.00', '900.00', '100.00'], [$sim['amortizacao_acumulada'], $sim['valor_liquido'], $sim['resultado']]);
        $r = $this->postJson('/api/ativos/abates', $venda, $this->s)->assertCreated()->json('dados');
        $this->assertSame(['C 1141 1200.00', 'D 1815 300.00', 'D 3721 1000.00', 'C 68031 100.00'], $this->linhasDe("ABT-{$r['abate']['id']}"));
        $this->getJson("/api/ativos/bens/{$a}", $this->s)->assertJsonPath('dados.estado', 'ABATIDO');
        $this->postJson('/api/ativos/amortizacoes/reabrir', ['periodos' => ['03-2026']], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ATIVO_ABATIDO');
        $this->postJson("/api/ativos/bens/{$a}/transferencias", ['centro_custo_destino_id' => $this->ids['cc2'], 'data' => '2026-04-01'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'ATIVO_ABATIDO');

        $this->postJson("/api/ativos/abates/{$r['abate']['id']}/anular", ['motivo' => 'Venda desfeita'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ACTIVO');
        $this->assertSame([], $this->linhasDe("ABT-{$r['abate']['id']}"));

        // sinistro sem indemnização: menos-valia = valor líquido
        $s = $this->postJson('/api/ativos/abates', ['ativo_imobilizado_id' => $a, 'tipo' => 'SINISTRO', 'data' => '2026-03-31', 'descricao' => 'Incêndio'], $this->s)
            ->assertCreated()->json('dados.abate.id');
        $this->assertSame(['C 1141 1200.00', 'D 1815 300.00', 'D 78031 900.00'], $this->linhasDe("ABT-{$s}"));
        $this->assertCount(1, $this->getJson('/api/ativos/abates', $this->s)->json('dados'));
        $this->assertSame([], $this->getJson('/api/ativos/mapas/categorias', $this->s)->assertOk()->json('dados'));   // abatidos saem do resumo

        // sem registo contabilístico (activos cuja compra nunca foi contabilizada), como no legado
        $b = $this->bem(['descricao' => 'Migrado', 'valor_aquisicao' => 500, 'data_aquisicao' => '2026-01-01']);
        $this->postJson('/api/ativos/abates', ['ativo_imobilizado_id' => $b, 'tipo' => 'FIM_VIDA', 'data' => '2026-01-31'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SEM_CONTA_ATIVO');
        $this->postJson('/api/ativos/abates', ['ativo_imobilizado_id' => $b, 'tipo' => 'FIM_VIDA', 'data' => '2026-01-31', 'contabilizar' => false], $this->s)
            ->assertCreated()->assertJsonPath('dados.numero_lan', null);
    }
}
