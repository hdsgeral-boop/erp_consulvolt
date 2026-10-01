<?php

namespace Tests\Feature;

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
}
