<?php

namespace Tests\Feature;

use App\Models\Utilizador;
use Tests\TestCase;

/**
 * Pedido de 2026-10-22: palavras-passe fracas recusadas, entrar com o e-mail e, numa conta criada (ou com a
 * palavra-passe reposta) por um administrador, o primeiro acesso pede a alteração — pode adiar-se uma vez.
 */
final class PrimeiroAcessoTest extends TestCase
{
    /** @return array{0: array<string, string>, 1: int} */
    private function admin(): array
    {
        $e = $this->criarEmpresa();
        $perfil = $this->criarPerfil(['all' => true], 'Total');
        $a = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true, 'perfil_utilizador_id' => $perfil->id]);

        return [$this->entrar($a) + ['X-Empresa-Id' => $e->id], $perfil->id];
    }

    public function test_palavra_passe_fraca_e_recusada(): void
    {
        [$h, $perfil] = $this->admin();
        foreach (['abcdefgh', '12345678', 'abcd1234', 'ABCD1234'] as $fraca) {
            $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'fraco', 'palavra_passe' => $fraca, 'perfil_utilizador_id' => $perfil, 'acesso_todas_empresas' => true], $h)
                ->assertStatus(422)->assertJsonPath('codigo', 'PALAVRA_PASSE_FRACA');
        }
    }

    public function test_conta_nova_pede_a_alteracao_no_primeiro_acesso_e_pode_adiar(): void
    {
        [$h, $perfil] = $this->admin();
        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'novo.user', 'email' => 'Novo@Exemplo.test', 'palavra_passe' => 'Provisoria2026',
            'perfil_utilizador_id' => $perfil, 'acesso_todas_empresas' => true], $h)->assertCreated();

        // entra pelo e-mail (sem distinguir maiúsculas)
        $r = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'novo@exemplo.test', 'palavra_passe' => 'Provisoria2026'])->assertOk();
        $this->assertTrue($r->json('dados.utilizador.alterar_palavra_passe'));
        $s = ['Authorization' => 'Bearer '.$r->json('dados.token')];

        $this->putJson('/api/autenticacao/palavra-passe/dispensar', [], $s)->assertOk();
        $this->getJson('/api/autenticacao/eu', $s)->assertOk()->assertJsonPath('dados.utilizador.alterar_palavra_passe', false);
    }

    public function test_email_de_mais_de_uma_conta_pede_o_nome_de_utilizador(): void
    {
        $this->criarUtilizador(['nome_utilizador' => 'um', 'email' => 'partilhado@exemplo.test']);
        $this->criarUtilizador(['nome_utilizador' => 'dois', 'email' => 'partilhado@exemplo.test']);

        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'partilhado@exemplo.test', 'palavra_passe' => 'Palavra#Passe2026'])
            ->assertStatus(401)->assertJsonPath('codigo', 'EMAIL_AMBIGUO');
    }
}
