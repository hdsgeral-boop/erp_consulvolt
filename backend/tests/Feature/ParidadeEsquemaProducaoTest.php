<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Migração 2026_11_22_090000 (paridade com a produção): funções SECURITY DEFINER sem EXECUTE para PUBLIC. A condição da
 * produção (ACL nula = EXECUTE para PUBLIC) reproduz-se dentro da transacção do teste.
 */
final class ParidadeEsquemaProducaoTest extends TestCase
{
    private const MIGRACAO = __DIR__.'/../../database/migrations/2026_11_22_090000_paridade_esquema_producao.php';

    private const FUNCOES = [
        'app_empresa_por_token_bi(text)',
        'app_empresa_por_webhook_token(text)',
        'plataforma_empresas()',
        'plataforma_empresas_por_tenant()',
    ];

    private function migracao(): object
    {
        return require self::MIGRACAO;
    }

    private function publicoExecuta(string $assinatura): bool
    {
        return (bool) DB::scalar(
            "SELECT EXISTS (SELECT 1 FROM pg_proc p, LATERAL aclexplode(COALESCE(p.proacl, acldefault('f', p.proowner))) a
                WHERE p.oid = to_regprocedure(?)::oid AND a.grantee = 0 AND a.privilege_type = 'EXECUTE')",
            [$assinatura],
        );
    }

    private function erpAppExecuta(string $assinatura): bool
    {
        return (bool) DB::scalar("SELECT has_function_privilege('erp_app', to_regprocedure(?)::oid, 'EXECUTE')", [$assinatura]);
    }

    private function simularProducao(string $assinatura): void
    {
        // ACL nula, como na produção: repõe o valor por omissão (EXECUTE para PUBLIC e para o dono)
        DB::statement("REVOKE ALL ON FUNCTION {$assinatura} FROM erp_app");
        DB::statement("GRANT EXECUTE ON FUNCTION {$assinatura} TO PUBLIC");
    }

    #[Test]
    public function e_no_op_quando_as_funcoes_ja_estao_fechadas_a_public(): void
    {
        foreach (self::FUNCOES as $f) {
            $this->assertFalse($this->publicoExecuta($f), "{$f} já devia estar fechada a PUBLIC no esquema do código");
        }
        $antes = DB::select("SELECT proname, proacl::text AS acl FROM pg_proc WHERE proname IN ('plataforma_empresas','app_empresa_por_token_bi') ORDER BY 1");
        $this->migracao()->up();
        $this->migracao()->up();
        $this->assertEquals($antes, DB::select("SELECT proname, proacl::text AS acl FROM pg_proc WHERE proname IN ('plataforma_empresas','app_empresa_por_token_bi') ORDER BY 1"));
    }

    #[Test]
    public function fecha_as_funcoes_abertas_a_public_e_mantem_erp_app_idempotente(): void
    {
        foreach (self::FUNCOES as $f) {
            $this->simularProducao($f);
            $this->assertTrue($this->publicoExecuta($f));
            $this->assertFalse(DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_proc p, LATERAL aclexplode(p.proacl) a WHERE p.oid = to_regprocedure(?)::oid AND a.grantee = (SELECT oid FROM pg_roles WHERE rolname = 'erp_app'))", [$f]));
        }

        $this->migracao()->up();
        foreach (self::FUNCOES as $f) {
            $this->assertFalse($this->publicoExecuta($f), "{$f} devia ter perdido o EXECUTE de PUBLIC");
            $this->assertTrue($this->erpAppExecuta($f), "{$f} devia manter EXECUTE para erp_app");
        }

        // Segunda corrida: sem efeito nem erro
        $this->migracao()->up();
        foreach (self::FUNCOES as $f) {
            $this->assertFalse($this->publicoExecuta($f));
            $this->assertTrue($this->erpAppExecuta($f));
        }
    }

    #[Test]
    public function ignora_funcao_inexistente_sem_erro(): void
    {
        DB::statement('DROP FUNCTION plataforma_empresas_por_tenant()');
        $this->migracao()->up();
        $this->assertNull(DB::scalar("SELECT to_regprocedure('plataforma_empresas_por_tenant()')"));
    }
}
