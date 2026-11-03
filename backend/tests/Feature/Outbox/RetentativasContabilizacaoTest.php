<?php

namespace Tests\Feature\Outbox;

use App\Jobs\Acrescimos\ContabilizarPropostaAcrescimoJob;
use App\Jobs\Sistema\BatimentoWorker;
use App\Jobs\Sistema\SimularRestauroLegado;
use App\Jobs\Vendas\CicloAgt;
use App\Jobs\Vendas\ContabilizarVendaLoteJob;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PeriodoLancamentoAcrescimo;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Acrescimos\ServicoPropostaAcrescimos;
use App\Services\Integracoes\Operacoes\ServicoOperacoes;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Support\Idempotencia\ExecucaoEmCursoException;
use App\Support\Idempotencia\ExecucaoIdempotente;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Retentativas idempotentes dos jobs de contabilização (ADR-080): `tries = 4` só é seguro porque o trabalho corre dentro de
 * ExecucaoIdempotente. O driver `sync` dos testes não repete sozinho, por isso cada «tentativa» é uma chamada a `handle()` do MESMO
 * job (o job serializado que a fila reentregaria).
 */
final class RetentativasContabilizacaoTest extends TestCase
{
    private Empresa $empresa;

    private int $cliente;

    private int $produto;

    private int $diario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000777']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['311', 'Clientes'], ['3452', 'IVA liquidado'], ['611', 'Vendas'], ['3731', 'Proveitos a facturar'], ['3743', 'Encargos a repartir'], ['3754', 'Encargos a pagar'],
                ['3766', 'Proveitos a repartir'], ['752331', 'Seguros'], ['321', 'Fornecedores']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente', 'nif' => '5000000777', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->produto = Produto::create(['codigo' => 'P1', 'nome' => 'Produto 1', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false])->id;
            $this->diario = DiarioContabil::create(['codigo' => 'AC', 'nome' => 'Acréscimos e diferimentos'])->id;
        });
    }

    protected function tearDown(): void
    {
        Event::forget('eloquent.updating: '.Venda::class);
        parent::tearDown();
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function emitirFactura(): int
    {
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fat_contabilizar', 'vendas_fat_descontab']);

        return $this->postJson('/api/vendas/documentos', ['tipo_documento' => 'FT', 'cliente_id' => $this->cliente, 'data_emissao' => now()->toDateString(),
            'linhas' => [['produto_id' => $this->produto, 'quantidade' => 1]]], $s)->assertCreated()->json('dados.id');
    }

    private function jobVenda(int $id, bool $contabilizar = true, ?string $motivo = null, string $tipo = 'documentos'): ContabilizarVendaLoteJob
    {
        return new ContabilizarVendaLoteJob($this->empresa->id, $tipo, $contabilizar, $id, $motivo);
    }

    private function correr(object $job): void
    {
        $job->handle(...array_map(fn (string $c) => app($c), match (true) {
            $job instanceof ContabilizarVendaLoteJob => [ContextoEmpresa::class, ServicoContabilizacaoVendas::class, ServicoOperacoes::class, ExecucaoIdempotente::class],
            default => [ContextoEmpresa::class, ServicoPropostaAcrescimos::class, ServicoOperacoes::class, ExecucaoIdempotente::class],
        }));
    }

    /** @return array<string, mixed> */
    private function execucao(string $chave): array
    {
        $linha = DB::table('execucoes_idempotentes')->where('empresa_id', $this->empresa->id)->where('chave', $chave)->first();
        $this->assertNotNull($linha, "sem execução idempotente {$chave}");

        return ['estado' => $linha->estado, 'tentativas' => (int) $linha->tentativas, 'resultado' => $linha->resultado ? json_decode($linha->resultado, true) : null];
    }

    private function lancamentos(): int
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->count());
    }

    private function venda(int $id): Venda
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Venda::query()->findOrFail($id));
    }

    #[Test]
    public function job_repetido_depois_de_sucesso_nao_volta_a_contabilizar_e_devolve_o_mesmo_resultado(): void
    {
        $ft = $this->emitirFactura();
        $job = $this->jobVenda($ft);
        $this->assertSame(4, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);

        $this->correr($job);
        $depois = $this->lancamentos();
        $primeiro = $this->execucao($job->chaveExecucao());
        $this->assertSame('CONCLUIDO', $primeiro['estado']);
        $this->assertTrue($primeiro['resultado']['sucesso']);
        $this->assertGreaterThan(0, $depois);

        $this->correr($job);   // entrega duplicada / reentrega depois do sucesso
        $this->assertSame($depois, $this->lancamentos());
        $this->assertSame($primeiro, $this->execucao($job->chaveExecucao()));   // mesmo resultado, nem sequer contou nova tentativa
        $this->assertTrue($this->venda($ft)->contabilizado);
    }

    #[Test]
    public function excepcao_tecnica_na_primeira_tentativa_e_sucesso_na_segunda_dao_um_so_lancamento(): void
    {
        $ft = $this->emitirFactura();
        $antes = $this->lancamentos();
        $job = $this->jobVenda($ft);

        // falha técnica DENTRO da transacção do serviço (desfeita por inteiro): nada fica gravado
        Event::listen('eloquent.updating: '.Venda::class, fn () => throw new RuntimeException('ligação à base de dados perdida'));
        try {
            $this->correr($job);
            $this->fail('a excepção técnica devia propagar-se para a fila repetir');
        } catch (RuntimeException $e) {
            $this->assertSame('ligação à base de dados perdida', $e->getMessage());
        }
        $this->assertSame($antes, $this->lancamentos());
        $linha = DB::table('execucoes_idempotentes')->where('chave', $job->chaveExecucao())->first();
        $this->assertSame(['EM_CURSO', null], [$linha->estado, $linha->reservado_ate]);   // reserva libertada: a repetição retoma já

        Event::forget('eloquent.updating: '.Venda::class);
        $this->correr($job);
        $this->assertGreaterThan($antes, $this->lancamentos(), 'o lançamento foi criado');
        $e = $this->execucao($job->chaveExecucao());
        $this->assertSame(['CONCLUIDO', 2, true], [$e['estado'], $e['tentativas'], $e['resultado']['sucesso']]);
        $this->assertTrue($this->venda($ft)->contabilizado);
        $criados = $this->lancamentos() - $antes;

        $this->correr($job);   // e uma terceira entrega não cria mais nada
        $this->assertSame($antes + $criados, $this->lancamentos());
    }

    #[Test]
    public function crash_depois_do_commit_do_servico_e_antes_de_gravar_o_resultado_a_repeticao_converte_ja_contabilizado_em_sucesso(): void
    {
        $ft = $this->emitirFactura();
        $job = $this->jobVenda($ft);

        // o serviço fez COMMIT, o worker morreu antes de gravar o resultado: execução EM_CURSO com o lease já expirado
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoContabilizacaoVendas::class)->contabilizar(Venda::query()->findOrFail($ft)));
        DB::table('execucoes_idempotentes')->insert(['empresa_id' => $this->empresa->id, 'chave' => $job->chaveExecucao(), 'estado' => 'EM_CURSO', 'tentativas' => 1,
            'reservado_ate' => now()->subMinute(), 'criado_em' => now()->subMinutes(10)]);
        $lancamentos = $this->lancamentos();

        $this->correr($job);   // a retoma bate em JA_CONTABILIZADO com o documento já contabilizado

        $e = $this->execucao($job->chaveExecucao());
        $this->assertSame(['CONCLUIDO', 2, true], [$e['estado'], $e['tentativas'], $e['resultado']['sucesso']]);
        $this->assertStringContainsString('retentativa', $e['resultado']['mensagem']);
        $this->assertSame($lancamentos, $this->lancamentos());
    }

    #[Test]
    public function lease_concorrente_o_segundo_worker_faz_release(): void
    {
        $ft = $this->emitirFactura();
        $job = $this->jobVenda($ft);
        $job->withFakeQueueInteractions();
        DB::table('execucoes_idempotentes')->insert(['empresa_id' => $this->empresa->id, 'chave' => $job->chaveExecucao(), 'estado' => 'EM_CURSO', 'tentativas' => 1,
            'reservado_ate' => now()->addMinutes(5), 'criado_em' => now()]);
        $antes = $this->lancamentos();

        $this->correr($job);

        $job->assertReleased(15);
        $this->assertSame($antes, $this->lancamentos());
        $this->assertFalse($this->venda($ft)->contabilizado);
        $this->assertSame('EM_CURSO', $this->execucao($job->chaveExecucao())['estado']);

        // o utilitário também o diz directamente
        $this->expectException(ExecucaoEmCursoException::class);
        app(ExecucaoIdempotente::class)->executar($this->empresa->id, $job->chaveExecucao(), fn () => ['x' => 1]);
    }

    #[Test]
    public function erro_de_negocio_genuino_continua_a_ser_falha_de_negocio_no_resultado(): void
    {
        $ft = $this->emitirFactura();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoContabilizacaoVendas::class)->contabilizar(Venda::query()->findOrFail($ft)));
        $lancamentos = $this->lancamentos();

        $job = $this->jobVenda($ft);   // primeira tentativa de um pedido NOVO sobre um documento já contabilizado
        $this->correr($job);

        $e = $this->execucao($job->chaveExecucao());
        $this->assertSame(['CONCLUIDO', 1, false, 'JA_CONTABILIZADO'], [$e['estado'], $e['tentativas'], $e['resultado']['sucesso'], $e['resultado']['codigo']]);
        $this->assertSame($lancamentos, $this->lancamentos());

        // pelo lote, o ecrã de progresso vê o mesmo (falha de negócio com detalhe, lote concluído sem «falhados»)
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_contabilizar']);
        $op = $this->postJson('/api/vendas/documentos/contabilizar', ['ids' => [$ft]], $s)->assertOk()->assertJsonPath('dados.falhados', 0)->assertJsonPath('dados.estado', 'CONCLUIDA')->json('dados.id');
        $r = $this->getJson("/api/sistema/operacoes/{$op}/resultados", $s)->assertOk()->json('dados');
        $this->assertSame([false, 'JA_CONTABILIZADO'], [$r[0]['sucesso'], $r[0]['codigo']]);
    }

    #[Test]
    public function operacoes_distintas_sobre_o_mesmo_documento_nao_colidem(): void
    {
        $ft = $this->emitirFactura();
        $contab1 = $this->jobVenda($ft);
        $descontab = $this->jobVenda($ft, false, 'Correcção');
        $contab2 = $this->jobVenda($ft);   // uma NOVA operação do utilizador sobre o mesmo documento

        $chaves = [$contab1->chaveExecucao(), $descontab->chaveExecucao(), $contab2->chaveExecucao(), $this->jobVenda($ft, true, null, 'recibos')->chaveExecucao()];
        $this->assertCount(4, array_unique($chaves));
        $this->assertStringStartsWith("contab:documentos:contabilizar:{$ft}:", $chaves[0]);
        $this->assertStringStartsWith("contab:documentos:descontabilizar:{$ft}:", $chaves[1]);
        $this->assertStringStartsWith("contab:recibos:contabilizar:{$ft}:", $chaves[3]);

        $this->correr($contab1);
        $this->assertTrue($this->venda($ft)->contabilizado);
        $this->correr($descontab);
        $this->assertFalse($this->venda($ft)->contabilizado);
        $this->correr($contab2);   // não devolve o resultado guardado de contab1: contabiliza outra vez
        $this->assertTrue($this->venda($ft)->contabilizado);
        $this->assertSame(3, DB::table('execucoes_idempotentes')->where('chave', 'like', "contab:documentos:%:{$ft}:%")->where('estado', 'CONCLUIDO')->count());
        $this->assertTrue($this->execucao($contab2->chaveExecucao())['resultado']['sucesso']);
    }

    #[Test]
    public function failed_regista_o_resultado_de_falha_no_lote_para_o_ecra_de_progresso(): void
    {
        $ft = $this->emitirFactura();
        $s = $this->sessao(['vendas_faturacao_view', 'vendas_fat_contabilizar']);
        $op = $this->postJson('/api/vendas/documentos/contabilizar', ['ids' => [$ft]], $s)->assertOk()->json('dados.id');

        $job = $this->jobVenda($ft);
        $job->withBatchId($op);
        $job->failed(new RuntimeException('esgotadas as tentativas'));

        $r = app(ServicoOperacoes::class)->resultados($op);
        $falha = collect($r)->firstWhere('codigo', 'ERRO_INTERNO');
        $this->assertNotNull($falha);
        $this->assertFalse($falha['sucesso']);
        $this->assertStringContainsString('4 tentativas', $falha['mensagem']);
    }

    #[Test]
    public function os_trabalhos_sem_retentativa_segura_continuam_com_tries_1(): void
    {
        foreach ([BatimentoWorker::class, SimularRestauroLegado::class, CicloAgt::class] as $classe) {
            $this->assertSame(1, (new \ReflectionClass($classe))->getProperty('tries')->getDefaultValue(), $classe);
        }
    }

    // ----- Acréscimos e diferimentos -----

    private function sessaoAcrescimos(): array
    {
        return $this->sessao(['ad_registos_view', 'ad_propostas_view', 'ad_recolher_view', 'ad_editar', 'ad_contabilizar', 'ad_definicoes_edit']);
    }

    /** @return array{0: string, 1: string} mês e chave da linha INICIAL de um diferimento */
    private function propostaAcrescimo(): array
    {
        $s = $this->sessaoAcrescimos();
        $this->putJson('/api/acrescimos/definicoes', ['contas' => ['ACRESCIMO_CUSTO' => '3754', 'ACRESCIMO_PROVEITO' => '3731', 'DIFERIMENTO_CUSTO' => '3743', 'DIFERIMENTO_PROVEITO' => '3766'],
            'diario_id' => $this->diario, 'prazo_documento_dias' => 60], $s)->assertOk();
        $id = $this->postJson('/api/acrescimos/itens', ['tipo' => 'DIFERIMENTO', 'natureza' => 'CUSTO', 'descricao' => 'Seguro', 'valor' => 1200, 'conta_resultado' => '752331',
            'reparticao' => 'MESES', 'conta_balanco' => '3743', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-04-30', 'data_documento' => '2026-01-10'], $s)->assertCreated()->json('dados.item.id');

        return ['2026-01', "{$id}|INICIAL|2026-01"];
    }

    #[Test]
    public function acrescimos_repeticao_depois_de_sucesso_falha_tecnica_e_crash_dao_um_so_lancamento(): void
    {
        [$mes, $chave] = $this->propostaAcrescimo();
        $contar = fn () => app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('tipo_origem', 'ACRESCIMOS')->count());
        $this->assertSame(0, $contar());

        // (a) falha técnica na 1.ª tentativa (o serviço devolve-a como excepção do job, não como erro de negócio) e sucesso na 2.ª
        $job = new ContabilizarPropostaAcrescimoJob($this->empresa->id, $mes, $chave);
        $this->assertSame([4, [10, 60, 300]], [$job->tries, $job->backoff]);
        Event::listen('eloquent.creating: '.PeriodoLancamentoAcrescimo::class, fn () => throw new RuntimeException('ligação à base de dados perdida'));
        try {
            $this->correr($job);
            $this->fail('a falha técnica devia propagar-se para a fila repetir');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('erro inesperado', $e->getMessage());   // o serviço devolve a falha técnica como código «ERRO»; o job repete-a
        }
        $this->assertSame(0, $contar());   // a transacção da linha foi desfeita
        $this->assertSame('EM_CURSO', $this->execucao($job->chaveExecucao())['estado']);

        Event::forget('eloquent.creating: '.PeriodoLancamentoAcrescimo::class);
        $this->correr($job);
        $linhas = $contar();
        $this->assertGreaterThan(0, $linhas);
        $e = $this->execucao($job->chaveExecucao());
        $this->assertSame(['CONCLUIDO', 2, true], [$e['estado'], $e['tentativas'], $e['resultado']['sucesso']]);

        // (b) reentrega depois do sucesso: nada de novo, mesmo resultado
        $this->correr($job);
        $this->assertSame($linhas, $contar());
        $this->assertSame($e['resultado'], $this->execucao($job->chaveExecucao())['resultado']);
    }

    #[Test]
    public function acrescimos_crash_depois_do_commit_e_erro_de_negocio_genuino(): void
    {
        [$mes, $chave] = $this->propostaAcrescimo();
        $contar = fn () => app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('tipo_origem', 'ACRESCIMOS')->count());

        // crash: o serviço fez COMMIT e o worker morreu antes de gravar o resultado
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoPropostaAcrescimos::class)->contabilizar($mes, [$chave]));
        $linhas = $contar();
        $this->assertGreaterThan(0, $linhas);
        $retoma = new ContabilizarPropostaAcrescimoJob($this->empresa->id, $mes, $chave);
        DB::table('execucoes_idempotentes')->insert(['empresa_id' => $this->empresa->id, 'chave' => $retoma->chaveExecucao(), 'estado' => 'EM_CURSO', 'tentativas' => 1,
            'reservado_ate' => now()->subMinute(), 'criado_em' => now()->subMinutes(10)]);
        $this->correr($retoma);
        $e = $this->execucao($retoma->chaveExecucao());
        $this->assertSame(['CONCLUIDO', 2, true], [$e['estado'], $e['tentativas'], $e['resultado']['sucesso']]);
        $this->assertStringContainsString('retentativa', $e['resultado']['mensagem']);
        $this->assertSame($linhas, $contar());

        // erro de negócio genuíno: um pedido NOVO (1.ª tentativa) sobre uma linha já contabilizada é uma falha de negócio
        $novo = new ContabilizarPropostaAcrescimoJob($this->empresa->id, $mes, $chave);
        $this->correr($novo);
        $e = $this->execucao($novo->chaveExecucao());
        $this->assertSame(['CONCLUIDO', 1, false], [$e['estado'], $e['tentativas'], $e['resultado']['sucesso']]);
        $this->assertContains($e['resultado']['codigo'], ['SEM_LINHAS', 'JA_CONTABILIZADO']);
        $this->assertNotSame($retoma->chaveExecucao(), $novo->chaveExecucao());
        $this->assertSame($linhas, $contar());
    }

    #[Test]
    public function limpeza_das_execucoes_idempotentes_so_apaga_concluidas_antigas(): void
    {
        $linha = fn (string $chave, string $estado, ?string $concluidoEm) => DB::table('execucoes_idempotentes')->insert([
            'empresa_id' => $this->empresa->id, 'chave' => $chave, 'estado' => $estado, 'concluido_em' => $concluidoEm, 'criado_em' => now()->subDays(60)]);
        $linha('concluida-antiga', 'CONCLUIDO', now()->subDays(40)->toDateTimeString());
        $linha('concluida-recente', 'CONCLUIDO', now()->subDays(3)->toDateTimeString());
        $linha('em-curso-antiga', 'EM_CURSO', null);

        $this->assertSame(0, Artisan::call('erp:idempotencia:limpar', ['--dias' => 30]));
        $this->assertEqualsCanonicalizing(['concluida-recente', 'em-curso-antiga'], DB::table('execucoes_idempotentes')->pluck('chave')->all());
    }
}
