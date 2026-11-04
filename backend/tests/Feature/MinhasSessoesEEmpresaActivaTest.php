<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoEmpresas;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-082: «As minhas sessões» (só as do próprio utilizador — IDOR) e empresa activa como estado da sessão no servidor.
 */
final class MinhasSessoesEEmpresaActivaTest extends TestCase
{
    private function h(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function tokenDe(Utilizador $u, string $dispositivo, string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36'): string
    {
        $r = $this->comDispositivo($dispositivo)->withHeader('User-Agent', $ua)->postJson('/api/autenticacao/entrar', [
            'nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026',
        ])->assertOk();

        return $this->tokenDoCookie($r);
    }

    // ---- sessões -----------------------------------------------------------------------------------------------------

    #[Test]
    public function lista_so_as_sessoes_do_proprio_com_rotulo_ip_aproximado_e_marca_da_actual(): void
    {
        $u = $this->criarUtilizador();
        $outro = $this->criarUtilizador();
        $a = $this->tokenDe($u, 'A');
        $this->tokenDe($u, 'B', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17) AppleWebKit Safari/604.1');
        $this->tokenDe($outro, 'C');

        $lista = $this->getJson('/api/autenticacao/sessoes', $this->h($a))->assertOk()->json('dados');

        $this->assertCount(2, $lista);
        $rotulos = array_column($lista, 'dispositivo');
        sort($rotulos);
        $this->assertSame(['Chrome em Windows', 'Safari em iOS'], $rotulos);
        $this->assertSame(1, count(array_filter($lista, fn ($s) => $s['esta_sessao'])));
        $actual = array_values(array_filter($lista, fn ($s) => $s['esta_sessao']))[0];
        $this->assertSame('Chrome em Windows', $actual['dispositivo']);
        foreach ($lista as $s) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+\.x$/', (string) $s['ip_aproximado'], 'IP aproximado: sem o último octeto');
            $this->assertNotEmpty($s['criada_em']);
            $this->assertNotEmpty($s['ultima_actividade_em']);
            $this->assertArrayNotHasKey('token', $s);
            $this->assertArrayNotHasKey('endereco_ip', $s);
        }
    }

    #[Test]
    public function terminar_uma_sessao_alheia_devolve_404_e_nao_a_apaga_idor(): void
    {
        $u = $this->criarUtilizador();
        $vitima = $this->criarUtilizador();
        $meu = $this->tokenDe($u, 'A');
        $tokenVitima = $this->tokenDe($vitima, 'V');
        $idVitima = (int) DB::table('tokens_acesso')->where('portador_id', $vitima->id)->value('id');

        $this->deleteJson("/api/autenticacao/sessoes/{$idVitima}", [], $this->h($meu))->assertStatus(404)->assertJsonPath('codigo', 'SESSAO_NAO_ENCONTRADA');
        $this->assertSame(1, DB::table('tokens_acesso')->where('id', $idVitima)->count());
        $this->getJson('/api/autenticacao/eu', $this->h($tokenVitima))->assertOk();
        // id inexistente: a mesma resposta (não revela se existe)
        $this->deleteJson('/api/autenticacao/sessoes/9999999', [], $this->h($meu))->assertStatus(404)->assertJsonPath('codigo', 'SESSAO_NAO_ENCONTRADA');
    }

    #[Test]
    public function terminar_a_propria_sessao_actual_pede_para_usar_sair_e_terminar_outra_propria_funciona(): void
    {
        $u = $this->criarUtilizador();
        $a = $this->tokenDe($u, 'A');
        $b = $this->tokenDe($u, 'B');
        $idA = (int) explode('|', $a)[0];
        $idB = (int) explode('|', $b)[0];

        $this->deleteJson("/api/autenticacao/sessoes/{$idA}", [], $this->h($a))->assertStatus(422)->assertJsonPath('codigo', 'SESSAO_PROPRIA');
        $this->deleteJson("/api/autenticacao/sessoes/{$idB}", [], $this->h($a))->assertOk();
        $this->getJson('/api/autenticacao/eu', $this->h($b))->assertStatus(401);
        $this->getJson('/api/autenticacao/eu', $this->h($a))->assertOk();
    }

    #[Test]
    public function terminar_todas_as_outras_nao_toca_na_actual_nem_nas_de_outros_utilizadores(): void
    {
        $u = $this->criarUtilizador();
        $outro = $this->criarUtilizador();
        $a = $this->tokenDe($u, 'A');
        $b = $this->tokenDe($u, 'B');
        $c = $this->tokenDe($u, 'C');
        $alheio = $this->tokenDe($outro, 'X');

        $this->postJson('/api/autenticacao/sessoes/terminar-outras', [], $this->h($a))->assertOk()->assertJsonPath('dados.terminadas', 2);

        $this->getJson('/api/autenticacao/eu', $this->h($a))->assertOk();
        $this->getJson('/api/autenticacao/eu', $this->h($b))->assertStatus(401);
        $this->getJson('/api/autenticacao/eu', $this->h($c))->assertStatus(401);
        $this->getJson('/api/autenticacao/eu', $this->h($alheio))->assertOk();
    }

    #[Test]
    public function as_rotas_de_sessoes_exigem_autenticacao(): void
    {
        $this->getJson('/api/autenticacao/sessoes')->assertStatus(401);
        $this->deleteJson('/api/autenticacao/sessoes/1')->assertStatus(401);
        $this->postJson('/api/autenticacao/sessoes/terminar-outras')->assertStatus(401);
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => 1])->assertStatus(401);
    }

    #[Test]
    public function sessao_inactiva_ha_mais_de_15_minutos_nao_aparece_na_lista(): void
    {
        $u = $this->criarUtilizador();
        $a = $this->tokenDe($u, 'A');
        $this->travel(10)->minutes();
        $b = $this->tokenDe($u, 'B');
        $this->travel(7)->minutes();   // A parada há 17 min; B há 7

        $lista = $this->getJson('/api/autenticacao/sessoes', $this->h($b))->assertOk()->json('dados');
        $this->assertCount(1, $lista);
        $this->assertTrue($lista[0]['esta_sessao']);
    }

    // ---- empresa activa --------------------------------------------------------------------------------------------

    /** @return array{0: Utilizador, 1: Empresa, 2: Empresa} */
    private function utilizadorComDuasEmpresas(): array
    {
        $e1 = $this->criarEmpresa();
        $e2 = $this->criarEmpresa();
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'dashboard_view' => true])->id]);
        $u->empresas()->attach([$e1->id, $e2->id]);

        return [$u, $e1, $e2];
    }

    #[Test]
    public function a_empresa_activa_fica_na_sessao_do_servidor_e_o_eu_devolve_a(): void
    {
        [$u, $e1] = $this->utilizadorComDuasEmpresas();
        $t = $this->tokenDe($u, 'A');
        $h = $this->h($t);

        $this->assertNull($this->getJson('/api/autenticacao/eu', $h)->assertOk()->json('dados.empresa_ativa_id'));
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $e1->id], $h)->assertOk()->assertJsonPath('dados.empresa_ativa_id', $e1->id);

        $this->assertSame($e1->id, $this->getJson('/api/autenticacao/eu', $h)->json('dados.empresa_ativa_id'));
        $this->assertSame($e1->id, (int) DB::table('tokens_acesso')->value('empresa_ativa_id'));
        $this->assertSame($e1->id, $this->getJson('/api/autenticacao/eu', $h)->json('dados.ultima_empresa_id'));
    }

    #[Test]
    public function sem_cabecalho_o_servidor_usa_a_empresa_activa_da_sessao_e_o_cabecalho_continua_a_mandar(): void
    {
        [$u, $e1, $e2] = $this->utilizadorComDuasEmpresas();
        $h = $this->h($this->tokenDe($u, 'A'));

        $this->getJson('/api/sistema/menu', $h)->assertStatus(422);   // nada escolhido: continua a pedir a empresa
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $e1->id], $h)->assertOk();

        $r = $this->getJson('/api/sistema/menu', $h)->assertOk();     // sem X-Empresa-Id: usa a da sessão
        $this->assertSame($e1->id, $r->json('metadados.empresa_id'));
        $r2 = $this->getJson('/api/sistema/menu', $h + ['X-Empresa-Id' => $e2->id])->assertOk();   // o cabeçalho manda (vários separadores)
        $this->assertSame($e2->id, $r2->json('metadados.empresa_id'));
    }

    #[Test]
    public function nao_se_define_como_activa_uma_empresa_sem_acesso_inexistente_ou_inactiva(): void
    {
        [$u, $e1] = $this->utilizadorComDuasEmpresas();
        $alheia = $this->criarEmpresa();
        $inactiva = $this->criarEmpresa(['estado' => Empresa::ESTADO_INATIVO]);
        $u->empresas()->attach($inactiva->id);
        $h = $this->h($this->tokenDe($u, 'A'));

        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $alheia->id], $h)->assertStatus(403)->assertJsonPath('codigo', 'EMPRESA_SEM_ACESSO');
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => 999999], $h)->assertStatus(403);
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $inactiva->id], $h)->assertStatus(403)->assertJsonPath('codigo', 'EMPRESA_INATIVA');
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => 'abc'], $h)->assertStatus(422);
        $this->assertNull(DB::table('tokens_acesso')->value('empresa_ativa_id'));
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $e1->id], $h)->assertOk();
    }

    #[Test]
    public function retirado_o_acesso_a_empresa_a_sessao_deixa_de_a_usar_e_o_eu_deixa_de_a_devolver(): void
    {
        [$u, $e1] = $this->utilizadorComDuasEmpresas();
        $h = $this->h($this->tokenDe($u, 'A'));
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $e1->id], $h)->assertOk();

        $u->empresas()->detach($e1->id);
        app(ServicoEmpresas::class)->invalidarUtilizador($u->id);

        $this->getJson('/api/sistema/menu', $h)->assertStatus(403);   // a validação de acesso corre sempre
        $this->assertNull($this->getJson('/api/autenticacao/eu', $h)->json('dados.empresa_ativa_id'));
    }

    #[Test]
    public function a_empresa_activa_nao_passa_de_uma_sessao_para_outra_nem_sobrevive_a_novo_login(): void
    {
        [$u, $e1] = $this->utilizadorComDuasEmpresas();
        $a = $this->h($this->tokenDe($u, 'A'));
        $b = $this->h($this->tokenDe($u, 'B'));
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $e1->id], $a)->assertOk();

        $this->assertNull($this->getJson('/api/autenticacao/eu', $b)->json('dados.empresa_ativa_id'), 'outro dispositivo: sessão própria');
        $a2 = $this->h($this->tokenDe($u, 'A'));   // novo login do mesmo dispositivo: sessão nova, sem empresa
        $this->assertNull($this->getJson('/api/autenticacao/eu', $a2)->json('dados.empresa_ativa_id'));
    }

    #[Test]
    public function a_empresa_activa_sobrevive_a_rotacao_do_token(): void
    {
        [$u, $e1] = $this->utilizadorComDuasEmpresas();
        $token = $this->tokenDe($u, 'A');
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => $e1->id], $this->h($token))->assertOk();

        $this->travel(11)->minutes();
        $r = $this->withCredentials()->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36')
            ->withUnencryptedCookie(config('erp.sessao.cookie_token'), $token)->getJson('/api/autenticacao/eu')->assertOk();
        $novo = $r->getCookie(config('erp.sessao.cookie_token'), false)->getValue();
        $this->assertNotSame($token, $novo);

        $this->assertSame($e1->id, $this->getJson('/api/autenticacao/eu', $this->h($novo))->json('dados.empresa_ativa_id'));
    }
}
