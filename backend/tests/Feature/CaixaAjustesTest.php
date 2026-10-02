<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\MovimentoCaixa;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Folha de caixa (A-11): notas DEMO/fluxo nos movimentos, classificação em massa e reabertura de sessão fechada. */
final class CaixaAjustesTest extends TestCase
{
    private Empresa $empresa;

    private string $hoje;

    private int $notaDemo;

    private int $notaFluxo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['4511' => 'Caixa', '4512' => 'Caixa 2', '752' => 'Serviços', '611' => 'Vendas'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->notaDemo = NotaDemonstracao::create(['codigo' => '30', 'descricao' => 'Fornecimentos e serviços de terceiros'])->id;
            $this->notaFluxo = NotaFluxoCaixa::create(['codigo' => '112', 'descricao' => 'Pagamentos a fornecedores'])->id;
        });
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function abrir(array $s, string $conta = '4511'): int
    {
        return $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => $conta, 'data' => $this->hoje, 'saldo_abertura' => 1000], $s)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function grava_notas_no_movimento_e_classifica_em_massa(): void
    {
        $s = $this->sessao(['teso_folha_caixa_view', 'teso_caixa_operar']);
        $id = $this->abrir($s);
        $r = $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/movimentos", ['tipo' => 'PAG', 'data_documento' => $this->hoje, 'conta_contrapartida' => '752',
            'valor' => 150, 'descricao' => 'Material de escritório', 'nota_demonstracao_id' => $this->notaDemo, 'nota_fluxo_caixa_id' => $this->notaFluxo], $s)->assertCreated();
        $m1 = $r->json('dados.movimentos.0');
        $this->assertSame($this->notaDemo, $m1['nota_demonstracao_id']);
        $this->assertSame($this->notaFluxo, $m1['nota_fluxo_caixa_id']);

        // nota de outra empresa / inexistente: recusada e o movimento não fica gravado
        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/movimentos", ['tipo' => 'REC', 'data_documento' => $this->hoje, 'conta_contrapartida' => '611',
            'valor' => 50, 'descricao' => 'Venda ao balcão', 'nota_demonstracao_id' => 999999], $s)->assertStatus(422)->assertJsonPath('codigo', 'NOTA_INEXISTENTE');
        $this->getJson("/api/tesouraria/caixa/sessoes/{$id}", $s)->assertOk()->assertJsonCount(1, 'dados.movimentos');

        $m2 = $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/movimentos", ['tipo' => 'REC', 'data_documento' => $this->hoje, 'conta_contrapartida' => '611',
            'valor' => 50, 'descricao' => 'Venda ao balcão'], $s)->assertCreated()->json('dados.movimentos.1.id');

        // só as chaves enviadas mudam: limpar a nota DEMO do 1.º e pôr a de fluxo no 2.º não toca no resto
        $this->putJson("/api/tesouraria/caixa/sessoes/{$id}/movimentos/classificacao", ['movimentos' => [$m1['id'], $m2], 'nota_fluxo_caixa_id' => $this->notaFluxo], $s)
            ->assertOk()->assertJsonPath('mensagem', 'Classificação aplicada a 2 movimento(s).');
        $this->putJson("/api/tesouraria/caixa/sessoes/{$id}/movimentos/classificacao", ['movimentos' => [$m1['id']], 'nota_demonstracao_id' => null], $s)->assertOk();
        $linhas = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => MovimentoCaixa::query()->orderBy('id')->get());
        $this->assertNull($linhas[0]->nota_demonstracao_id);
        $this->assertSame($this->notaFluxo, (int) $linhas[0]->nota_fluxo_caixa_id);
        $this->assertSame($this->notaFluxo, (int) $linhas[1]->nota_fluxo_caixa_id);
        $this->assertSame('150.00', (string) $linhas[0]->valor);

        // movimento de outra sessão e pedido sem campos
        $this->putJson("/api/tesouraria/caixa/sessoes/{$id}/movimentos/classificacao", ['movimentos' => [$m2 + 1000]], $s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_CAMPOS');
        $this->putJson("/api/tesouraria/caixa/sessoes/{$id}/movimentos/classificacao", ['movimentos' => [$m2 + 1000], 'nota_fluxo_caixa_id' => null], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MOVIMENTO_INEXISTENTE');
    }

    #[Test]
    public function reabre_sessao_fechada_com_as_regras_e_a_permissao(): void
    {
        $operador = $this->sessao(['teso_folha_caixa_view', 'teso_caixa_operar']);
        $gestor = $this->sessao(['teso_folha_caixa_view', 'teso_caixa_operar', 'teso_caixa_fechar', 'teso_caixa_contabilizar']);
        $id = $this->abrir($gestor);
        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/movimentos", ['tipo' => 'PAG', 'data_documento' => $this->hoje, 'conta_contrapartida' => '752',
            'valor' => 100, 'descricao' => 'Táxi'], $gestor)->assertCreated();

        // aberta: não se reabre
        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/reabrir", ['motivo' => 'Falta um movimento'], $gestor)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_NAO_FECHADA');
        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/fechar", ['saldo_fisico' => 880, 'data' => $this->hoje], $gestor)->assertOk();

        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/reabrir", ['motivo' => 'Falta um movimento'], $operador)->assertForbidden();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/reabrir", ['motivo' => 'x'], $gestor)->assertStatus(422);
        $r = $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/reabrir", ['motivo' => 'Falta registar um recibo'], $gestor)->assertOk();
        $r->assertJsonPath('dados.estado', 'ABERTA')->assertJsonPath('dados.saldo_fisico', null)->assertJsonPath('dados.data_fecho', null)->assertJsonCount(1, 'dados.movimentos');
        $this->assertDatabaseHas('logs_auditoria', ['acao' => 'REABRIR_SESSAO_CAIXA', 'registo_id' => (string) $id]);

        // volta a fechar, contabiliza: contabilizada não se reabre
        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/fechar", ['saldo_fisico' => 900, 'data' => $this->hoje], $gestor)->assertOk();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/contabilizar", [], $gestor)->assertOk();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$id}/reabrir", ['motivo' => 'Falta registar um recibo'], $gestor)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_CONTABILIZADA');
    }

    #[Test]
    public function nao_reabre_com_outra_sessao_aberta_ou_posterior_na_mesma_caixa(): void
    {
        $s = $this->sessao(['teso_folha_caixa_view', 'teso_caixa_operar', 'teso_caixa_fechar']);
        $a = $this->abrir($s);
        $this->postJson("/api/tesouraria/caixa/sessoes/{$a}/fechar", ['saldo_fisico' => 1000, 'data' => $this->hoje], $s)->assertOk();
        $b = $this->abrir($s);
        $this->postJson("/api/tesouraria/caixa/sessoes/{$a}/reabrir", ['motivo' => 'Correcção da contagem'], $s)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_JA_ABERTA');
        $this->postJson("/api/tesouraria/caixa/sessoes/{$b}/fechar", ['saldo_fisico' => 1000, 'data' => $this->hoje], $s)->assertOk();
        $this->postJson("/api/tesouraria/caixa/sessoes/{$a}/reabrir", ['motivo' => 'Correcção da contagem'], $s)->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_POSTERIOR');
        // a última da caixa reabre; uma sessão de outra caixa não interfere
        $c = $this->abrir($s, '4512');
        $this->postJson("/api/tesouraria/caixa/sessoes/{$b}/reabrir", ['motivo' => 'Correcção da contagem'], $s)->assertOk()->assertJsonPath('dados.estado', 'ABERTA');
        $this->assertNotSame($b, $c);
    }
}
