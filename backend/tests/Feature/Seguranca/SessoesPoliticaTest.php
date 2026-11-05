<?php

namespace Tests\Feature\Seguranca;

use App\Models\Empresa;
use App\Models\TokenAcesso;
use App\Models\Utilizador;
use App\Support\Autenticacao\CookiesSessao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-085 — política de sessão pedida em Implementar.txt §4: inactividade de 15 min medida só em pedidos legítimos
 * (polling/heartbeat não renovam), duração absoluta de 2 h sem renovação (a rotação do token não a reinicia), expiração
 * efectiva no servidor, estado da conta validado em cada pedido, logout global e controlo de sessões por empresa.
 */
final class SessoesPoliticaTest extends TestCase
{
    private const AUTOMATICO = ['X-Erp-Automatico' => '1'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function os_valores_da_politica_sao_15_minutos_de_inactividade_e_2_horas_absolutas(): void
    {
        $this->assertSame(15, config('erp.sessao.inatividade_minutos'));
        $this->assertSame(2, config('erp.sessao.validade_horas'));

        $u = $this->criarUtilizador();
        $this->entrar($u);
        $t = TokenAcesso::query()->firstOrFail();
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $t->expira_em->getTimestamp(), 5);
    }

    #[Test]
    public function um_pedido_automatico_nao_renova_a_inactividade_mas_um_do_utilizador_renova(): void
    {
        $u = $this->criarUtilizador();
        $s = $this->entrar($u);
        $inicio = TokenAcesso::query()->firstOrFail()->ultimo_uso_em;

        Carbon::setTestNow(now()->addMinutes(10));
        $this->getJson('/api/autenticacao/eu', $s + self::AUTOMATICO)->assertOk();
        $this->assertEquals($inicio?->getTimestamp(), TokenAcesso::query()->firstOrFail()->ultimo_uso_em?->getTimestamp(), 'o polling não pode mexer em ultimo_uso_em');

        // 16 min depois do último pedido LEGÍTIMO (o login): o polling a 10 min não prolongou nada
        Carbon::setTestNow(now()->addMinutes(6));
        $this->getJson('/api/autenticacao/eu', $s + self::AUTOMATICO)->assertStatus(401);
    }

    #[Test]
    public function um_pedido_do_utilizador_prolonga_a_inactividade(): void
    {
        $u = $this->criarUtilizador();
        $s = $this->entrar($u);

        Carbon::setTestNow(now()->addMinutes(10));
        $this->getJson('/api/autenticacao/eu', $s)->assertOk();          // actividade legítima a +10
        Carbon::setTestNow(now()->addMinutes(10));                       // +20 desde o login, +10 desde a actividade
        $this->getJson('/api/autenticacao/eu', $s + self::AUTOMATICO)->assertOk();
        Carbon::setTestNow(now()->addMinutes(6));                        // +16 desde a actividade legítima; o polling não ajudou
        $this->getJson('/api/autenticacao/eu', $s)->assertStatus(401);
    }

    #[Test]
    public function a_marca_de_automatico_so_vale_em_leituras(): void
    {
        $u = $this->criarUtilizador();
        $s = $this->entrar($u);

        Carbon::setTestNow(now()->addMinutes(10));
        // um PUT com a marca é acção do utilizador: renova na mesma
        $this->putJson('/api/autenticacao/empresa-activa', ['empresa_id' => null], $s + self::AUTOMATICO)->assertOk();
        Carbon::setTestNow(now()->addMinutes(10));
        $this->getJson('/api/autenticacao/eu', $s)->assertOk();
    }

    #[Test]
    public function a_duracao_absoluta_de_2_horas_corta_mesmo_com_actividade_constante(): void
    {
        $u = $this->criarUtilizador();
        $s = $this->entrar($u);

        for ($i = 0; $i < 11; $i++) {                                   // 11 × 10 min = 1 h 50 min, sempre activo
            Carbon::setTestNow(now()->addMinutes(10));
            $this->getJson('/api/autenticacao/eu', $s)->assertOk();
        }
        Carbon::setTestNow(now()->addMinutes(11));                      // 2 h 01 min no total, última actividade há 11 min
        $this->getJson('/api/autenticacao/eu', $s)->assertStatus(401);
    }

    #[Test]
    public function a_rotacao_do_token_nao_reinicia_o_limite_absoluto(): void
    {
        $u = $this->criarUtilizador();
        $login = $this->comDispositivo('rot')->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();
        $token = $this->tokenDoCookie($login);
        $expiraInicial = TokenAcesso::query()->firstOrFail()->expira_em->getTimestamp();

        // pedidos pelo cookie (como o SPA) a cada 11 min: o token roda várias vezes
        $rotacoes = 0;
        for ($i = 0; $i < 10; $i++) {
            Carbon::setTestNow(now()->addMinutes(11));
            $r = $this->withCredentials()->withUnencryptedCookie(CookiesSessao::nome('token'), $token)->getJson('/api/autenticacao/eu')->assertOk();
            $novo = $r->getCookie(CookiesSessao::nome('token'), false)?->getValue();
            if ($novo !== null && $novo !== $token) {
                $token = $novo;
                $rotacoes++;
            }
        }
        $this->assertGreaterThanOrEqual(3, $rotacoes);
        $this->assertSame($expiraInicial, TokenAcesso::query()->firstOrFail()->expira_em->getTimestamp(), 'a rotação nunca move o expira_em');

        Carbon::setTestNow(now()->addMinutes(11));                      // 2 h 01 min desde o login
        $this->withCredentials()->withUnencryptedCookie(CookiesSessao::nome('token'), $token)->getJson('/api/autenticacao/eu')->assertStatus(401);
    }

    #[Test]
    public function uma_sessao_emitida_antes_da_validade_curta_tambem_e_cortada_ao_fim_de_2_horas(): void
    {
        $u = $this->criarUtilizador();
        $s = $this->entrar($u);
        // simula uma sessão antiga, criada com a validade de 12 h: o `expira_em` está longe, mas já passaram 3 h
        DB::table('tokens_acesso')->update(['criado_em' => now()->subHours(3), 'expira_em' => now()->addHours(9), 'ultimo_uso_em' => now()]);

        $this->getJson('/api/autenticacao/eu', $s)->assertStatus(401);
    }

    #[Test]
    public function o_estado_da_conta_e_validado_em_cada_pedido(): void
    {
        $u = $this->criarUtilizador();
        $s = $this->entrar($u);
        $this->getJson('/api/autenticacao/eu', $s)->assertOk();

        DB::table('utilizadores')->where('id', $u->id)->update(['ativo' => false]);
        $this->getJson('/api/autenticacao/eu', $s)->assertStatus(401);

        $v = $this->criarUtilizador();
        $sv = $this->entrar($v);
        $v->delete();
        $this->getJson('/api/autenticacao/eu', $sv)->assertStatus(401);
    }

    #[Test]
    public function varias_sessoes_por_dispositivo_e_logout_global_termina_todas(): void
    {
        $u = $this->criarUtilizador();
        $a = $this->entrar($u, dispositivo: 'portatil');
        $b = $this->entrar($u, dispositivo: 'telemovel');
        $this->assertSame(2, DB::table('tokens_acesso')->where('portador_id', $u->id)->count());
        $this->getJson('/api/autenticacao/eu', $a)->assertOk();
        $this->getJson('/api/autenticacao/eu', $b)->assertOk();

        $r = $this->postJson('/api/autenticacao/sair-de-todas', [], $a)->assertOk();
        $this->assertSame(2, $r->json('dados.terminadas'));
        $this->assertSame(0, DB::table('tokens_acesso')->where('portador_id', $u->id)->count());
        $this->getJson('/api/autenticacao/eu', $b)->assertStatus(401);
    }

    // ---- controlo de sessões por empresa --------------------------------------------------------------------------

    private function administrador(Empresa $empresa): array
    {
        $admin = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true, 'config_util_gerir' => true], 'Gestor')->id]);
        $admin->empresas()->attach($empresa->id);

        return [$admin, $this->entrar($admin) + ['X-Empresa-Id' => $empresa->id]];
    }

    #[Test]
    public function o_administrador_ve_e_termina_as_sessoes_da_empresa_e_pode_bloquear_a_conta(): void
    {
        $empresa = $this->criarEmpresa();
        [$admin, $sa] = $this->administrador($empresa);
        $colega = $this->criarUtilizador();
        $colega->empresas()->attach($empresa->id);
        $this->entrar($colega, dispositivo: 'pc');
        $this->entrar($colega, dispositivo: 'tel');

        $lista = $this->getJson('/api/sistema/sessoes-empresa', $sa)->assertOk()->json('dados');
        $this->assertCount(3, $lista);                                   // admin + 2 do colega
        $this->assertStringNotContainsString('token', json_encode($lista));

        $this->getJson("/api/sistema/utilizadores/{$colega->id}/sessoes", $sa)->assertOk()->assertJsonCount(2, 'dados');
        $uma = $lista[0]['utilizador_id'] === $colega->id ? $lista[0]['id'] : $lista[1]['id'];
        $this->deleteJson("/api/sistema/utilizadores/{$colega->id}/sessoes", ['sessao_id' => $uma], $sa)->assertOk();
        $this->assertSame(1, DB::table('tokens_acesso')->where('portador_id', $colega->id)->count());

        $this->deleteJson("/api/sistema/utilizadores/{$colega->id}/sessoes", ['bloquear' => true], $sa)->assertOk()->assertJsonPath('dados.bloqueada', true);
        $this->assertSame(0, DB::table('tokens_acesso')->where('portador_id', $colega->id)->count());
        $this->assertFalse((bool) $colega->fresh()->ativo);
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $colega->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])->assertStatus(401);
    }

    #[Test]
    public function um_administrador_nao_controla_sessoes_de_utilizadores_de_outra_empresa(): void
    {
        $a = $this->criarEmpresa();
        $b = $this->criarEmpresa();
        [, $sa] = $this->administrador($a);
        $alheio = $this->criarUtilizador();
        $alheio->empresas()->attach($b->id);
        $this->entrar($alheio);

        $this->getJson("/api/sistema/utilizadores/{$alheio->id}/sessoes", $sa)->assertStatus(404);
        $this->deleteJson("/api/sistema/utilizadores/{$alheio->id}/sessoes", [], $sa)->assertStatus(404);
        $this->assertSame(1, DB::table('tokens_acesso')->where('portador_id', $alheio->id)->count());
        $lista = $this->getJson('/api/sistema/sessoes-empresa', $sa)->assertOk()->json('dados');
        $this->assertNotContains($alheio->id, array_column($lista, 'utilizador_id'));
    }

    #[Test]
    public function sem_permissao_de_gestao_nao_se_termina_sessoes_alheias(): void
    {
        $empresa = $this->criarEmpresa();
        $leitor = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true], 'Leitor')->id]);
        $leitor->empresas()->attach($empresa->id);
        $s = $this->entrar($leitor) + ['X-Empresa-Id' => $empresa->id];
        $colega = $this->criarUtilizador();
        $colega->empresas()->attach($empresa->id);
        $this->entrar($colega);

        $this->deleteJson("/api/sistema/utilizadores/{$colega->id}/sessoes", [], $s)->assertStatus(403);
        $this->assertInstanceOf(Utilizador::class, $colega->fresh());
        $this->assertSame(1, DB::table('tokens_acesso')->where('portador_id', $colega->id)->count());
    }
}
