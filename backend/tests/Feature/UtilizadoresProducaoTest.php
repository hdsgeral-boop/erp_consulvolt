<?php

namespace Tests\Feature;

use App\Mail\NovoUtilizadorMail;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoUtilizadores;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regras trazidas da produção, adaptadas ao modelo do teste (palavra-passe SEMPRE gerada pelo servidor + troca obrigatória):
 * reactivar utilizador eliminado, força de palavra-passe escolhida pelo utilizador e login por e-mail (EMAIL_AMBIGUO).
 */
final class UtilizadoresProducaoTest extends TestCase
{
    /** @return array{0: array<string, string>, 1: int} */
    private function admin(): array
    {
        $e = $this->criarEmpresa();
        $perfil = $this->criarPerfil(['all' => true], 'Total');
        $a = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true, 'perfil_utilizador_id' => $perfil->id]);

        return [$this->entrar($a) + ['X-Empresa-Id' => $e->id], $perfil->id];
    }

    public function test_criar_com_o_nome_de_um_eliminado_oferece_e_faz_a_reactivacao(): void
    {
        Mail::fake();
        [$h, $perfil] = $this->admin();
        $corpo = ['nome_utilizador' => 'ana.antiga', 'email' => 'ana@exemplo.test', 'perfil_utilizador_id' => $perfil, 'acesso_todas_empresas' => true, 'nome_completo' => 'Ana Nova'];

        $id = $this->postJson('/api/sistema/utilizadores', $corpo, $h)->assertCreated()->json('dados.id');
        $this->deleteJson("/api/sistema/utilizadores/{$id}", [], $h)->assertOk();

        $this->postJson('/api/sistema/utilizadores', $corpo, $h)->assertStatus(422)
            ->assertJsonPath('codigo', 'UTILIZADOR_ELIMINADO')->assertJsonPath('erros.utilizador_id', $id);
        $this->postJson('/api/sistema/utilizadores', $corpo + ['reactivar' => true], $h)->assertCreated()->assertJsonPath('dados.id', $id);

        $u = Utilizador::query()->findOrFail($id);
        $this->assertNull($u->eliminado_em);
        $this->assertTrue((bool) $u->ativo);
        $this->assertTrue((bool) $u->deve_alterar_password, 'a conta reactivada recebe palavra-passe nova gerada pelo servidor, com troca obrigatória');
        $this->assertSame('Ana Nova', $u->nome_completo);
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Reactivar utilizador')->exists());

        // a palavra-passe nova vai por e-mail e permite entrar
        $enviados = Mail::queued(NovoUtilizadorMail::class);
        $this->assertCount(2, $enviados);
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'ana.antiga', 'palavra_passe' => $this->palavraPasseDoEmail($enviados->last())])->assertOk();
    }

    public function test_nome_de_uma_conta_activa_continua_duplicado(): void
    {
        [$h, $perfil] = $this->admin();
        $this->criarUtilizador(['nome_utilizador' => 'ja.existe']);

        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'ja.existe', 'perfil_utilizador_id' => $perfil, 'reactivar' => true], $h)
            ->assertStatus(422)->assertJsonPath('codigo', 'UTILIZADOR_DUPLICADO');
    }

    public function test_palavra_passe_escolhida_fraca_e_recusada_e_a_gerada_cumpre_a_politica(): void
    {
        foreach (['abcdefgh', '12345678', 'abcd1234', 'ABCD1234'] as $fraca) {
            $this->assertNotNull(ServicoUtilizadores::problemaDeForca($fraca), $fraca);
        }
        $this->assertNull(ServicoUtilizadores::problemaDeForca('Luanda#2026'));
        $gerador = app(ServicoUtilizadores::class);
        for ($i = 0; $i < 50; $i++) {
            $this->assertNull(ServicoUtilizadores::problemaDeForca($gerador->gerarPalavraPasseAleatoria()));
        }

        $u = $this->criarUtilizador(['nome_utilizador' => 'troca.fraca']);
        $s = $this->entrar($u);
        $this->putJson('/api/autenticacao/palavra-passe', ['palavra_passe_actual' => 'Palavra#Passe2026', 'palavra_passe_nova' => 'abcd1234', 'palavra_passe_nova_confirmation' => 'abcd1234'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PALAVRA_PASSE_FRACA');
        $this->putJson('/api/autenticacao/palavra-passe', ['palavra_passe_actual' => 'Palavra#Passe2026', 'palavra_passe_nova' => 'Luanda#2027', 'palavra_passe_nova_confirmation' => 'Luanda#2027'], $s)
            ->assertOk();
    }

    public function test_entrar_pelo_email_sem_distinguir_maiusculas_e_email_partilhado_pede_o_nome(): void
    {
        $this->criarUtilizador(['nome_utilizador' => 'unico', 'email' => 'unico@exemplo.test']);
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'Unico@Exemplo.TEST', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();

        $this->criarUtilizador(['nome_utilizador' => 'um', 'email' => 'partilhado@exemplo.test']);
        $this->criarUtilizador(['nome_utilizador' => 'dois', 'email' => 'partilhado@exemplo.test']);
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'partilhado@exemplo.test', 'palavra_passe' => 'Palavra#Passe2026'])
            ->assertStatus(401)->assertJsonPath('codigo', 'EMAIL_AMBIGUO');
        // entrar pelo nome continua a funcionar
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'um', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();
    }
}
