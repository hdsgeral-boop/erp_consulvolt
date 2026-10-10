<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** «Gerir Dados Pessoais»: o próprio utilizador altera nome, telefone, e-mail e palavra-passe. */
final class DadosPessoaisTest extends TestCase
{
    #[Test]
    public function o_utilizador_ve_e_altera_os_seus_dados_sem_tocar_no_resto(): void
    {
        $u = $this->criarUtilizador(['nome_completo' => 'Nome Antigo', 'email' => 'antigo@exemplo.test']);
        $s = $this->entrar($u);

        $this->getJson('/api/autenticacao/dados-pessoais', $s)->assertOk()
            ->assertJsonPath('dados.nome_utilizador', $u->nome_utilizador)->assertJsonPath('dados.nome_completo', 'Nome Antigo');

        // campos de administração no pedido são ignorados (só nome, telefone e e-mail)
        $this->putJson('/api/autenticacao/dados-pessoais', ['nome_completo' => '  Nome Novo ', 'telefone' => '+244 900 000 000', 'email' => 'Novo@Exemplo.test',
            'papel' => 'SUPER_ADMINISTRADOR', 'nome_utilizador' => 'outro', 'ativo' => false], $s)
            ->assertOk()->assertJsonPath('dados.nome_completo', 'Nome Novo')->assertJsonPath('dados.telefone', '+244 900 000 000')->assertJsonPath('dados.email', 'novo@exemplo.test');
        $u->refresh();
        $this->assertSame([$u->getOriginal('papel'), true], [$u->papel, (bool) $u->ativo]);
        $this->assertNotSame('outro', $u->nome_utilizador);
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Alterar dados pessoais')->where('registo_id', (string) $u->id)->exists());

        $this->putJson('/api/autenticacao/dados-pessoais', ['nome_completo' => 'X', 'telefone' => 'abc<script>', 'email' => 'nao-e-email'], $s)
            ->assertStatus(422);
        $this->putJson('/api/autenticacao/dados-pessoais', ['nome_completo' => ''], $s)->assertStatus(422);
    }

    #[Test]
    public function palavra_passe_exige_a_actual_e_termina_as_outras_sessoes(): void
    {
        $u = $this->criarUtilizador();
        $outra = $this->entrar($u);
        $s = $this->entrar($u);

        $this->putJson('/api/autenticacao/palavra-passe', ['palavra_passe_actual' => 'errada-123', 'palavra_passe_nova' => 'NovaSenha#2026', 'palavra_passe_nova_confirmation' => 'NovaSenha#2026'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PALAVRA_PASSE_ACTUAL_ERRADA');
        $this->putJson('/api/autenticacao/palavra-passe', ['palavra_passe_actual' => 'Palavra#Passe2026', 'palavra_passe_nova' => 'curta', 'palavra_passe_nova_confirmation' => 'curta'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PALAVRA_PASSE_CURTA');
        $this->putJson('/api/autenticacao/palavra-passe', ['palavra_passe_actual' => 'Palavra#Passe2026', 'palavra_passe_nova' => 'NovaSenha#2026', 'palavra_passe_nova_confirmation' => 'Outra#2026'], $s)
            ->assertStatus(422);

        $this->putJson('/api/autenticacao/palavra-passe', ['palavra_passe_actual' => 'Palavra#Passe2026', 'palavra_passe_nova' => 'NovaSenha#2026', 'palavra_passe_nova_confirmation' => 'NovaSenha#2026'], $s)
            ->assertOk()->assertJsonPath('dados.sessoes_terminadas', 1);

        $this->assertTrue(Hash::check('NovaSenha#2026', $u->refresh()->palavra_passe));
        $this->getJson('/api/autenticacao/eu', $s)->assertOk();          // a sessão actual continua
        $this->getJson('/api/autenticacao/eu', $outra)->assertStatus(401); // a outra foi terminada
        $this->entrar($u, 'NovaSenha#2026');
        $log = DB::table('logs_auditoria')->where('acao', 'Alterar palavra-passe')->where('registo_id', (string) $u->id)->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('NovaSenha', (string) $log->detalhes.(string) $log->dados_novos);
    }

    #[Test]
    public function sem_sessao_nao_ha_acesso(): void
    {
        $this->getJson('/api/autenticacao/dados-pessoais')->assertStatus(401);
        $this->putJson('/api/autenticacao/palavra-passe', [])->assertStatus(401);
    }
}
