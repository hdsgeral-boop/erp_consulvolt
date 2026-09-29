<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Critério de aceitação: todas as respostas usam o envelope { sucesso, mensagem, dados, metadados }. */
final class EnvelopeRespostaTest extends TestCase
{
    #[Test]
    public function saude_responde_com_envelope_de_sucesso(): void
    {
        $this->getJson('/api/saude')
            ->assertOk()
            ->assertJsonStructure($this->envelope())
            ->assertJsonPath('sucesso', true)
            ->assertJsonPath('dados.estado', 'OK')
            ->assertJsonPath('dados.componentes.base_dados.estado', 'OK')
            ->assertJsonPath('dados.componentes.redis.estado', 'OK');
    }

    #[Test]
    public function rota_inexistente_responde_404_com_envelope_de_erro(): void
    {
        $this->get('/api/nao-existe')   // sem Accept JSON: a API responde sempre JSON
            ->assertNotFound()
            ->assertJsonStructure($this->envelope(false))
            ->assertJsonPath('sucesso', false)
            ->assertJsonPath('codigo', 'NAO_ENCONTRADO');
    }

    #[Test]
    public function metodo_nao_suportado_responde_405(): void
    {
        $this->deleteJson('/api/saude')->assertStatus(405)->assertJsonPath('codigo', 'METODO_NAO_PERMITIDO');
    }

    #[Test]
    public function erros_de_validacao_vem_por_campo_e_em_portugues(): void
    {
        $this->postJson('/api/autenticacao/entrar', [])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'VALIDACAO')
            ->assertJsonStructure(['erros' => ['nome_utilizador', 'palavra_passe']])
            ->assertJsonPath('erros.nome_utilizador.0', fn ($m) => str_contains($m, 'nome de utilizador'));
    }

    #[Test]
    public function metadados_trazem_data_de_execucao_em_utc(): void
    {
        $data = $this->getJson('/api/saude')->json('metadados.executado_em');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data);
    }
}
