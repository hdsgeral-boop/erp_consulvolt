<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Models\PlanoConta;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** Módulo Contabilidade: lançamentos, numeração, estorno (ADR-016), mapas, permissões e validações. */
final class ContabilidadeTest extends TestCase
{
    private Empresa $empresa;

    private DiarioContabil $diario;

    private array $cabecalhos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['11', 'Caixa (totalizadora)', 'T'], ['111', 'Caixa', 'M'], ['211', 'Clientes', 'M'], ['611', 'Vendas', 'M']] as [$c, $d, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => $t]);
            }
            $this->diario = DiarioContabil::create(['codigo' => 'VD', 'descricao' => 'VENDAS']);
        });
        $this->cabecalhos = $this->sessao(['lancamentos_view', 'lancamentos_post', 'contab_lanc_transferir', 'contab_mapa_balancete_view',
            'relatorios_contabeis_view', 'config_plano_view', 'contab_plano_gerir', 'config_manutencao_view']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function lancamento(array $linhas, string $data = '2026-03-15', array $cabecalhos = []): TestResponse
    {
        return $this->postJson('/api/contabilidade/lancamentos', ['diario_id' => $this->diario->id, 'data_documento' => $data,
            'descricao' => 'Venda a dinheiro', 'linhas' => $linhas], $cabecalhos ?: $this->cabecalhos);
    }

    private function equilibrado(string $valor = '1000.00'): array
    {
        return [['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => $valor], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => $valor]];
    }

    #[Test]
    public function grava_um_lancamento_equilibrado_com_numero_do_diario(): void
    {
        $r = $this->lancamento($this->equilibrado())->assertCreated()
            ->assertJsonPath('dados.numero_lan', 'VD2026000001')
            ->assertJsonPath('dados.equilibrado', true)
            ->assertJsonPath('dados.debito', '1000.00')
            ->assertJsonCount(2, 'dados.linhas');

        $this->assertSame('MANUAL', $r->json('dados.linhas.0.tipo_origem'));
        $this->lancamento($this->equilibrado('50.10'))->assertJsonPath('dados.numero_lan', 'VD2026000002');
        $this->lancamento($this->equilibrado(), '2027-01-02')->assertJsonPath('dados.numero_lan', 'VD2027000001');   // sequência por ano
        $this->assertSame(6, app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('tabela', 'lancamentos_contabeis')->where('acao', 'Criou')->count()));
    }

    #[Test]
    public function a_numeracao_continua_a_do_legado(): void
    {
        DB::table('lancamentos_contabeis')->insert(['id' => 500, 'empresa_id' => $this->empresa->id, 'diario_id' => $this->diario->id,
            'data_documento' => '2026-01-10', 'codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => 1, 'numero_lan' => 'VD2026000041']);
        DB::table('lancamentos_contabeis')->insert(['id' => 501, 'empresa_id' => $this->empresa->id, 'diario_id' => $this->diario->id,
            'data_documento' => '2026-01-10', 'codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => 1, 'referencia' => 'VD2026000057']);

        $this->lancamento($this->equilibrado())->assertJsonPath('dados.numero_lan', 'VD2026000058');
    }

    #[Test]
    public function um_numero_nao_se_perde_quando_a_transaccao_do_documento_falha(): void
    {
        $numeracao = app(ServicoNumeracao::class);
        try {
            DB::transaction(function () use ($numeracao) {
                $this->assertSame(1, $numeracao->proximo($this->empresa->id, 'teste', fn () => 0));
                throw new RuntimeException('falha ao gravar o documento');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $numeracao->proximo($this->empresa->id, 'teste', fn () => 0));
        $this->assertSame(2, $numeracao->proximo($this->empresa->id, 'teste', fn () => 0));
    }

    #[Test]
    public function recusa_lancamentos_desequilibrados_em_contas_invalidas_ou_em_exercicio_encerrado(): void
    {
        $this->lancamento([['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => '100.00'], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => '99.99']])
            ->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTO_DESEQUILIBRADO')->assertJsonPath('erros.diferenca', '0.01');

        $this->lancamento([['codigo_conta' => '11', 'tipo_dc' => 'D', 'valor' => 5], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => 5]])
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_TOTALIZADORA');
        $this->lancamento([['codigo_conta' => '999', 'tipo_dc' => 'D', 'valor' => 5], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => 5]])
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        $this->lancamento([['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => '1.005'], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => '1.005']])
            ->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO');

        DB::table('configuracoes_sistema')->insert(['chave' => "closed_year_{$this->empresa->id}_2025", 'valor' => 'true']);
        $this->lancamento($this->equilibrado(), '2025-12-31')->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_ENCERRADO');
        $this->assertSame(0, DB::table('lancamentos_contabeis')->count());
    }

    #[Test]
    public function estorno_cria_o_lancamento_inverso_e_nunca_apaga_o_original(): void
    {
        $original = $this->lancamento($this->equilibrado('250.00'))->json('dados.linhas');

        $estorno = $this->postJson("/api/contabilidade/lancamentos/{$original[0]['id']}/estornar", ['motivo' => 'Factura anulada'], $this->cabecalhos)
            ->assertCreated()->assertJsonPath('dados.numero_lan', 'VD2026000002')->assertJsonPath('dados.equilibrado', true)->json('dados.linhas');

        $this->assertSame(['C', 'D'], array_column($estorno, 'tipo_dc'));
        $this->assertSame([$original[0]['id'], $original[1]['id']], array_column($estorno, 'estorno_de_id'));
        $this->assertSame('ESTORNO', $estorno[0]['tipo_origem']);
        $this->assertSame(4, DB::table('lancamentos_contabeis')->count());   // nada foi apagado
        $this->assertSame(2, DB::table('lancamentos_contabeis')->whereNotNull('estornado_por_id')->count());

        // Saldo líquido zero; excluindo estornos, a conta fica sem movimento
        $balancete = $this->getJson('/api/contabilidade/relatorios/balancete?data_inicio=2026-01-01&data_fim=2026-12-31', $this->cabecalhos)->json('dados');
        $this->assertSame('0.00', collect($balancete['linhas'])->firstWhere('codigo_conta', '111')['saldo_final']);
        $sem = $this->getJson('/api/contabilidade/relatorios/balancete?data_inicio=2026-01-01&data_fim=2026-12-31&excluir_estornos=1', $this->cabecalhos)->json('dados');
        $this->assertSame([], $sem['linhas']);

        $this->postJson("/api/contabilidade/lancamentos/{$original[0]['id']}/estornar", ['motivo' => 'de novo'], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'JA_ESTORNADO');
        $this->postJson("/api/contabilidade/lancamentos/{$estorno[0]['id']}/estornar", ['motivo' => 'estorno do estorno'], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'ESTORNO_DE_ESTORNO');
    }

    #[Test]
    public function estorno_de_lancamento_reconciliado_e_bloqueado_e_em_exercicio_encerrado_usa_a_data_de_hoje(): void
    {
        $linhas = $this->lancamento($this->equilibrado())->json('dados.linhas');
        DB::table('lancamentos_contabeis')->where('id', $linhas[0]['id'])->update(['reconciliacao_codigo' => 'REC-1']);
        $this->postJson("/api/contabilidade/lancamentos/{$linhas[1]['id']}/estornar", ['motivo' => 'teste de bloqueio'], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTO_RECONCILIADO');

        $outro = $this->lancamento($this->equilibrado(), '2025-06-30')->json('dados.linhas');
        DB::table('configuracoes_sistema')->insert(['chave' => "closed_year_{$this->empresa->id}_2025", 'valor' => 'true']);
        $this->travelTo(now()->setDate(2026, 9, 29));
        $this->postJson("/api/contabilidade/lancamentos/{$outro[0]['id']}/estornar", ['motivo' => 'erro de conta'], $this->cabecalhos)
            ->assertCreated()->assertJsonPath('dados.data_documento', '2026-09-29')->assertJsonPath('dados.numero_lan', 'VD2026000002');
    }

    #[Test]
    public function balancete_agrega_por_nivel_e_razao_acumula_o_saldo(): void
    {
        $this->lancamento([['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => '100.00'], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => '100.00']], '2025-12-20');
        $this->lancamento([['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => '40.00'], ['codigo_conta' => '211', 'tipo_dc' => 'D', 'valor' => '60.00'],
            ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => '100.00']], '2026-02-01');
        $this->lancamento([['codigo_conta' => '611', 'tipo_dc' => 'D', 'valor' => '15.00'], ['codigo_conta' => '111', 'tipo_dc' => 'C', 'valor' => '15.00']], '2026-02-03');

        $b = $this->getJson('/api/contabilidade/relatorios/balancete?data_inicio=2026-01-01&data_fim=2026-12-31', $this->cabecalhos)->assertOk()->json('dados');
        $caixa = collect($b['linhas'])->firstWhere('codigo_conta', '111');
        $this->assertSame(['100.00', '40.00', '15.00', '125.00'], [$caixa['saldo_inicial'], $caixa['debito'], $caixa['credito'], $caixa['saldo_final']]);
        $this->assertSame('Caixa', $caixa['descricao']);
        $this->assertSame('0.00', $b['totais']['saldo_final']);

        $classes = $this->getJson('/api/contabilidade/relatorios/balancete?data_inicio=2026-01-01&data_fim=2026-12-31&nivel=1', $this->cabecalhos)->json('dados.linhas');
        $this->assertSame(['1', '2', '6'], array_column($classes, 'codigo_conta'));

        $razao = $this->getJson('/api/contabilidade/relatorios/razao?codigo_conta=111&data_inicio=2026-01-01&data_fim=2026-12-31', $this->cabecalhos)->assertOk()->json('dados');
        $this->assertSame('100.00', $razao['saldo_inicial']);
        $this->assertSame(['140.00', '125.00'], array_column($razao['movimentos'], 'saldo'));
        $this->assertSame('125.00', $razao['saldo_final']);
    }

    #[Test]
    public function relatorio_e_validacao_de_desequilibrios_mostram_os_lancamentos_do_legado(): void
    {
        foreach ([['D', 300], ['C', 100]] as $i => [$dc, $v]) {
            DB::table('lancamentos_contabeis')->insert(['id' => 900 + $i, 'empresa_id' => $this->empresa->id, 'diario_id' => $this->diario->id,
                'data_documento' => '2026-04-01', 'codigo_conta' => '111', 'tipo_dc' => $dc, 'valor' => $v, 'numero_documento' => 'FT 2026/113']);
        }
        $this->lancamento($this->equilibrado());

        $r = $this->getJson('/api/contabilidade/relatorios/desequilibrios', $this->cabecalhos)->assertOk()->json('dados');
        $this->assertSame(1, $r['resumo']['lancamentos_desequilibrados']);
        $this->assertSame('200.00', $r['resumo']['diferenca']);
        $this->assertSame('FT 2026/113', $r['lancamentos'][0]['lancamento']);

        $validacoes = collect($this->getJson('/api/sistema/validacoes', $this->cabecalhos)->assertOk()->json('dados'))->keyBy('codigo');
        $this->assertSame(1, $validacoes['lancamentos_desequilibrados']['ocorrencias']);
        $this->assertGreaterThanOrEqual(10, $validacoes->count());   // todas as consultas executam sem erro
        $this->getJson('/api/sistema/validacoes/lancamentos_desequilibrados', $this->cabecalhos)->assertOk()->assertJsonCount(1, 'dados.linhas');
        $this->getJson('/api/sistema/validacoes/nao_existe', $this->cabecalhos)->assertNotFound();
    }

    #[Test]
    public function permissoes_do_catalogo_do_legado_e_isolamento_entre_empresas(): void
    {
        $linhas = $this->lancamento($this->equilibrado())->json('dados.linhas');

        // Perfil só de consulta (ex.: Contabilista Junior convertido): vê mas não grava nem estorna
        $consulta = $this->sessao(['lancamentos_view', 'contab_mapa_balancete_view']);
        $this->getJson("/api/contabilidade/lancamentos/{$linhas[0]['id']}", $consulta)->assertOk()->assertJsonCount(2, 'dados.linhas');
        $this->lancamento($this->equilibrado(), '2026-03-15', $consulta)->assertStatus(403)->assertJsonPath('codigo', 'SEM_PERMISSAO');
        $this->postJson("/api/contabilidade/lancamentos/{$linhas[0]['id']}/estornar", ['motivo' => 'sem permissão'], $consulta)->assertStatus(403);

        // Ter uma tarefa do ecrã torna o ecrã visível (semântica ecraVisivel do legado)
        $soGravar = $this->sessao(['lancamentos_post']);
        $this->getJson('/api/contabilidade/lancamentos', $soGravar)->assertOk();

        // Outra empresa: o lançamento não existe para ela
        $outra = $this->criarEmpresa();
        $this->getJson("/api/contabilidade/lancamentos/{$linhas[0]['id']}", $this->sessao(['lancamentos_view'], $outra))->assertNotFound();
    }

    #[Test]
    public function plano_de_contas_em_cache_e_invalidado_nas_escritas(): void
    {
        $this->getJson('/api/contabilidade/plano-contas?tipo=M', $this->cabecalhos)->assertOk()->assertJsonCount(3, 'dados');

        $this->postJson('/api/contabilidade/plano-contas', ['codigo' => '4111', 'descricao' => 'Banco BAI', 'tipo' => 'M', 'codigo_moeda' => 'USD'], $this->cabecalhos)->assertCreated();
        $this->getJson('/api/contabilidade/plano-contas?prefixo=41', $this->cabecalhos)->assertJsonPath('dados.0.codigo', '4111');

        $this->postJson('/api/contabilidade/plano-contas', ['codigo' => '2112', 'descricao' => 'X', 'tipo' => 'M', 'codigo_moeda' => 'USD'], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'MOEDA_SO_CLASSE_4');
        $this->postJson('/api/contabilidade/plano-contas', ['codigo' => '111', 'descricao' => 'Dup', 'tipo' => 'M'], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_DUPLICADA');

        $this->lancamento($this->equilibrado());
        $caixa = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PlanoConta::query()->where('codigo', '111')->value('id'));
        $this->deleteJson("/api/contabilidade/plano-contas/{$caixa}", [], $this->cabecalhos)->assertStatus(403);   // falta contab_tabelas_del
    }
}
