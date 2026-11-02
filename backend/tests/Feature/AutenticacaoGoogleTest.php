<?php

namespace Tests\Feature;

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as UtilizadorGoogle;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Login com o Google (AutenticacaoGoogleController): navegação por redirect, nunca AJAX — ver routes/web.php. */
final class AutenticacaoGoogleTest extends TestCase
{
    private function mockarGoogle(string $googleId, string $email, bool $emailVerificado = true, string $nome = 'Pessoa Teste'): void
    {
        Socialite::shouldReceive('driver->user')->andReturn(
            UtilizadorGoogle::fake(['id' => $googleId, 'email' => $email, 'name' => $nome, 'email_verified' => $emailVerificado]),
        );
    }

    #[Test]
    public function login_com_google_id_ja_ligado_emite_cookie_e_vai_para_app(): void
    {
        $u = $this->criarUtilizador(['email' => 'rita@empresa.ao']);
        $u->forceFill(['google_id' => 'g-123'])->save();
        $this->mockarGoogle('g-123', 'rita@empresa.ao');

        $resposta = $this->get('/auth/google/callback')->assertRedirect('/app');
        $this->assertNotEmpty($this->tokenDoCookie($resposta));
    }

    #[Test]
    public function primeira_ligacao_por_email_verificado_associa_a_conta_existente(): void
    {
        $u = $this->criarUtilizador(['email' => 'joana@empresa.ao']);
        $this->assertNull($u->google_id);
        $this->mockarGoogle('g-456', 'joana@empresa.ao', true);

        $resposta = $this->get('/auth/google/callback')->assertRedirect('/app');
        $this->assertNotEmpty($this->tokenDoCookie($resposta));
        $this->assertSame('g-456', $u->fresh()->google_id);
    }

    #[Test]
    public function email_nao_verificado_nao_liga_a_conta_e_vai_para_o_registo(): void
    {
        $u = $this->criarUtilizador(['email' => 'pedro@empresa.ao']);
        $this->mockarGoogle('g-789', 'pedro@empresa.ao', false, 'Pedro Teste');

        $this->get('/auth/google/callback')->assertRedirect('/registar?nome=Pedro+Teste&email=pedro%40empresa.ao');
        $this->assertNull($u->fresh()->google_id);
    }

    #[Test]
    public function utilizador_desconhecido_vai_para_o_registo_com_nome_e_email(): void
    {
        $this->mockarGoogle('g-novo', 'novo@empresa.ao', true, 'Novo Utilizador');

        $this->get('/auth/google/callback')->assertRedirect('/registar?nome=Novo+Utilizador&email=novo%40empresa.ao');
    }

    #[Test]
    public function utilizador_inactivo_e_rejeitado(): void
    {
        $u = $this->criarUtilizador(['email' => 'ines@empresa.ao', 'ativo' => false]);
        $u->forceFill(['google_id' => 'g-inactivo'])->save();
        $this->mockarGoogle('g-inactivo', 'ines@empresa.ao');

        $this->get('/auth/google/callback')->assertRedirect('/entrar?erro=google_conta_inactiva');
    }

    #[Test]
    public function utilizador_com_mfa_activo_e_rejeitado_sem_emitir_cookie(): void
    {
        $u = $this->criarUtilizador(['email' => 'mfa@empresa.ao', 'mfa_ativo' => true, 'mfa_segredo' => 'ABCDEFGHIJKLMNOP']);
        $u->forceFill(['google_id' => 'g-mfa'])->save();
        $this->mockarGoogle('g-mfa', 'mfa@empresa.ao');

        $resposta = $this->get('/auth/google/callback')->assertRedirect('/entrar?erro=google_mfa_obrigatorio');
        $this->assertNull($this->tokenDoCookie($resposta));
    }

    #[Test]
    public function email_ja_ligado_a_outra_conta_google_e_rejeitado(): void
    {
        $u = $this->criarUtilizador(['email' => 'carla@empresa.ao']);
        $u->forceFill(['google_id' => 'g-original'])->save();
        $this->mockarGoogle('g-diferente', 'carla@empresa.ao');

        $this->get('/auth/google/callback')->assertRedirect('/entrar?erro=google_conta_ja_ligada');
        $this->assertSame('g-original', $u->fresh()->google_id);
    }

    #[Test]
    public function falha_a_obter_o_perfil_do_google_e_tratada_sem_rebentar(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new \Exception('falha simulada'));

        $this->get('/auth/google/callback')->assertRedirect('/entrar?erro=google_falhou');
    }
}
