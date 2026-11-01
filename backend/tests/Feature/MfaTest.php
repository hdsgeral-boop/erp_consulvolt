<?php

namespace Tests\Feature;

use App\Models\Utilizador;
use App\Services\Autenticacao\ServicoTotp;
use Tests\TestCase;

/**
 * Fase 4 da extensão SaaS (segurança.txt, secção 12, ponto 222): MFA por TOTP. Login em dois passos quando activo —
 * credenciais válidas só dão um token limitado ('mfa:pendente'), nunca uma sessão completa, até o código ser confirmado.
 */
class MfaTest extends TestCase
{
    private function codigoAtual(string $segredo): string
    {
        $totp = app(ServicoTotp::class);
        $gerarCodigo = new \ReflectionMethod($totp, 'gerarCodigo');

        return $gerarCodigo->invoke($totp, $segredo, intdiv(time(), 30));
    }

    public function test_login_sem_mfa_continua_a_dar_sessao_completa_de_imediato(): void
    {
        $u = $this->criarUtilizador(['nome_utilizador' => 'sem_mfa']);

        $r = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'sem_mfa', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();
        $this->assertFalse($r->json('dados.mfa_pendente'));
        $this->assertNotNull($this->tokenDoCookie($r));
    }

    public function test_fluxo_completo_de_configuracao_e_login_com_mfa(): void
    {
        $u = $this->criarUtilizador(['nome_utilizador' => 'com_mfa', 'email' => 'com_mfa@teste.ao']);
        $sessao = $this->entrar($u);

        // iniciar: gera um segredo ainda inactivo
        $setup = $this->postJson('/api/autenticacao/mfa/iniciar', [], $sessao)->assertOk()->json('dados');
        $this->assertNotEmpty($setup['segredo']);
        $this->assertStringStartsWith('otpauth://totp/', $setup['uri']);
        $this->assertFalse($u->fresh()->mfa_ativo);

        // confirmar com código errado: continua inactivo
        $this->postJson('/api/autenticacao/mfa/confirmar', ['codigo' => '000000'], $sessao)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_INVALIDO');
        $this->assertFalse($u->fresh()->mfa_ativo);

        // confirmar com o código certo: activa e devolve códigos de recuperação
        $confirmar = $this->postJson('/api/autenticacao/mfa/confirmar', ['codigo' => $this->codigoAtual($setup['segredo'])], $sessao)
            ->assertOk()->json('dados');
        $this->assertTrue($confirmar['ativo']);
        $this->assertCount(8, $confirmar['codigos_recuperacao']);
        $this->assertTrue($u->fresh()->mfa_ativo);

        // login agora exige o 2.º passo: não dá sessão completa de imediato
        $login = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'com_mfa', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk()->json('dados');
        $this->assertTrue($login['mfa_pendente']);
        $this->assertNotEmpty($login['token_mfa']);
        $tokenMfa = ['Authorization' => "Bearer {$login['token_mfa']}"];

        // o token limitado não serve de sessão completa: bloqueado em qualquer rota normal
        $this->getJson('/api/autenticacao/eu', $tokenMfa)->assertStatus(423)->assertJsonPath('codigo', 'MFA_PENDENTE');

        // código errado no 2.º passo: rejeitado, token continua só pendente
        $this->postJson('/api/autenticacao/mfa/verificar', ['codigo' => '000000'], $tokenMfa)->assertStatus(422);

        // código certo no 2.º passo: emite a sessão completa
        $respostaFinal = $this->postJson('/api/autenticacao/mfa/verificar', ['codigo' => $this->codigoAtual($u->fresh()->mfa_segredo)], $tokenMfa)
            ->assertOk();
        $this->assertFalse($respostaFinal->json('dados.mfa_pendente'));
        $tokenCompleto = ['Authorization' => 'Bearer '.$this->tokenDoCookie($respostaFinal)];
        $this->getJson('/api/autenticacao/eu', $tokenCompleto)->assertOk();

        // o token limitado já não serve de nada (foi substituído, não só "completado")
        $this->postJson('/api/autenticacao/mfa/verificar', ['codigo' => '123456'], $tokenMfa)->assertUnauthorized();
    }

    public function test_codigo_de_recuperacao_e_de_uso_unico(): void
    {
        $u = $this->criarUtilizador(['nome_utilizador' => 'com_recuperacao']);
        $sessao = $this->entrar($u);
        $setup = $this->postJson('/api/autenticacao/mfa/iniciar', [], $sessao)->assertOk()->json('dados');
        $codigos = $this->postJson('/api/autenticacao/mfa/confirmar', ['codigo' => $this->codigoAtual($setup['segredo'])], $sessao)
            ->assertOk()->json('dados.codigos_recuperacao');

        $login = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'com_recuperacao', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk()->json('dados');
        $tokenMfa = ['Authorization' => "Bearer {$login['token_mfa']}"];

        $this->postJson('/api/autenticacao/mfa/verificar', ['codigo' => $codigos[0]], $tokenMfa)->assertOk();

        // o mesmo código de recuperação já não serve uma segunda vez
        $login2 = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'com_recuperacao', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk()->json('dados');
        $tokenMfa2 = ['Authorization' => "Bearer {$login2['token_mfa']}"];
        $this->postJson('/api/autenticacao/mfa/verificar', ['codigo' => $codigos[0]], $tokenMfa2)->assertStatus(422);

        // mas o segundo código de recuperação ainda serve
        $this->postJson('/api/autenticacao/mfa/verificar', ['codigo' => $codigos[1]], $tokenMfa2)->assertOk();
    }

    public function test_desactivar_exige_a_palavra_passe_actual(): void
    {
        $u = $this->criarUtilizador(['nome_utilizador' => 'desactiva_mfa']);
        $sessao = $this->entrar($u);
        $setup = $this->postJson('/api/autenticacao/mfa/iniciar', [], $sessao)->assertOk()->json('dados');
        $this->postJson('/api/autenticacao/mfa/confirmar', ['codigo' => $this->codigoAtual($setup['segredo'])], $sessao)->assertOk();

        $this->postJson('/api/autenticacao/mfa/desactivar', ['palavra_passe' => 'Errada#2026'], $sessao)
            ->assertStatus(422)->assertJsonPath('codigo', 'PALAVRA_PASSE_INCORRETA');
        $this->assertTrue($u->fresh()->mfa_ativo);

        $this->postJson('/api/autenticacao/mfa/desactivar', ['palavra_passe' => 'Palavra#Passe2026'], $sessao)->assertOk();
        $this->assertFalse($u->fresh()->mfa_ativo);
        $this->assertNull($u->fresh()->mfa_segredo);

        // sem MFA activo, o login volta a dar sessão completa de imediato
        $login = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'desactiva_mfa', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk()->json('dados');
        $this->assertFalse($login['mfa_pendente']);
    }

    public function test_confirmar_sem_setup_iniciado_e_rejeitado(): void
    {
        $u = $this->criarUtilizador(['nome_utilizador' => 'sem_setup']);
        $sessao = $this->entrar($u);

        $this->postJson('/api/autenticacao/mfa/confirmar', ['codigo' => '123456'], $sessao)
            ->assertStatus(422)->assertJsonPath('codigo', 'MFA_SEM_SETUP');
    }
}
