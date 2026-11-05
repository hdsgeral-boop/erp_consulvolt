<?php

namespace Tests\Feature\Seguranca;

use App\Models\Empresa;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use App\Services\Autenticacao\ServicoTotp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-085 — step-up (Implementar.txt §4, MFA em operações críticas): `mfa_recente` devolve 403 MFA_REQUERIDO quando a sessão
 * não tem autenticação forte recente; `POST /autenticacao/reautenticar` (código MFA de uso único, ou palavra-passe sem MFA)
 * reabre a janela; só a própria sessão fica reautenticada.
 */
final class MfaStepUpTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
    }

    private function gestor(): array
    {
        $u = $this->criarUtilizador(['email' => 'gestor@teste.ao', 'perfil_utilizador_id' => $this->criarPerfil(['_v2' => true,
            'config_utilizadores_view' => true, 'config_util_gerir' => true, 'config_perfis_view' => true, 'config_perfis_gerir' => true], 'Gestor')->id]);
        $u->empresas()->attach($this->empresa->id);

        return [$u, $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id]];
    }

    /** A sessão passa a ter 20 min (a janela é 15), mas continua dentro da inactividade e da validade absoluta. */
    private function envelhecerSessao(): void
    {
        DB::table('tokens_acesso')->update(['criado_em' => now()->subMinutes(20), 'ultimo_uso_em' => now()]);
    }

    private function codigo(string $segredo, int $desvio = 0): string
    {
        $totp = app(ServicoTotp::class);
        $m = new \ReflectionMethod($totp, 'gerarCodigo');

        return $m->invoke($totp, $segredo, intdiv(time(), 30) + $desvio);
    }

    private function activarMfa(Utilizador $u): string
    {
        $segredo = app(ServicoTotp::class)->gerarSegredo();
        $u->forceFill(['mfa_segredo' => $segredo, 'mfa_ativo' => true, 'mfa_ativado_em' => now(), 'mfa_codigos_recuperacao' => [Hash::make('ABCD-EFGH')]])->save();

        return $segredo;
    }

    private function novoUtilizadorPayload(): array
    {
        $perfil = PerfilUtilizador::query()->where('nome', 'Operador')->first() ?? $this->criarPerfil(['_v2' => true, 'config_utilizadores_view' => true], 'Operador');

        return ['nome_utilizador' => 'novo'.random_int(1000, 9999), 'nome_completo' => 'Novo Utilizador', 'email' => 'novo'.random_int(1000, 9999).'@teste.ao',
            'perfil_utilizador_id' => $perfil->id, 'empresas' => [['empresa_id' => $this->empresa->id]]];
    }

    #[Test]
    public function logo_apos_o_login_a_operacao_critica_passa_e_depois_da_janela_exige_confirmacao(): void
    {
        [, $s] = $this->gestor();
        $this->postJson('/api/sistema/utilizadores', $this->novoUtilizadorPayload(), $s)->assertCreated();

        $this->envelhecerSessao();
        $r = $this->postJson('/api/sistema/utilizadores', $this->novoUtilizadorPayload(), $s)->assertStatus(403);
        $r->assertJsonPath('codigo', 'MFA_REQUERIDO')->assertJsonPath('erros.metodo', 'palavra_passe');

        // as leituras do mesmo ecrã não são bloqueadas
        $this->getJson('/api/sistema/utilizadores', $s)->assertOk();
    }

    #[Test]
    public function reautenticar_com_a_palavra_passe_reabre_a_janela_so_nesta_sessao(): void
    {
        [$u, $s] = $this->gestor();
        $outra = $this->entrar($u, dispositivo: 'outro') + ['X-Empresa-Id' => $this->empresa->id];
        $this->envelhecerSessao();

        $this->postJson('/api/autenticacao/reautenticar', ['palavra_passe' => 'errada'], $s)->assertStatus(422)->assertJsonPath('codigo', 'PALAVRA_PASSE_INCORRETA');
        $this->postJson('/api/sistema/utilizadores', $this->novoUtilizadorPayload(), $s)->assertStatus(403);

        $this->postJson('/api/autenticacao/reautenticar', ['palavra_passe' => 'Palavra#Passe2026'], $s)->assertOk();
        $this->postJson('/api/sistema/utilizadores', $this->novoUtilizadorPayload(), $s)->assertCreated();
        // a outra sessão (outro dispositivo) continua por reautenticar
        $this->postJson('/api/sistema/utilizadores', $this->novoUtilizadorPayload(), $outra)->assertStatus(403)->assertJsonPath('codigo', 'MFA_REQUERIDO');
    }

    #[Test]
    public function com_mfa_activo_a_reautenticacao_e_um_codigo_de_uso_unico(): void
    {
        [$u, $s] = $this->gestor();
        $segredo = $this->activarMfa($u);
        $this->envelhecerSessao();

        $this->postJson('/api/sistema/perfis', ['nome' => 'X', 'permissoes' => []], $s)->assertStatus(403)->assertJsonPath('erros.metodo', 'codigo_mfa');
        $this->postJson('/api/autenticacao/reautenticar', ['codigo' => '000000'], $s)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_INVALIDO');
        // a palavra-passe sozinha NÃO serve quando há MFA
        $this->postJson('/api/autenticacao/reautenticar', ['palavra_passe' => 'Palavra#Passe2026'], $s)->assertStatus(422);

        $codigo = $this->codigo($segredo);
        $this->postJson('/api/autenticacao/reautenticar', ['codigo' => $codigo], $s)->assertOk();

        // repetir o MESMO código (replay) é recusado
        $this->envelhecerSessao();
        $this->postJson('/api/autenticacao/reautenticar', ['codigo' => $codigo], $s)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_INVALIDO');
        // o passo seguinte (outro código válido na janela ±1) serve
        $this->postJson('/api/autenticacao/reautenticar', ['codigo' => $this->codigo($segredo, 1)], $s)->assertOk();
    }

    #[Test]
    public function o_codigo_gasto_no_login_nao_serve_para_reautenticar(): void
    {
        [$u] = $this->gestor();
        $segredo = $this->activarMfa($u);
        $login = $this->comDispositivo('mfa')->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])->assertOk()->json('dados');
        $codigo = $this->codigo($segredo);
        $r = $this->comDispositivo('mfa')->postJson('/api/autenticacao/mfa/verificar', ['codigo' => $codigo], ['Authorization' => 'Bearer '.$login['token_mfa']])->assertOk();
        $sessao = ['Authorization' => 'Bearer '.$this->tokenDoCookie($r)];
        $this->envelhecerSessao();

        $this->postJson('/api/autenticacao/reautenticar', ['codigo' => $codigo], $sessao)->assertStatus(422);
        // e o mesmo código também não entra duas vezes no login
        $login2 = $this->comDispositivo('mfa2')->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $u->nome_utilizador, 'palavra_passe' => 'Palavra#Passe2026'])->assertOk()->json('dados');
        $this->comDispositivo('mfa2')->postJson('/api/autenticacao/mfa/verificar', ['codigo' => $codigo], ['Authorization' => 'Bearer '.$login2['token_mfa']])->assertStatus(422);
    }

    #[Test]
    public function um_codigo_de_recuperacao_tambem_reautentica_e_gasta_se(): void
    {
        [$u, $s] = $this->gestor();
        $this->activarMfa($u);
        $this->envelhecerSessao();

        $this->postJson('/api/autenticacao/reautenticar', ['codigo' => 'ABCD-EFGH'], $s)->assertOk();
        $this->assertSame([], $u->fresh()->mfa_codigos_recuperacao);
        $this->envelhecerSessao();
        $this->postJson('/api/autenticacao/reautenticar', ['codigo' => 'ABCD-EFGH'], $s)->assertStatus(422);
    }

    #[Test]
    public function mudar_o_email_a_palavra_passe_e_o_mfa_exigem_autenticacao_recente(): void
    {
        [$u, $s] = $this->gestor();
        $this->envelhecerSessao();

        $dados = ['nome_completo' => 'Gestor', 'email' => 'outro@teste.ao'];
        $this->putJson('/api/autenticacao/dados-pessoais', $dados, $s)->assertStatus(403)->assertJsonPath('codigo', 'MFA_REQUERIDO');
        // o mesmo e-mail (só o nome) não é crítico
        $this->putJson('/api/autenticacao/dados-pessoais', ['nome_completo' => 'Gestor B', 'email' => 'gestor@teste.ao'], $s)->assertOk();
        $this->postJson('/api/autenticacao/mfa/iniciar', [], $s)->assertStatus(403)->assertJsonPath('codigo', 'MFA_REQUERIDO');
        $this->putJson('/api/autenticacao/palavra-passe', ['palavra_passe_actual' => 'Palavra#Passe2026', 'palavra_passe_nova' => 'Outra#Palavra2027', 'palavra_passe_nova_confirmation' => 'Outra#Palavra2027'], $s)
            ->assertStatus(403)->assertJsonPath('codigo', 'MFA_REQUERIDO');

        $this->postJson('/api/autenticacao/reautenticar', ['palavra_passe' => 'Palavra#Passe2026'], $s)->assertOk();
        $this->putJson('/api/autenticacao/dados-pessoais', $dados, $s)->assertOk();
        $this->assertSame('outro@teste.ao', $u->fresh()->email);
    }

    #[Test]
    public function exportacoes_em_massa_e_integracoes_exigem_autenticacao_recente(): void
    {
        [, $s] = $this->gestor();
        $this->envelhecerSessao();

        $this->getJson('/api/sistema/copias/exportar', $s)->assertStatus(403)->assertJsonPath('codigo', 'MFA_REQUERIDO');
        $this->postJson('/api/sistema/bi/tokens', ['nome' => 'x'], $s)->assertStatus(403)->assertJsonPath('codigo', 'MFA_REQUERIDO');
        $this->postJson('/api/vendas/faturacao-eletronica/ligacao-empresa/webhook', [], $s)->assertStatus(403)->assertJsonPath('codigo', 'MFA_REQUERIDO');
    }

    #[Test]
    public function toda_a_rota_critica_da_plataforma_exige_autenticacao_recente_excepto_o_suporte_diario(): void
    {
        $roteador = app('router');
        $criticas = 0;
        foreach ($roteador->getRoutes() as $rota) {
            $uri = $rota->uri();
            if (! str_starts_with($uri, 'api/plataforma/') || in_array('GET', $rota->methods(), true)) {
                continue;
            }
            $nome = (string) $rota->getName();
            $mw = implode(',', $rota->gatherMiddleware());
            if (str_starts_with($nome, 'plataforma.suporte.') || str_contains($uri, 'plataforma-inicial')) {
                continue;
            }
            $this->assertStringContainsString('mfa_recente', $mw, "{$uri} ({$nome}) devia exigir mfa_recente");
            $criticas++;
        }
        $this->assertGreaterThan(40, $criticas);
    }

    #[Test]
    public function o_interruptor_do_step_up_desliga_e_volta_a_ligar(): void
    {
        [, $s] = $this->gestor();
        $this->envelhecerSessao();
        config(['erp.sessao.stepup_ativo' => false]);
        $this->postJson('/api/sistema/utilizadores', $this->novoUtilizadorPayload(), $s)->assertCreated();

        config(['erp.sessao.stepup_ativo' => true]);
        $this->postJson('/api/sistema/utilizadores', $this->novoUtilizadorPayload(), $s)->assertStatus(403)->assertJsonPath('codigo', 'MFA_REQUERIDO');
    }
}
