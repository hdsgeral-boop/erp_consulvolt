<?php

namespace Tests\Feature;

use App\Jobs\Sistema\BatimentoWorker;
use App\Support\Cache\Batimentos;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** GET /api/saude: usado pelos healthchecks e pela monitorização de produção (docs/PRODUCAO.md). */
final class SaudeTest extends TestCase
{
    #[Test]
    public function devolve_todos_os_componentes_e_a_versao(): void
    {
        $this->getJson('/api/saude')
            ->assertOk()
            ->assertJsonPath('dados.estado', 'OK')
            ->assertJsonPath('dados.componentes.base_dados.estado', 'OK')
            ->assertJsonPath('dados.componentes.redis.estado', 'OK')
            ->assertJsonPath('dados.componentes.filas.estado', 'OK')
            ->assertJsonPath('dados.componentes.armazenamento.estado', 'OK')
            ->assertJsonStructure(['dados' => ['versao', 'componentes' => ['filas' => ['estado', 'detalhe', 'latencia_ms']]]]);
    }

    #[Test]
    public function o_detalhe_das_filas_inclui_cada_fila_e_os_falhados(): void
    {
        $detalhe = $this->getJson('/api/saude')->json('dados.componentes.filas.detalhe');

        foreach (['alta=', 'agt=', 'default=', 'pdfs=', 'baixa=', 'falhados='] as $parte) {
            $this->assertStringContainsString($parte, $detalhe);
        }
    }

    #[Test]
    public function responde_503_sem_revelar_o_erro_quando_uma_dependencia_falha(): void
    {
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('ligação recusada: segredo-interno'));

        $resposta = $this->getJson('/api/saude')
            ->assertStatus(503)
            ->assertJsonPath('sucesso', false)
            ->assertJsonPath('codigo', 'SERVICO_INDISPONIVEL')
            ->assertJsonPath('erros.redis.estado', 'FALHA')
            ->assertJsonPath('erros.base_dados.estado', 'OK');

        // APP_DEBUG=false nos testes (phpunit.xml): a mensagem interna nunca sai na resposta pública.
        $this->assertStringNotContainsString('segredo-interno', $resposta->getContent());
    }

    #[Test]
    public function sem_throttle_responde_503_com_detalhe_quando_o_redis_falha(): void
    {
        // o limitador da API usa o Redis: em baixo, as outras rotas dão 500; a saúde não passa pelo limitador (R5)
        $this->app->instance(RateLimiter::class, new class(app('cache')->store()) extends RateLimiter
        {
            public function tooManyAttempts($key, $maxAttempts)
            {
                throw new RuntimeException('Redis em baixo');
            }

            public function attempts($key)
            {
                throw new RuntimeException('Redis em baixo');
            }
        });
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('ligação recusada'));

        $this->getJson('/api/sistema/logotipo-login')->assertStatus(500);
        $this->getJson('/api/saude')->assertStatus(503)->assertJsonPath('erros.redis.estado', 'FALHA')->assertJsonPath('erros.base_dados.estado', 'OK');
    }

    #[Test]
    public function batimentos_do_scheduler_e_do_worker(): void
    {
        // sem registo (ambiente sem scheduler): informativo
        $this->getJson('/api/saude')->assertOk()->assertJsonPath('dados.componentes.processos.estado', 'OK')
            ->assertJsonPath('dados.componentes.processos.detalhe', 'scheduler: sem registo; worker: sem registo');

        Batimentos::registar(Batimentos::SCHEDULER);
        (new BatimentoWorker)->handle();
        $this->getJson('/api/saude')->assertOk()->assertJsonPath('dados.componentes.processos.estado', 'OK');

        // worker parado há 20 min: FALHA, com o detalhe visível (sem segredos)
        Cache::forever('batimento:worker', now()->subMinutes(20)->timestamp);
        $this->getJson('/api/saude')->assertStatus(503)->assertJsonPath('erros.processos.estado', 'FALHA')
            ->assertJsonPath('erros.processos.detalhe', fn ($d) => str_contains($d, 'worker sem batimento'));
        $this->assertContains('sistema:batimento-scheduler', collect(app(Schedule::class)->events())->pluck('description')->all());
    }
}
