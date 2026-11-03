<?php

namespace Tests\Feature\Outbox;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class TrabalhoDeTeste implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void {}
}

/**
 * `after_commit` na fila (ADR-080): um trabalho despachado dentro de uma transacção só entra na fila depois do COMMIT.
 *
 * Como se verifica: o projecto não tem a tabela `jobs` (a fila de produção é Redis e os testes usam `sync`, que não distingue),
 * por isso cria-se uma `jobs` temporária (o DDL do PostgreSQL é transaccional: desaparece no rollback do próprio teste) e usa-se o
 * driver `database`, que partilha a ligação da transacção. Dentro da transacção a tabela tem de estar vazia (com after_commit
 * desligado o INSERT já lá estaria); depois do COMMIT tem uma linha; um ROLLBACK não chega a levantar o evento JobQueued.
 */
final class FilaDepoisDoCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        // a ligação `database` só é resolvida (e a opção lida) na primeira utilização, por isso configura-se antes de despachar
        config(['queue.connections.database.table' => 'jobs']);
    }

    #[Test]
    public function after_commit_esta_ligado_por_omissao_nas_ligacoes_da_fila(): void
    {
        $this->assertNull(env('QUEUE_AFTER_COMMIT'), 'o ambiente de testes não deve sobrepor QUEUE_AFTER_COMMIT');
        foreach (['redis', 'database'] as $ligacao) {
            $this->assertTrue(config("queue.connections.{$ligacao}.after_commit"), "queue.connections.{$ligacao}.after_commit");
        }
        // e o valor por omissão do ficheiro (sem variável de ambiente) é verdadeiro
        $definicao = require config_path('queue.php');
        $this->assertTrue($definicao['connections']['redis']['after_commit']);
    }

    #[Test]
    public function um_despacho_dentro_da_transaccao_so_entra_na_fila_depois_do_commit(): void
    {
        DB::transaction(function () {
            TrabalhoDeTeste::dispatch()->onConnection('database');
            $this->assertSame(0, DB::table('jobs')->count(), 'dentro da transacção o trabalho ainda não está na fila');
        });

        $this->assertSame(1, DB::table('jobs')->count());
    }

    #[Test]
    public function um_despacho_dentro_de_uma_transaccao_com_rollback_nao_fica_na_fila(): void
    {
        Event::fake([JobQueued::class]);
        try {
            DB::transaction(function () {
                TrabalhoDeTeste::dispatch()->onConnection('database');
                throw new RuntimeException('a escrita de negócio falhou');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('jobs')->count());
        Event::assertNotDispatched(JobQueued::class);   // nem chegou a ser enfileirado
    }

    #[Test]
    public function fora_de_transaccoes_o_despacho_continua_a_ser_imediato(): void
    {
        TrabalhoDeTeste::dispatch()->onConnection('database');
        $this->assertSame(1, DB::table('jobs')->count());
    }

    #[Test]
    public function com_after_commit_desligado_o_trabalho_entra_logo(): void
    {
        config(['queue.connections.database.after_commit' => false]);
        DB::transaction(function () {
            TrabalhoDeTeste::dispatch()->onConnection('database');
            $this->assertSame(1, DB::table('jobs')->count());
        });
    }
}
