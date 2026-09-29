<?php

namespace Tests\Feature;

use App\Models\LogAuditoria;
use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AutenticacaoTest extends TestCase
{
    /** Utilizador tal como sai do ETL: só com o hash PBKDF2 do legado. */
    private function utilizadorMigrado(): Utilizador
    {
        $id = DB::table('utilizadores')->insertGetId([
            'nome_utilizador' => 'celso',
            'hash_password_legado' => 'osuPYeOnI5bTXe+Ib78Bc3Lto7iOlxpsOeEcyEuD79U=',
            'salt_password_legado' => 'ABEiM0RVZneImaq7zN3u/w==',
            'algoritmo_password_legado' => 'PBKDF2-SHA256-120000',
            'papel' => Utilizador::PAPEL_UTILIZADOR,
            'ativo' => true,
        ]);

        return Utilizador::findOrFail($id);
    }

    #[Test]
    public function utilizador_migrado_entra_com_a_palavra_passe_do_legado_e_fica_com_argon2id(): void
    {
        $u = $this->utilizadorMigrado();

        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'celso', 'palavra_passe' => 'Senha@Legado2026'])
            ->assertOk()
            ->assertJsonStructure($this->envelope())
            ->assertJsonStructure(['dados' => ['token', 'tipo_token', 'expira_em', 'inatividade_minutos', 'utilizador', 'permissoes', 'empresas']])
            ->assertJsonPath('dados.tipo_token', 'Bearer')
            ->assertJsonPath('dados.utilizador.nome_utilizador', 'celso')
            ->assertJsonMissingPath('dados.utilizador.palavra_passe');

        $u->refresh();
        $this->assertNull($u->hash_password_legado);
        $this->assertNull($u->salt_password_legado);
        $this->assertStringStartsWith('$argon2id$', $u->palavra_passe);
        $this->assertTrue(Hash::check('Senha@Legado2026', $u->palavra_passe));

        // O segundo login já usa o hash moderno.
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'celso', 'palavra_passe' => 'Senha@Legado2026'])->assertOk();
        $this->assertTrue(LogAuditoria::withoutGlobalScopes()->where('acao', 'Migração de palavra-passe')->where('utilizador_id', $u->id)->exists());
    }

    #[Test]
    public function credenciais_erradas_ou_utilizador_inexistente_dao_a_mesma_resposta(): void
    {
        $this->utilizadorMigrado();

        $errada = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'celso', 'palavra_passe' => 'errada']);
        $inexistente = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'ninguem', 'palavra_passe' => 'errada']);

        $errada->assertStatus(401)->assertJsonPath('codigo', 'CREDENCIAIS_INVALIDAS');
        $inexistente->assertStatus(401)->assertJsonPath('codigo', 'CREDENCIAIS_INVALIDAS');
        $this->assertSame($errada->json('mensagem'), $inexistente->json('mensagem'));
        $this->assertSame(2, LogAuditoria::withoutGlobalScopes()->where('acao', 'Falha de autenticação')->count());
    }

    #[Test]
    public function nome_de_utilizador_e_comparado_de_forma_exacta_como_no_legado(): void
    {
        $this->utilizadorMigrado();

        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'CELSO', 'palavra_passe' => 'Senha@Legado2026'])->assertStatus(401);
    }

    #[Test]
    public function utilizador_inactivo_nao_entra(): void
    {
        $u = $this->criarUtilizador(['ativo' => false]);

        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])
            ->assertStatus(401);
    }

    #[Test]
    public function limita_tentativas_de_login(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'alvo', 'palavra_passe' => "x{$i}"])->assertStatus(401);
        }

        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'alvo', 'palavra_passe' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('codigo', 'LIMITE_PEDIDOS');
    }

    #[Test]
    public function eu_devolve_a_sessao_e_sair_revoga_o_token(): void
    {
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR]);
        $cabecalhos = $this->entrar($u);

        $this->getJson('/api/autenticacao/eu', $cabecalhos)
            ->assertOk()
            ->assertJsonPath('dados.utilizador.id', $u->id)
            ->assertJsonPath('dados.permissoes', ['*']);

        $this->postJson('/api/autenticacao/sair', [], $cabecalhos)->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/autenticacao/eu', $cabecalhos)->assertStatus(401)->assertJsonPath('codigo', 'NAO_AUTENTICADO');
    }

    #[Test]
    public function sessao_expira_apos_15_minutos_de_inactividade(): void
    {
        $cabecalhos = $this->entrar($this->criarUtilizador());

        $this->travel(14)->minutes();
        $this->getJson('/api/autenticacao/eu', $cabecalhos)->assertOk();   // renova a actividade
        $this->app['auth']->forgetGuards();

        $this->travel(14)->minutes();
        $this->getJson('/api/autenticacao/eu', $cabecalhos)->assertOk();   // 28 min desde o login, 14 sem actividade
        $this->app['auth']->forgetGuards();

        $this->travel(16)->minutes();
        $this->getJson('/api/autenticacao/eu', $cabecalhos)->assertStatus(401);
    }

    #[Test]
    public function desactivar_o_utilizador_invalida_os_tokens_existentes(): void
    {
        $u = $this->criarUtilizador();
        $cabecalhos = $this->entrar($u);

        $u->update(['ativo' => false]);

        $this->getJson('/api/autenticacao/eu', $cabecalhos)->assertStatus(401);
    }

    #[Test]
    public function token_fica_na_tabela_tokens_acesso_em_portugues_sem_guardar_o_segredo(): void
    {
        $u = $this->criarUtilizador();
        $token = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026', 'dispositivo' => 'Portátil RH'])
            ->json('dados.token');

        $linha = DB::table('tokens_acesso')->first();
        $this->assertSame('utilizador', $linha->portador_tipo);
        $this->assertSame($u->id, (int) $linha->portador_id);
        $this->assertSame('Portátil RH', $linha->nome);
        $this->assertNotNull($linha->expira_em);
        $this->assertSame(hash('sha256', explode('|', $token, 2)[1]), $linha->token);
    }
}
