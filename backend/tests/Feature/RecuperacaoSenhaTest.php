<?php

namespace Tests\Feature;

use App\Mail\RecuperarSenhaMail;
use App\Models\Tenant;
use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Recuperação de palavra-passe: resposta genérica, token de uso único e com validade, e isolamento por cliente (host). */
class RecuperacaoSenhaTest extends TestCase
{
    private const NOVA = 'NovaPalavra#2026';

    /** Pede a recuperação e devolve o token extraído da ligação enviada por e-mail (null se nada foi enviado). */
    private function pedir(string $identificador, string $url = '/api/autenticacao/recuperar-senha'): ?string
    {
        Mail::fake();
        $this->postJson($url, ['identificador' => $identificador])->assertOk();

        $token = null;
        Mail::assertSent(RecuperarSenhaMail::class, function (RecuperarSenhaMail $m) use (&$token) {
            parse_str((string) parse_url($m->ligacao, PHP_URL_QUERY), $q);
            $token = $q['token'] ?? null;

            return true;
        });

        return $token;
    }

    public function test_resposta_e_generica_exista_ou_nao_o_utilizador(): void
    {
        Mail::fake();
        $this->criarUtilizador(['nome_utilizador' => 'rec_existe', 'email' => 'rec@exemplo.test']);

        $a = $this->postJson('/api/autenticacao/recuperar-senha', ['identificador' => 'rec_existe'])->assertOk();
        $b = $this->postJson('/api/autenticacao/recuperar-senha', ['identificador' => 'nao_existe_xyz'])->assertOk();

        $this->assertSame($a->json('mensagem'), $b->json('mensagem'));
        Mail::assertSent(RecuperarSenhaMail::class, 1);
    }

    public function test_redefine_a_palavra_passe_e_revoga_sessoes(): void
    {
        $u = $this->criarUtilizador(['nome_utilizador' => 'rec_ok', 'email' => 'ok@exemplo.test']);
        $this->entrar($u);
        $this->assertSame(1, $u->tokens()->count());

        $token = $this->pedir('ok@exemplo.test');
        $this->assertNotNull($token);
        $this->assertSame(0, DB::table('recuperacoes_senha')->where('token_hash', $token)->count(), 'Só o hash é guardado.');

        $this->postJson('/api/autenticacao/redefinir-senha', ['token' => $token, 'palavra_passe' => self::NOVA, 'palavra_passe_confirmation' => self::NOVA])->assertOk();

        $this->assertSame(0, $u->tokens()->count());
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'rec_ok', 'palavra_passe' => self::NOVA])->assertOk();
    }

    public function test_token_expirado_e_recusado(): void
    {
        $this->criarUtilizador(['nome_utilizador' => 'rec_exp', 'email' => 'exp@exemplo.test']);
        $token = $this->pedir('rec_exp');
        DB::table('recuperacoes_senha')->update(['expira_em' => now()->subMinute()]);

        $this->postJson('/api/autenticacao/redefinir-senha', ['token' => $token, 'palavra_passe' => self::NOVA, 'palavra_passe_confirmation' => self::NOVA])
            ->assertStatus(422)->assertJsonPath('codigo', 'TOKEN_RECUPERACAO_INVALIDO');
    }

    public function test_token_so_pode_ser_usado_uma_vez(): void
    {
        $this->criarUtilizador(['nome_utilizador' => 'rec_uso', 'email' => 'uso@exemplo.test']);
        $token = $this->pedir('rec_uso');
        $corpo = ['token' => $token, 'palavra_passe' => self::NOVA, 'palavra_passe_confirmation' => self::NOVA];

        $this->postJson('/api/autenticacao/redefinir-senha', $corpo)->assertOk();
        $this->postJson('/api/autenticacao/redefinir-senha', $corpo)->assertStatus(422)->assertJsonPath('codigo', 'TOKEN_RECUPERACAO_INVALIDO');
    }

    public function test_utilizador_de_outro_cliente_nao_recupera_pelo_subdominio_errado(): void
    {
        config(['erp.dominio.base' => 'meuerp.test']);
        $acme = Tenant::query()->create(['nome' => 'Acme', 'slug' => 'acme', 'estado' => Tenant::ESTADO_ATIVO]);
        Tenant::query()->create(['nome' => 'Beta', 'slug' => 'beta', 'estado' => Tenant::ESTADO_ATIVO]);
        $u = $this->criarUtilizador(['nome_utilizador' => 'rec_acme', 'email' => 'acme@exemplo.test']);
        $u->forceFill(['tenant_id' => $acme->id])->save();

        // Subdomínio de outro cliente: resposta igual, mas nenhum e-mail.
        Mail::fake();
        $this->postJson('http://beta.meuerp.test/api/autenticacao/recuperar-senha', ['identificador' => 'rec_acme'])->assertOk();
        Mail::assertNothingSent();

        // Host fora do domínio base (host-header injection): nenhum e-mail.
        $this->postJson('http://atacante.example/api/autenticacao/recuperar-senha', ['identificador' => 'rec_acme'])->assertOk();
        Mail::assertNothingSent();

        // No subdomínio certo envia, com a ligação nesse host; e um token de outro cliente não redefine no subdomínio errado.
        $token = $this->pedir('rec_acme', 'http://acme.meuerp.test/api/autenticacao/recuperar-senha');
        $this->postJson('http://beta.meuerp.test/api/autenticacao/redefinir-senha', ['token' => $token, 'palavra_passe' => self::NOVA, 'palavra_passe_confirmation' => self::NOVA])
            ->assertStatus(422)->assertJsonPath('codigo', 'TOKEN_RECUPERACAO_INVALIDO');
        $this->postJson('http://acme.meuerp.test/api/autenticacao/redefinir-senha', ['token' => $token, 'palavra_passe' => self::NOVA, 'palavra_passe_confirmation' => self::NOVA])->assertOk();
        $this->assertInstanceOf(Utilizador::class, $u->fresh());
    }
}
