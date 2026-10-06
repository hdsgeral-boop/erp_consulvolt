<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\LogAuditoria;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A-05 (classificação dos lançamentos: notas em massa e ficha) e M-09 (transferência para outra empresa). */
final class ContabilidadeClassificacaoTransferenciaTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private DiarioContabil $diario;

    private array $cab;

    private int $nota;

    private int $cc;

    private int $terceiro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
        foreach ([$this->empresa, $this->outra] as $e) {
            app(ContextoEmpresa::class)->executarComo($e->id, function () use ($e) {
                foreach ([['111', 'Caixa'], ['211', 'Clientes'], ['611', 'Vendas']] as [$c, $d]) {
                    PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
                }
                $diario = DiarioContabil::create(['codigo' => 'VD', 'descricao' => 'VENDAS']);
                $nota = NotaDemonstracao::create(['codigo' => '22', 'descricao' => 'Vendas']);
                $cc = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Sede']);
                $t = Terceiro::create(['nif' => '5000000001', 'nome' => 'Cliente Fictício', 'tipo' => 'CLIENTE']);
                if ($e->is($this->empresa)) {
                    [$this->diario, $this->nota, $this->cc, $this->terceiro] = [$diario, $nota->id, $cc->id, $t->id];
                }
            });
        }
        $this->cab = $this->sessao(['lancamentos_view', 'lancamentos_post', 'lancamentos_bulk_notes', 'lancamentos_editar', 'contab_lanc_transferir']);
    }

    private function sessao(array $permissoes, array $empresas = []): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresas ?: [$this->empresa->id, $this->outra->id]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    /** @return array{0: int, 1: int} ids das linhas D e C */
    private function lancar(string $valor = '1000.00', string $data = '2026-03-15', array $extraD = []): array
    {
        $r = $this->postJson('/api/contabilidade/lancamentos', ['diario_id' => $this->diario->id, 'data_documento' => $data, 'descricao' => 'Venda',
            'linhas' => [['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => $valor] + $extraD, ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => $valor]]], $this->cab)
            ->assertCreated();

        return [$r->json('dados.linhas.0.id'), $r->json('dados.linhas.1.id')];
    }

    private function linha(int $id): object
    {
        return DB::table('lancamentos_contabeis')->where('id', $id)->first();
    }

    #[Test]
    public function aplica_notas_cc_e_un_as_linhas_seleccionadas_sem_tocar_nos_valores(): void
    {
        [$d, $c] = $this->lancar();
        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$c], 'campos' => ['nota_demonstracao_id' => $this->nota, 'centro_custo_id' => $this->cc,
            'codigo_conta' => '211', 'valor' => 1]], $this->cab)
            ->assertOk()->assertJsonPath('dados.actualizadas', 1);

        $l = $this->linha($c);
        $this->assertSame($this->nota, (int) $l->nota_demonstracao_id);
        $this->assertSame($this->cc, (int) $l->centro_custo_id);
        $this->assertSame('611', $l->codigo_conta);   // campos financeiros ignorados
        $this->assertSame('1000.00', $l->valor);
        $this->assertNull($this->linha($d)->nota_demonstracao_id);

        // null remove (o «[REMOVER NOTA]» do legado)
        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$c], 'campos' => ['nota_demonstracao_id' => null]], $this->cab)->assertOk();
        $this->assertNull($this->linha($c)->nota_demonstracao_id);
        $this->assertTrue(app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('acao', 'Editou a classificação do lançamento')->exists()));
    }

    #[Test]
    public function filtra_linhas_sem_nota_e_classifica_todas_as_filtradas(): void
    {
        $this->lancar();
        $this->lancar('20.00');
        $this->getJson('/api/contabilidade/lancamentos?sem[]=demo&codigo_conta=6', $this->cab)->assertOk()->assertJsonCount(2, 'dados');

        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['filtros' => ['codigo_conta' => '6', 'sem' => ['demo']], 'campos' => ['nota_demonstracao_id' => $this->nota]], $this->cab)
            ->assertOk()->assertJsonPath('dados.actualizadas', 2);
        $this->getJson('/api/contabilidade/lancamentos?sem[]=demo&codigo_conta=6', $this->cab)->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson('/api/contabilidade/lancamentos?filtro_contas=11-61', $this->cab)->assertOk()->assertJsonCount(4, 'dados');
    }

    #[Test]
    public function propaga_ao_estorno_e_recusa_exercicio_encerrado(): void
    {
        [, $c] = $this->lancar();
        $this->postJson("/api/contabilidade/lancamentos/{$c}/estornar", ['motivo' => 'Erro de teste'], $this->cab)->assertCreated();
        $estorno = (int) $this->linha($c)->estornado_por_id;
        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$c], 'campos' => ['nota_demonstracao_id' => $this->nota]], $this->cab)
            ->assertOk()->assertJsonPath('dados.propagadas', 1);
        $this->assertSame($this->nota, (int) $this->linha($estorno)->nota_demonstracao_id);

        [, $c2] = $this->lancar('5.00', '2025-06-01');
        DB::table('configuracoes_sistema')->insert(['chave' => "closed_year_{$this->empresa->id}_2025", 'valor' => 'true']);
        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$c2], 'campos' => ['centro_custo_id' => $this->cc]], $this->cab)
            ->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_ENCERRADO');
    }

    #[Test]
    public function exige_as_permissoes_e_recusa_terceiro_em_linha_compensada(): void
    {
        [$d] = $this->lancar();
        $so = $this->sessao(['lancamentos_view', 'lancamentos_bulk_notes']);
        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$d], 'campos' => ['centro_custo_id' => $this->cc]], $so)->assertOk();
        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$d], 'campos' => ['descricao' => 'Outra']], $so)->assertForbidden();
        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$d], 'campos' => ['descricao' => 'Outra']], $this->sessao(['lancamentos_view']))->assertForbidden();

        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$d], 'campos' => ['descricao' => 'Descrição corrigida', 'terceiro_id' => $this->terceiro]], $this->cab)->assertOk();
        $this->assertSame('Descrição corrigida', $this->linha($d)->descricao);

        DB::table('lancamentos_contabeis')->where('id', $d)->update(['reconciliacao_codigo' => 'AUTO_1']);
        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$d], 'campos' => ['terceiro_id' => null]], $this->cab)
            ->assertStatus(422)->assertJsonPath('codigo', 'LINHA_COMPENSADA');
        // referência de outra empresa recusada pela validação
        $ccOutra = app(ContextoEmpresa::class)->executarComo($this->outra->id, fn () => CentroCusto::query()->value('id'));
        $this->postJson('/api/contabilidade/lancamentos/classificacao', ['ids' => [$d], 'campos' => ['centro_custo_id' => $ccOutra]], $this->cab)->assertStatus(422);
    }

    #[Test]
    public function transfere_para_outra_empresa_com_estorno_na_origem(): void
    {
        [$d] = $this->lancar('750.00', '2026-03-15', ['terceiro_id' => null]);
        DB::table('lancamentos_contabeis')->where('id', $d)->update(['terceiro_id' => $this->terceiro, 'centro_custo_id' => $this->cc]);

        $r = $this->postJson("/api/contabilidade/lancamentos/{$d}/transferir", ['empresa_destino_id' => $this->outra->id, 'motivo' => 'Lançado na empresa errada'], $this->cab)
            ->assertCreated()->assertJsonPath('dados.destino.numero_lan', 'VD2026000001');
        $this->assertNotNull($this->linha($d)->estornado_por_id);
        $this->assertStringContainsString('VD2026000001', $this->linha((int) $this->linha($d)->estornado_por_id)->descricao);

        $destino = DB::table('lancamentos_contabeis')->where('empresa_id', $this->outra->id)->orderBy('id')->get();
        $this->assertCount(2, $destino);
        $this->assertSame('TRANSFERENCIA', $destino[0]->tipo_origem);
        $this->assertSame($d, (int) $destino[0]->linha_origem_id);
        $this->assertSame('750.00', $destino[0]->valor);
        $tDestino = app(ContextoEmpresa::class)->executarComo($this->outra->id, fn () => Terceiro::query()->value('id'));
        $ccDestino = app(ContextoEmpresa::class)->executarComo($this->outra->id, fn () => CentroCusto::query()->value('id'));
        $this->assertSame($tDestino, (int) $destino[0]->terceiro_id);   // mapeado pelo NIF
        $this->assertSame($ccDestino, (int) $destino[0]->centro_custo_id);   // mapeado pelo código
        $this->assertSame([], $r->json('dados.avisos'));

        // já estornado: não se transfere outra vez
        $this->postJson("/api/contabilidade/lancamentos/{$d}/transferir", ['empresa_destino_id' => $this->outra->id, 'motivo' => 'Outra vez'], $this->cab)
            ->assertStatus(422)->assertJsonPath('codigo', 'JA_ESTORNADO');
    }

    #[Test]
    public function recusa_transferir_sem_acesso_ao_destino_ou_sem_diario_no_destino(): void
    {
        [$d] = $this->lancar();
        $semDestino = $this->sessao(['lancamentos_view', 'contab_lanc_transferir'], [$this->empresa->id]);
        $this->postJson("/api/contabilidade/lancamentos/{$d}/transferir", ['empresa_destino_id' => $this->outra->id, 'motivo' => 'Sem acesso'], $semDestino)
            ->assertStatus(403)->assertJsonPath('codigo', 'EMPRESA_SEM_ACESSO');
        $this->postJson("/api/contabilidade/lancamentos/{$d}/transferir", ['empresa_destino_id' => $this->empresa->id, 'motivo' => 'Mesma empresa'], $this->cab)
            ->assertStatus(422)->assertJsonPath('codigo', 'TRANSFERENCIA_MESMA_EMPRESA');

        app(ContextoEmpresa::class)->executarComo($this->outra->id, fn () => DiarioContabil::query()->update(['codigo' => 'XX']));
        $this->postJson("/api/contabilidade/lancamentos/{$d}/transferir", ['empresa_destino_id' => $this->outra->id, 'motivo' => 'Sem diário'], $this->cab)
            ->assertStatus(422)->assertJsonPath('codigo', 'DIARIO_EM_FALTA_DESTINO');
        $this->assertNull($this->linha($d)->estornado_por_id);   // nada ficou gravado
        $this->assertSame(0, DB::table('lancamentos_contabeis')->where('empresa_id', $this->outra->id)->count());
        $this->assertSame(2, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->count()));
    }
}
