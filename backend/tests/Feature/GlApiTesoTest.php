<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\LinhaExtratoBancario;
use App\Models\MovimentoCaixa;
use App\Models\OrcamentoAnual;
use App\Models\PlanoConta;
use App\Models\ReconciliacaoBancaria;
use App\Models\RubricaOrcamental;
use App\Models\SessaoCaixa;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Campos que os ecrãs novos de Tesouraria e Orçamento esperam (gl_api_teso): contrapartidas e estado de reconciliação no
 * extracto da conta, descrição e totais das sessões de caixa, quadro-resumo do mapa de reconciliação e realizado do ano
 * anterior na ficha do orçamento. Dados fictícios em duas empresas (isolamento) e valores exactos.
 */
final class GlApiTesoTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa();
        $this->b = $this->criarEmpresa();
        $this->em($this->a, function () {
            foreach (['4311' => 'Banco BAI', '4511' => 'Caixa Central', '752' => 'Serviços', '3211' => 'Fornecedores', '6211' => 'Vendas'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $diario = DiarioContabil::create(['codigo' => 'DG', 'nome' => 'Diário geral'])->id;
            $l = fn (string $data, string $lan, array $linhas, array $extra = []) => array_map(fn ($x) => LancamentoContabil::create($extra + [
                'diario_id' => $diario, 'data_documento' => $data, 'numero_lan' => $lan, 'numero_documento' => $lan, 'descricao' => "Lançamento {$lan}",
                'codigo_conta' => $x[0], 'tipo_dc' => $x[1], 'valor' => $x[2], 'reconciliacao_codigo' => $x[3] ?? null]), $linhas);
            // extracto/mapa: L1 reconciliado, L2 por reconciliar, L3 depois da data do mapa
            $l('2026-03-10', 'L1', [['4311', 'D', '1000.00', 'REC-20260315-0001'], ['6211', 'C', '600.00'], ['3211', 'C', '400.00']]);
            $l('2026-03-12', 'L2', [['752', 'D', '250.25'], ['4311', 'C', '250.25']]);
            $l('2026-04-02', 'L3', [['4311', 'D', '99.99'], ['6211', 'C', '99.99']]);
            ReconciliacaoBancaria::create(['reconciliacao_codigo' => 'REC-20260315-0001', 'data' => '2026-03-15 10:00:00', 'valor_total' => 1000, 'estado' => 'CONCILIADO_BANCO']);
            foreach ([['2026-03-11', 'C', '1000.00', 'CONCILIADO'], ['2026-03-13', 'D', '250.25', 'PENDENTE'], ['2026-03-20', 'D', '10.10', 'ANULADO'], ['2026-04-05', 'C', '5000.00', 'PENDENTE']] as $e) {
                LinhaExtratoBancario::create(['codigo_conta' => '4311', 'data' => $e[0], 'tipo_dc' => $e[1], 'valor' => $e[2], 'estado' => $e[3], 'descricao' => 'Extracto']);
            }

            // caixa: uma sessão com movimentos e outra sem
            $s = SessaoCaixa::create(['codigo_conta' => '4511', 'operador' => 'op', 'data_abertura' => '2026-03-01', 'saldo_abertura' => 0, 'estado' => 'ABERTA']);
            foreach ([['REC', '100.10'], ['REC', '50.05'], ['PAG', '30.00']] as $m) {
                MovimentoCaixa::create(['sessao_caixa_id' => $s->id, 'tipo' => $m[0], 'data_documento' => '2026-03-01', 'valor' => $m[1], 'conta_debito' => '4511', 'contabilizado' => false]);
            }
            $this->ids['sessao'] = $s->id;
            $this->ids['sessao_vazia'] = SessaoCaixa::create(['codigo_conta' => '4511', 'operador' => 'op', 'data_abertura' => '2026-02-01', 'saldo_abertura' => 0, 'estado' => 'ABERTA'])->id;

            // orçamento 2026 com âmbito UN1; realizado de 2025 (UN1 e UN2) e de 2026 (não conta)
            $un1 = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Sede'])->id;
            $un2 = UnidadeNegocio::create(['codigo' => 'UN2', 'nome' => 'Filial'])->id;
            $this->ids['R1'] = RubricaOrcamental::create(['tipo' => 'EXPLORACAO', 'codigo' => 'P01', 'nome' => 'Vendas', 'natureza' => 'PROVEITO', 'contas' => [['codigo' => '62', 'prefixo' => true]], 'ativo' => true])->id;
            $this->ids['R2'] = RubricaOrcamental::create(['tipo' => 'EXPLORACAO', 'codigo' => 'C04', 'nome' => 'Serviços', 'natureza' => 'CUSTO', 'contas' => [['codigo' => '75', 'prefixo' => true]], 'ativo' => true])->id;
            $this->ids['R3'] = RubricaOrcamental::create(['tipo' => 'EXPLORACAO', 'codigo' => 'C09', 'nome' => 'Sem movimento', 'natureza' => 'CUSTO', 'contas' => [['codigo' => '79', 'prefixo' => true]], 'ativo' => true])->id;
            $l('2025-05-10', 'O1', [['6211', 'C', '300.10'], ['3211', 'D', '300.10']], ['unidade_negocio_id' => $un1]);
            $l('2025-06-10', 'O2', [['6211', 'C', '50.00'], ['3211', 'D', '50.00']], ['unidade_negocio_id' => $un2]);
            $l('2025-07-10', 'O3', [['752', 'D', '120.40'], ['3211', 'C', '120.40']], ['unidade_negocio_id' => $un1]);
            $l('2025-11-10', 'O4', [['752', 'C', '20.00'], ['3211', 'D', '20.00']], ['unidade_negocio_id' => $un1]);
            $this->ids['orc_un'] = OrcamentoAnual::create(['ano' => 2026, 'tipo' => 'EXPLORACAO', 'nome' => 'Sede', 'versao' => 1, 'estado' => 'RASCUNHO', 'unidade_negocio_id' => $un1])->id;
            $this->ids['orc'] = OrcamentoAnual::create(['ano' => 2026, 'tipo' => 'EXPLORACAO', 'nome' => 'Empresa', 'versao' => 1, 'estado' => 'RASCUNHO'])->id;
            $this->ids['orc_2024'] = OrcamentoAnual::create(['ano' => 2024, 'tipo' => 'EXPLORACAO', 'nome' => 'Antigo', 'versao' => 1, 'estado' => 'RASCUNHO'])->id;
        });
        // empresa B: mesmas contas e chaves de lançamento — nada pode aparecer na empresa A
        $this->em($this->b, function () {
            foreach (['4311' => 'Banco B', '4511' => 'Caixa B', '6211' => 'Vendas B'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $diario = DiarioContabil::create(['codigo' => 'DG', 'nome' => 'Diário geral'])->id;
            foreach ([['2026-03-10', '4311', 'D', 'L1'], ['2026-03-10', '6211', 'C', 'L1'], ['2025-05-10', '6211', 'C', 'L9'], ['2025-05-10', '4511', 'D', 'L9']] as $x) {
                LancamentoContabil::create(['diario_id' => $diario, 'data_documento' => $x[0], 'numero_lan' => $x[3], 'numero_documento' => $x[3],
                    'codigo_conta' => $x[1], 'tipo_dc' => $x[2], 'valor' => '777.00', 'reconciliacao_codigo' => 'REC-20260315-0001']);
            }
            ReconciliacaoBancaria::create(['reconciliacao_codigo' => 'REC-20260315-0001', 'data' => '2020-01-01 10:00:00', 'valor_total' => 777, 'estado' => 'CONCILIADO_BANCO']);
            LinhaExtratoBancario::create(['codigo_conta' => '4311', 'data' => '2026-03-11', 'tipo_dc' => 'C', 'valor' => '777.00', 'estado' => 'PENDENTE']);
            $s = SessaoCaixa::create(['codigo_conta' => '4511', 'operador' => 'op', 'data_abertura' => '2026-03-01', 'saldo_abertura' => 0, 'estado' => 'ABERTA']);
            MovimentoCaixa::create(['sessao_caixa_id' => $s->id, 'tipo' => 'REC', 'data_documento' => '2026-03-01', 'valor' => '777.00', 'conta_debito' => '4511', 'contabilizado' => false]);
            $this->ids['orc_b'] = OrcamentoAnual::create(['ano' => 2026, 'tipo' => 'EXPLORACAO', 'nome' => 'B', 'versao' => 1, 'estado' => 'RASCUNHO'])->id;
        });
    }

    private function em(Empresa $e, \Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($e->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $e = null): array
    {
        $e ??= $this->a;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($e->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $e->id];
    }

    #[Test]
    public function extracto_da_conta_traz_contrapartidas_e_reconciliacao(): void
    {
        $s = $this->sessao(['teso_gestao_mapas_view']);
        $r = $this->getJson('/api/tesouraria/extrato-conta?codigo_conta=4311&data_inicio=2026-03-01&data_fim=2026-03-31', $s)->assertOk()
            ->assertJsonCount(2, 'dados.movimentos')->assertJsonPath('dados.debito', '1000.00')->assertJsonPath('dados.credito', '250.25');
        $m = $r->json('dados.movimentos');
        $this->assertSame(['3211', '6211'], $m[0]['contrapartidas']);
        $this->assertSame('REC-20260315-0001', $m[0]['reconciliacao_codigo']);
        $this->assertSame('2026-03-15', $m[0]['reconciliacao_data']);
        $this->assertSame(['752'], $m[1]['contrapartidas']);
        $this->assertNull($m[1]['reconciliacao_codigo']);
        $this->assertNull($m[1]['reconciliacao_data']);

        // reconciliação anulada: o código fica na linha (dados do legado), mas sem data de registo válido
        $this->em($this->a, fn () => ReconciliacaoBancaria::query()->update(['estado' => 'ANULADA']));
        $this->getJson('/api/tesouraria/extrato-conta?codigo_conta=4311&data_inicio=2026-03-01&data_fim=2026-03-31', $s)
            ->assertJsonPath('dados.movimentos.0.reconciliacao_data', null);

        // empresa B: só vê o seu lançamento, com a sua contrapartida e a sua data de reconciliação
        $sb = $this->sessao(['teso_gestao_mapas_view'], $this->b);
        $this->getJson('/api/tesouraria/extrato-conta?codigo_conta=4311&data_inicio=2026-03-01&data_fim=2026-03-31', $sb)->assertOk()
            ->assertJsonCount(1, 'dados.movimentos')->assertJsonPath('dados.movimentos.0.contrapartidas', ['6211'])
            ->assertJsonPath('dados.movimentos.0.reconciliacao_data', '2020-01-01');

        $this->getJson('/api/tesouraria/extrato-conta?codigo_conta=4311&data_inicio=2026-03-01&data_fim=2026-03-31', $this->sessao(['teso_folha_caixa_view']))->assertForbidden();
    }

    #[Test]
    public function sessoes_de_caixa_trazem_descricao_e_totais(): void
    {
        $r = $this->getJson('/api/tesouraria/caixa/sessoes', $this->sessao(['teso_folha_caixa_view']))->assertOk()->assertJsonCount(2, 'dados');
        $porId = collect($r->json('dados'))->keyBy('id');
        $this->assertSame('Caixa Central', $porId[$this->ids['sessao']]['descricao_conta']);
        $this->assertSame('150.15', $porId[$this->ids['sessao']]['total_entradas']);
        $this->assertSame('30.00', $porId[$this->ids['sessao']]['total_saidas']);
        $this->assertSame('0.00', $porId[$this->ids['sessao_vazia']]['total_entradas']);
        $this->assertSame('0.00', $porId[$this->ids['sessao_vazia']]['total_saidas']);

        $this->getJson('/api/tesouraria/caixa/sessoes', $this->sessao(['teso_folha_caixa_view'], $this->b))->assertOk()->assertJsonCount(1, 'dados')
            ->assertJsonPath('dados.0.descricao_conta', 'Caixa B')->assertJsonPath('dados.0.total_entradas', '777.00')->assertJsonPath('dados.0.total_saidas', '0.00');
        $this->getJson('/api/tesouraria/caixa/sessoes', $this->sessao(['teso_gestao_mapas_view']))->assertForbidden();
    }

    #[Test]
    public function mapa_de_reconciliacao_traz_o_quadro_resumo(): void
    {
        $s = $this->sessao(['teso_gestao_conciliacao_view']);
        $this->getJson('/api/tesouraria/reconciliacao/mapa?codigo_conta=4311&data=2026-03-31', $s)->assertOk()
            ->assertJsonPath('dados.diario_debito', '1000.00')->assertJsonPath('dados.diario_credito', '250.25')
            ->assertJsonPath('dados.saldo_diario', '749.75')
            // extracto: a anulada (10.10) e a posterior à data (5 000) não contam
            ->assertJsonPath('dados.extrato_debito', '250.25')->assertJsonPath('dados.extrato_credito', '1000.00')
            ->assertJsonPath('dados.saldo_extrato', '749.75');
        $this->getJson('/api/tesouraria/reconciliacao/mapa?codigo_conta=4311&data=2026-04-30', $s)->assertOk()
            ->assertJsonPath('dados.diario_debito', '1099.99')->assertJsonPath('dados.saldo_diario', '849.74')
            ->assertJsonPath('dados.extrato_credito', '6000.00')->assertJsonPath('dados.saldo_extrato', '5749.75');

        $this->getJson('/api/tesouraria/reconciliacao/mapa?codigo_conta=4311&data=2026-03-31', $this->sessao(['teso_gestao_conciliacao_view'], $this->b))->assertOk()
            ->assertJsonPath('dados.diario_debito', '777.00')->assertJsonPath('dados.diario_credito', '0.00')
            ->assertJsonPath('dados.extrato_debito', '0.00')->assertJsonPath('dados.saldo_extrato', '777.00');
        $this->getJson('/api/tesouraria/reconciliacao/mapa?codigo_conta=4311&data=2026-03-31', $this->sessao(['teso_folha_caixa_view']))->assertForbidden();
    }

    #[Test]
    public function ficha_do_orcamento_traz_o_realizado_do_ano_anterior(): void
    {
        $s = $this->sessao(['orc_orcamentos_view']);
        // âmbito UN1: vendas 300.10 (a UN2 fica de fora); serviços 120.40 − 20.00
        $r = $this->getJson("/api/orcamento/orcamentos/{$this->ids['orc_un']}", $s)->assertOk();
        $this->assertSame([(string) $this->ids['R1'] => '300.10', (string) $this->ids['R2'] => '100.40'], $r->json('dados.real_anterior'));
        // sem âmbito: todas as UN
        $r = $this->getJson("/api/orcamento/orcamentos/{$this->ids['orc']}", $s)->assertOk();
        $this->assertSame('350.10', $r->json('dados.real_anterior.'.$this->ids['R1']));
        $this->assertSame('100.40', $r->json('dados.real_anterior.'.$this->ids['R2']));
        $this->assertNull($r->json('dados.real_anterior.'.$this->ids['R3']));
        // sem realizado no ano anterior: objecto vazio (não lista)
        $this->assertStringContainsString('"real_anterior":{}', $this->getJson("/api/orcamento/orcamentos/{$this->ids['orc_2024']}", $s)->assertOk()->getContent());

        // empresa B: o seu orçamento não tem rubricas, logo nada; e não abre o da empresa A
        $sb = $this->sessao(['orc_orcamentos_view'], $this->b);
        $this->assertStringContainsString('"real_anterior":{}', $this->getJson("/api/orcamento/orcamentos/{$this->ids['orc_b']}", $sb)->assertOk()->getContent());
        $this->getJson("/api/orcamento/orcamentos/{$this->ids['orc_un']}", $sb)->assertNotFound();
        $this->getJson("/api/orcamento/orcamentos/{$this->ids['orc_un']}", $this->sessao(['teso_folha_caixa_view']))->assertForbidden();
    }
}
