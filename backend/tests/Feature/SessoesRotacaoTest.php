<?php

namespace Tests\Feature;

use App\Mail\AvisoSessaoMail;
use App\Models\LogAuditoria;
use App\Models\TokenAcesso;
use App\Models\Utilizador;
use App\Services\Autenticacao\ServicoSessoes;
use App\Support\Autenticacao\CookiesSessao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-082: tokens únicos por dispositivo, rotação com tolerância, detecção de reutilização, vinculação ao navegador,
 * limite de sessões, cookies (HttpOnly/Secure/SameSite/__Host-) e CSRF ligado à sessão + verificação de origem.
 */
final class SessoesRotacaoTest extends TestCase
{
    private const CHROME_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    private const FIREFOX_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';

    private function login(Utilizador $u, ?string $dispositivo = null, string $ua = self::CHROME_WIN, string $url = '/api/autenticacao/entrar'): TestResponse
    {
        $base = $dispositivo !== null ? $this->comDispositivo($dispositivo) : $this->withCredentials();

        return $base->withHeader('User-Agent', $ua)->postJson($url, ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();
    }

    private function segredo(string $token): string
    {
        return explode('|', $token, 2)[1];
    }

    /** Pedido autenticado só pelo cookie HttpOnly (como o SPA), com o cookie CSRF quando pedido. */
    private function porCookie(string $token, ?string $csrf = null, string $ua = self::CHROME_WIN): static
    {
        $t = $this->withCredentials()->withHeader('User-Agent', $ua)->withUnencryptedCookie(CookiesSessao::nome('token'), $token);
        if ($csrf !== null) {
            $t = $t->withUnencryptedCookie(CookiesSessao::nome('csrf'), $csrf)->withHeader(config('erp.sessao.cabecalho_csrf'), $csrf);
        }

        return $t;
    }

    private function cookie(TestResponse $r, string $qual)
    {
        return $r->getCookie(CookiesSessao::nome($qual), false);
    }

    // ---- 1. tokens ---------------------------------------------------------------------------------------------------

    #[Test]
    public function o_token_tem_256_bits_e_so_o_hash_fica_na_base(): void
    {
        $u = $this->criarUtilizador();
        $token = $this->tokenDoCookie($this->login($u));
        $segredo = $this->segredo($token);

        $this->assertSame(43, strlen($segredo));                                   // 32 bytes em base64url = 256 bits
        $this->assertSame(32, strlen((string) base64_decode(strtr($segredo, '-_', '+/').'=')));
        $linha = DB::table('tokens_acesso')->first();
        $this->assertSame(hash('sha256', $segredo), $linha->token);
        $this->assertStringNotContainsString($segredo, json_encode($linha));
    }

    #[Test]
    public function cada_login_emite_um_token_diferente_e_nenhum_se_repete(): void
    {
        $u = $this->criarUtilizador();
        $tokens = [];
        foreach (['a', 'b', 'c', 'a', 'b'] as $d) {
            $tokens[] = $this->tokenDoCookie($this->login($u, $d));
        }
        $this->assertCount(5, array_unique($tokens));
        $this->assertSame(3, DB::table('tokens_acesso')->where('portador_id', $u->id)->count());   // 3 dispositivos, 1 token cada
    }

    #[Test]
    public function o_servidor_emite_o_cookie_do_dispositivo_e_ignora_o_que_o_cliente_escolhe(): void
    {
        $u = $this->criarUtilizador();
        $r = $this->withCredentials()->postJson('/api/autenticacao/entrar', [
            'nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026', 'dispositivo' => 'escolhido-pelo-cliente',
        ])->assertOk();

        $dev = $this->cookie($r, 'dispositivo');
        $this->assertNotNull($dev);
        $this->assertTrue($dev->isHttpOnly());
        $this->assertSame('strict', $dev->getSameSite());
        $this->assertSame('/', $dev->getPath());
        $this->assertNull($dev->getDomain());
        $id = CookiesSessao::verificarDispositivo($dev->getValue());
        $this->assertNotNull($id, 'o cookie leva identificador e assinatura do servidor');
        $this->assertSame($id, DB::table('tokens_acesso')->value('dispositivo_id'));
        $this->assertNotSame('escolhido-pelo-cliente', DB::table('tokens_acesso')->value('dispositivo_id'));
        // 180 dias por omissão
        $this->assertEqualsWithDelta(now()->addDays(180)->getTimestamp(), $dev->getExpiresTime(), 120);
    }

    #[Test]
    public function cookie_de_dispositivo_forjado_ou_adulterado_equivale_a_nao_ter_cookie(): void
    {
        $u = $this->criarUtilizador();
        foreach (['abcdefghijklmnopqrstuv.assinatura-falsa', 'abcdefghijklmnopqrstuv', 'lixo', CookiesSessao::assinarDispositivo('abcdefghijklmnopqrstuv').'x'] as $forjado) {
            $r = $this->withCredentials()->withUnencryptedCookie(CookiesSessao::nome('dispositivo'), $forjado)->postJson('/api/autenticacao/entrar', [
                'nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026',
            ])->assertOk();
            $novo = CookiesSessao::verificarDispositivo($this->cookie($r, 'dispositivo')->getValue());
            $this->assertNotSame('abcdefghijklmnopqrstuv', $novo);
        }
    }

    #[Test]
    public function mesmo_navegador_reutiliza_o_dispositivo_e_so_substitui_a_sua_propria_sessao(): void
    {
        $u = $this->criarUtilizador();
        $a1 = $this->tokenDoCookie($this->login($u, 'A'));
        $b1 = $this->tokenDoCookie($this->login($u, 'B'));
        $a2 = $this->tokenDoCookie($this->login($u, 'A'));

        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$a1])->assertStatus(401);   // substituída
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$b1])->assertOk();           // outro dispositivo: intacta
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$a2])->assertOk();
    }

    #[Test]
    public function token_sem_formato_id_barra_segredo_ou_com_segredo_alterado_e_recusado(): void
    {
        $u = $this->criarUtilizador();
        $token = $this->tokenDoCookie($this->login($u));
        [$id, $segredo] = explode('|', $token, 2);

        foreach ([$segredo, $id.'|'.strrev($segredo), $id.'|', '0|'.$segredo, 'x|'.$segredo] as $mau) {
            $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$mau])->assertStatus(401);
        }
        // um token sem prefixo de id nunca é procurado só pelo hash (comportamento por omissão do Sanctum)
        $this->assertNull(TokenAcesso::findToken(hash('sha256', $segredo)));
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    // ---- rotação e detecção de reutilização ----------------------------------------------------------------------

    #[Test]
    public function antes_do_intervalo_nao_ha_rotacao_e_depois_o_pedido_recebe_cookie_novo(): void
    {
        $u = $this->criarUtilizador();
        $t1 = $this->tokenDoCookie($this->login($u));

        $r = $this->porCookie($t1)->getJson('/api/autenticacao/eu')->assertOk();
        $this->assertNull($this->cookie($r, 'token'), 'dentro do intervalo, o cookie não muda');

        $this->travel(11)->minutes();
        $r = $this->porCookie($t1)->getJson('/api/autenticacao/eu')->assertOk();
        $novo = $this->cookie($r, 'token');
        $this->assertNotNull($novo);
        $this->assertNotSame($t1, $novo->getValue());
        $this->assertSame(explode('|', $t1)[0], explode('|', $novo->getValue())[0], 'a mesma linha de sessão');
        $this->assertTrue($novo->isHttpOnly());
        $this->assertSame('strict', $novo->getSameSite());
        $linha = DB::table('tokens_acesso')->first();
        $this->assertSame(hash('sha256', $this->segredo($novo->getValue())), $linha->token);
        $this->assertCount(1, json_decode($linha->tokens_anteriores, true));
        $this->assertStringNotContainsString($this->segredo($t1), $linha->tokens_anteriores, 'só o hash do anterior');
        // o cookie novo funciona, e logo a seguir não volta a rodar
        $r2 = $this->porCookie($novo->getValue())->getJson('/api/autenticacao/eu')->assertOk();
        $this->assertNull($this->cookie($r2, 'token'));
    }

    #[Test]
    public function a_rotacao_nao_acontece_com_authorization_bearer(): void
    {
        $u = $this->criarUtilizador();
        $t1 = $this->tokenDoCookie($this->login($u));
        $this->travel(11)->minutes();
        $r = $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$t1])->assertOk();
        $this->assertNull($this->cookie($r, 'token'));
    }

    #[Test]
    public function token_rodado_ainda_serve_dentro_da_tolerancia_para_pedidos_paralelos(): void
    {
        $u = $this->criarUtilizador();
        $t1 = $this->tokenDoCookie($this->login($u));
        $this->travel(11)->minutes();
        $this->porCookie($t1)->getJson('/api/autenticacao/eu')->assertOk();   // roda

        // pedido em voo com o token antigo, 20 s depois: aceite, sem nova rotação
        $this->travel(20)->seconds();
        $r = $this->porCookie($t1)->getJson('/api/autenticacao/eu')->assertOk();
        $this->assertNull($this->cookie($r, 'token'));
        $this->assertCount(1, json_decode(DB::table('tokens_acesso')->value('tokens_anteriores'), true));
    }

    #[Test]
    public function reutilizar_um_token_rodado_fora_da_tolerancia_revoga_o_dispositivo_regista_e_avisa(): void
    {
        Mail::fake();
        $u = $this->criarUtilizador(['email' => 'titular@exemplo.ao', 'nome_completo' => 'Titular Teste']);
        $a1 = $this->tokenDoCookie($this->login($u, 'A'));
        $b1 = $this->tokenDoCookie($this->login($u, 'B'));
        $this->travel(11)->minutes();
        $r = $this->porCookie($a1)->getJson('/api/autenticacao/eu')->assertOk();
        $a2 = $this->cookie($r, 'token')->getValue();

        $this->travel(61)->seconds();
        // o atacante (ou o perfil copiado) apresenta o token antigo
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$a1])->assertStatus(401);

        // a «família» (dispositivo A) fica revogada, incluindo o token novo; o dispositivo B não é afectado
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$a2])->assertStatus(401);
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$b1])->assertOk();
        $this->assertSame(1, DB::table('tokens_acesso')->where('portador_id', $u->id)->count());

        $this->assertTrue(LogAuditoria::withoutGlobalScopes()->where('acao', 'Reutilização de token detectada')->where('utilizador_id', $u->id)->exists());
        Mail::assertQueued(AvisoSessaoMail::class, fn (AvisoSessaoMail $m) => $m->hasTo('titular@exemplo.ao'));
    }

    #[Test]
    public function a_rotacao_e_atomica_dois_pedidos_em_paralelo_so_rodam_uma_vez(): void
    {
        $u = $this->criarUtilizador();
        $this->login($u);
        $token = TokenAcesso::query()->first();
        $copia = TokenAcesso::query()->first();   // o segundo pedido leu a mesma linha

        $servico = app(ServicoSessoes::class);
        $this->assertNotNull($servico->rotacionar($token));
        $this->assertNull($servico->rotacionar($copia), 'o segundo perdeu a corrida: não há dois tokens válidos');
        $this->assertCount(1, json_decode(DB::table('tokens_acesso')->value('tokens_anteriores'), true));
    }

    // ---- vinculação, IP, limite ----------------------------------------------------------------------------------

    #[Test]
    public function mudanca_de_navegador_termina_a_sessao_mas_actualizacao_de_versao_nao(): void
    {
        $u = $this->criarUtilizador();
        $t = $this->tokenDoCookie($this->login($u));

        $this->withHeader('User-Agent', str_replace('130.0', '131.0', self::CHROME_WIN))->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$t])->assertOk();
        $this->withHeader('User-Agent', self::FIREFOX_LINUX)->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$t])->assertStatus(401);
        $this->assertSame(0, DB::table('tokens_acesso')->count());
        $this->assertTrue(LogAuditoria::withoutGlobalScopes()->where('acao', 'Sessão terminada: navegador diferente')->exists());
    }

    #[Test]
    public function mudanca_de_ip_so_gera_auditoria_e_nunca_termina_a_sessao(): void
    {
        $u = $this->criarUtilizador();
        $t = $this->tokenDoCookie($this->withServerVariables(['REMOTE_ADDR' => '41.1.2.3'])->withHeader('User-Agent', self::CHROME_WIN)
            ->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])->assertOk());

        $this->withServerVariables(['REMOTE_ADDR' => '102.9.8.7'])->withHeader('User-Agent', self::CHROME_WIN)
            ->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$t])->assertOk();

        $this->assertSame('102.9.8.7', DB::table('tokens_acesso')->value('endereco_ip'));
        $this->assertTrue(LogAuditoria::withoutGlobalScopes()->where('acao', 'Mudança de IP da sessão')->exists());
    }

    #[Test]
    public function o_limite_de_sessoes_por_utilizador_termina_a_mais_antiga(): void
    {
        config(['erp.sessao.max_sessoes_por_utilizador' => 3]);
        $u = $this->criarUtilizador();
        $tokens = [];
        foreach (['d1', 'd2', 'd3', 'd4'] as $d) {
            $tokens[$d] = $this->tokenDoCookie($this->login($u, $d));
            $this->travel(1)->seconds();
        }

        $this->assertSame(3, DB::table('tokens_acesso')->where('portador_id', $u->id)->count());
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$tokens['d1']])->assertStatus(401);
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$tokens['d4']])->assertOk();
    }

    #[Test]
    public function logout_revoga_no_servidor_e_apaga_os_cookies_de_sessao_mas_nao_o_do_dispositivo(): void
    {
        $u = $this->criarUtilizador();
        $t = $this->tokenDoCookie($this->login($u));
        $r = $this->postJson('/api/autenticacao/sair', [], ['Authorization' => 'Bearer '.$t])->assertOk();

        $this->assertSame(0, DB::table('tokens_acesso')->count());
        foreach (['token', 'csrf'] as $qual) {
            $this->assertLessThan(time(), $this->cookie($r, $qual)->getExpiresTime());
        }
        $this->assertNull($this->cookie($r, 'dispositivo'));
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$t])->assertStatus(401);
    }

    #[Test]
    public function alterar_a_palavra_passe_revoga_as_outras_sessoes_e_mantem_a_actual(): void
    {
        $u = $this->criarUtilizador();
        $a = $this->tokenDoCookie($this->login($u, 'A'));
        $b = $this->tokenDoCookie($this->login($u, 'B'));

        $this->putJson('/api/autenticacao/palavra-passe', ['palavra_passe_actual' => 'Palavra#Passe2026', 'palavra_passe_nova' => 'Nova#Palavra2027', 'palavra_passe_nova_confirmation' => 'Nova#Palavra2027'],
            ['Authorization' => 'Bearer '.$a])->assertOk();

        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$a])->assertOk();
        $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer '.$b])->assertStatus(401);
    }

    #[Test]
    public function primeiro_dispositivo_nao_avisa_mas_um_dispositivo_novo_avisa_e_um_conhecido_nao(): void
    {
        Mail::fake();
        $u = $this->criarUtilizador(['email' => 'titular@exemplo.ao']);
        $this->login($u, 'A');
        Mail::assertNotQueued(AvisoSessaoMail::class);

        $this->login($u, 'B');
        Mail::assertQueued(AvisoSessaoMail::class, 1);
        $this->assertTrue(LogAuditoria::withoutGlobalScopes()->where('acao', 'Novo dispositivo')->exists());

        $this->login($u, 'B');   // já conhecido
        Mail::assertQueued(AvisoSessaoMail::class, 1);
    }

    // ---- 2. cookies ------------------------------------------------------------------------------------------------

    #[Test]
    public function em_http_os_cookies_nao_levam_secure_nem_prefixo_mas_levam_httponly_e_samesite_strict(): void
    {
        $r = $this->login($this->criarUtilizador());
        $token = $r->getCookie('erp_token', false);
        $this->assertNotNull($token);
        $this->assertFalse($token->isSecure());
        $this->assertTrue($token->isHttpOnly());
        $this->assertSame('strict', $token->getSameSite());
        $this->assertSame('/', $token->getPath());
        $this->assertNull($token->getDomain());
        // validade alinhada com a expiração absoluta da sessão (2 h, ADR-085)
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $token->getExpiresTime(), 120);
        $csrf = $r->getCookie('XSRF-TOKEN', false);
        $this->assertFalse($csrf->isHttpOnly());   // o único legível por JavaScript
        $this->assertSame('strict', $csrf->getSameSite());
    }

    #[Test]
    public function em_https_os_cookies_levam_secure_e_prefixo_host_sem_domain(): void
    {
        $u = $this->criarUtilizador();
        $r = $this->withHeader('User-Agent', self::CHROME_WIN)->postJson('https://localhost/api/autenticacao/entrar', [
            'nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026',
        ])->assertOk();

        foreach (['__Host-erp_token' => true, '__Host-erp_dev' => true, '__Host-XSRF-TOKEN' => false] as $nome => $httpOnly) {
            $c = $r->getCookie($nome, false);
            $this->assertNotNull($c, $nome);
            $this->assertTrue($c->isSecure(), $nome);
            $this->assertSame($httpOnly, $c->isHttpOnly(), $nome);
            $this->assertSame('/', $c->getPath(), $nome);
            $this->assertNull($c->getDomain(), $nome);   // exigência do prefixo __Host-
        }
        $this->assertNull($r->getCookie('erp_token', false), 'em HTTPS só o nome com prefixo');
    }

    #[Test]
    public function cada_anfitriao_tem_os_seus_cookies_sem_domain_largo_por_cliente_e_portal(): void
    {
        $u = $this->criarUtilizador();
        foreach (['https://acme.meuerp.test', 'https://portal.meuerp.test'] as $host) {
            $r = $this->postJson($host.'/api/autenticacao/entrar', ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026']);
            foreach ($r->headers->getCookies() as $c) {
                $this->assertNull($c->getDomain(), "{$host}: {$c->getName()} sem Domain");
                $this->assertStringStartsWith('__Host-', $c->getName());
            }
        }
    }

    #[Test]
    public function respostas_de_autenticacao_e_dados_do_utilizador_nunca_ficam_em_cache(): void
    {
        $u = $this->criarUtilizador();
        $login = $this->login($u);
        $t = ['Authorization' => 'Bearer '.$this->tokenDoCookie($login)];

        $respostas = [$login, $this->getJson('/api/autenticacao/eu', $t), $this->getJson('/api/autenticacao/sessoes', $t),
            $this->getJson('/api/autenticacao/eu', ['Authorization' => 'Bearer invalido'])];   // até os erros
        foreach ($respostas as $r) {
            $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
            $this->assertSame('no-cache', $r->headers->get('Pragma'));
        }
    }

    #[Test]
    public function a_cache_condicional_do_logotipo_publico_nao_e_afectada_pelo_no_store(): void
    {
        $r = $this->getJson('/api/sistema/logotipo-login')->assertOk();
        $this->assertStringContainsString('public', (string) $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertNotNull($r->headers->get('ETag'));
        $this->getJson('/api/sistema/logotipo-login', ['If-None-Match' => $r->headers->get('ETag')])->assertStatus(304);
    }

    // ---- CSRF ligado à sessão e origem -----------------------------------------------------------------------------

    #[Test]
    public function csrf_plantado_com_cabecalho_igual_mas_sem_hmac_da_sessao_e_rejeitado(): void
    {
        $u = $this->criarUtilizador();
        $login = $this->login($u);
        $t = $this->tokenDoCookie($login);
        $legitimo = $this->cookie($login, 'csrf')->getValue();
        $csrfAlheio = $this->cookie($this->login($this->criarUtilizador()), 'csrf')->getValue();   // antes de os cookies ficarem no cliente de teste

        // cookie e cabeçalho iguais, mas inventados (o que um sub-domínio conseguiria plantar)
        $this->porCookie($t, 'valor-plantado-por-um-subdominio')->postJson('/api/autenticacao/sessoes/terminar-outras')
            ->assertStatus(419)->assertJsonPath('codigo', 'CSRF_INVALIDO');
        // um CSRF legítimo de OUTRA sessão também não serve
        $this->porCookie($t, $csrfAlheio)->postJson('/api/autenticacao/sessoes/terminar-outras')->assertStatus(419);
        // o legítimo passa
        $this->porCookie($t, $legitimo)->postJson('/api/autenticacao/sessoes/terminar-outras')->assertOk();
    }

    #[Test]
    public function pedidos_que_alteram_estado_de_outro_site_sao_rejeitados_pela_origem(): void
    {
        $login = $this->login($this->criarUtilizador());
        $t = $this->tokenDoCookie($login);
        $csrf = $this->cookie($login, 'csrf')->getValue();
        $url = '/api/autenticacao/sessoes/terminar-outras';

        $this->porCookie($t, $csrf)->withHeader('Origin', 'https://site-malicioso.example')->postJson($url)->assertStatus(403)->assertJsonPath('codigo', 'ORIGEM_INVALIDA');
        $this->porCookie($t, $csrf)->withHeader('Origin', 'null')->postJson($url)->assertStatus(403);
        $this->porCookie($t, $csrf)->withHeader('Sec-Fetch-Site', 'cross-site')->postJson($url)->assertStatus(403);
        // sub-domínio vizinho NÃO é aceite (cada cliente fala com a API no seu próprio anfitrião)
        $this->porCookie($t, $csrf)->withHeader('Origin', 'https://vizinho.localhost')->postJson($url)->assertStatus(403);

        $propria = rtrim((string) config('app.url'), '/');   // o anfitrião dos pedidos de teste
        $this->porCookie($t, $csrf)->withHeader('Origin', $propria)->withHeader('Sec-Fetch-Site', 'same-origin')->postJson($url)->assertOk();
        // origem extra configurada
        config(['erp.sessao.origens_permitidas' => 'https://app.parceiro.example']);
        $this->porCookie($t, $csrf)->withHeader('Origin', 'https://app.parceiro.example')->postJson($url)->assertOk();
    }

    #[Test]
    public function a_verificacao_de_origem_pode_ser_desligada_por_configuracao(): void
    {
        config(['erp.sessao.verificar_origem' => false]);
        $login = $this->login($this->criarUtilizador());
        $this->porCookie($this->tokenDoCookie($login), $this->cookie($login, 'csrf')->getValue())
            ->withHeader('Origin', 'https://qualquer.example')->postJson('/api/autenticacao/sessoes/terminar-outras')->assertOk();
    }
}
