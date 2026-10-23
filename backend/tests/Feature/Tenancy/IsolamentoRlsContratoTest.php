<?php

namespace Tests\Feature\Tenancy;

use App\Models\TokenAcesso;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SaaS fase 2 (ADR-073). Contrato: nenhuma tabela com `empresa_id` fica sem política RLS. E prova real do isolamento:
 * a ligação de teste é superuser (ignora a RLS); aqui muda-se para a role `erp_app` (`SET ROLE`), igual à da produção.
 */
class IsolamentoRlsContratoTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        parent::tearDown();
    }

    public function test_nenhuma_tabela_com_empresa_id_fica_sem_politica(): void
    {
        $faltam = (new IsolamentoRls(DB::connection()))->tabelasSemPolitica();

        $this->assertSame(
            [],
            $faltam,
            'Tabelas com empresa_id sem RLS: '.implode(', ', $faltam)
            .'. A migração que as criou deve chamar (new IsolamentoRls(DB::connection()))->aplicarATodas() no fim.',
        );
    }

    public function test_role_da_aplicacao_nao_ignora_a_rls_nem_e_superuser(): void
    {
        $r = DB::selectOne("SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'erp_app'");

        $this->assertNotNull($r);
        $this->assertFalse($r->rolsuper);
        $this->assertFalse($r->rolbypassrls);
    }

    public function test_cada_tenant_so_ve_e_escreve_os_seus_dados(): void
    {
        $empresaA = $this->criarEmpresa();
        $empresaB = $this->criarEmpresa();
        $outro = Tenant::query()->create(['nome' => 'Cliente B', 'slug' => 'cliente-b']);
        DB::table('empresas')->where('id', $empresaB->id)->update(['tenant_id' => $outro->id]);
        DB::table('armazens')->insert([['empresa_id' => $empresaA->id], ['empresa_id' => $empresaB->id]]);

        DB::statement('SET ROLE erp_app');

        // Sem tenant definido: fail-closed.
        $this->assertSame(0, DB::table('armazens')->count());
        $this->assertSame(0, DB::table('empresas')->count());

        $this->definirTenant(1);
        $this->assertSame([$empresaA->id], DB::table('armazens')->pluck('empresa_id')->all());
        $this->assertSame([1], DB::table('empresas')->pluck('tenant_id')->unique()->values()->all());
        $this->assertNull(DB::table('empresas')->where('id', $empresaB->id)->first());

        $this->definirTenant($outro->id);
        $this->assertSame([$empresaB->id], DB::table('armazens')->pluck('empresa_id')->all());

        // Escrever para uma empresa de outro tenant é recusado (WITH CHECK).
        $this->definirTenant(1);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('armazens')->insert(['empresa_id' => $empresaB->id]));
    }

    public function test_utilizadores_tem_rls_por_tenant_e_a_pesquisa_de_login_atravessa_todos(): void
    {
        $outro = Tenant::query()->create(['nome' => 'Cliente B RLS', 'slug' => 'cliente-b-rls']);
        $idA = DB::table('utilizadores')->insertGetId(['tenant_id' => 1, 'nome_utilizador' => 'rls_user_a', 'palavra_passe' => 'x', 'papel' => 'UTILIZADOR', 'ativo' => true, 'criado_em' => now(), 'atualizado_em' => now()]);
        $idB = DB::table('utilizadores')->insertGetId(['tenant_id' => $outro->id, 'nome_utilizador' => 'rls_user_b', 'palavra_passe' => 'x', 'papel' => 'UTILIZADOR', 'ativo' => true, 'criado_em' => now(), 'atualizado_em' => now()]);

        DB::statement('SET ROLE erp_app');

        // Sem tenant definido: fail-closed, tal como empresas.
        $this->assertSame(0, DB::table('utilizadores')->whereIn('id', [$idA, $idB])->count());

        $this->definirTenant(1);
        $this->assertSame([$idA], DB::table('utilizadores')->whereIn('id', [$idA, $idB])->pluck('id')->all());

        $this->definirTenant($outro->id);
        $this->assertSame([$idB], DB::table('utilizadores')->whereIn('id', [$idA, $idB])->pluck('id')->all());

        // O login/recuperação de password têm de encontrar qualquer um dos dois mesmo sem o tenant definido
        // (login_localizar_utilizador, SECURITY DEFINER — é o único bypass legítimo desta política).
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");
        $this->assertSame('rls_user_a', DB::selectOne('SELECT nome_utilizador FROM login_localizar_utilizador(?, NULL)', ['rls_user_a'])->nome_utilizador);
        $this->assertSame('rls_user_b', DB::selectOne('SELECT nome_utilizador FROM login_localizar_utilizador(?, NULL)', ['rls_user_b'])->nome_utilizador);
    }

    /**
     * O Guard do Sanctum (vendor/laravel/sanctum/src/Guard.php, isValidAccessToken->hasValidProvider) acede a
     * `$accessToken->tokenable` ANTES de qualquer código da aplicação correr — nunca pode haver um pedido autenticado
     * genuíno (fora de testes, que correm sem RLS) sem que o tenant já esteja definido nesse preciso instante. Apanhou
     * uma regressão real: a 1.ª versão só definia o tenant depois (authenticateAccessTokensUsing), tarde de mais —
     * a relação já tinha ficado em cache a null e nenhum utilizador autenticado conseguia usar o token outra vez.
     */
    public function test_resolucao_do_utilizador_pelo_token_funciona_sob_a_role_da_aplicacao(): void
    {
        $utilizador = $this->criarUtilizador();
        $token = $utilizador->createToken('teste', ['*'])->plainTextToken;
        [$tokenId] = explode('|', $token, 2);
        config(['erp.saas.ativo' => true]);

        DB::statement('SET ROLE erp_app');
        DB::selectOne("SELECT set_config('app.tenant_id', '', false)");

        // Tal como o Sanctum faz, mas na ordem exacta que importa: o SELECT que resolve o tenant tem de acontecer
        // e o contexto tem de ficar definido ANTES de qualquer acesso a tokenable — nunca depois.
        $tenantId = DB::selectOne('SELECT tenant_do_token(?) AS tenant_id', [(int) $tokenId])->tenant_id ?? null;
        $this->assertSame((int) $utilizador->tenant_id, $tenantId);

        app(ContextoTenant::class)->definir((int) $tenantId);
        $tokenModel = TokenAcesso::findToken($token);
        $portador = $tokenModel->tokenable;

        $this->assertInstanceOf(Utilizador::class, $portador);
        $this->assertSame($utilizador->id, $portador->id);
    }

    public function test_a_role_da_aplicacao_nao_altera_nem_apaga_a_auditoria(): void
    {
        DB::statement('SET ROLE erp_app');

        foreach (['DELETE FROM logs_auditoria WHERE false', 'UPDATE logs_auditoria SET ocorrido_em = ocorrido_em WHERE false'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Devia ser recusado: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }

    private function definirTenant(int $id): void
    {
        DB::selectOne("SELECT set_config('app.tenant_id', ?, false)", [(string) $id]);
    }
}
