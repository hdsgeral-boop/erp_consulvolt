<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paridade do esquema da produção (análise de catálogo de 2026-11: docs/paridade/ESQUEMA_PRODUCAO_2026-11.md).
 *
 * Única lacuna estrutural da produção face ao que as migrações criam do zero: quatro funções SECURITY DEFINER
 * (`app_empresa_por_token_bi`, `app_empresa_por_webhook_token`, `plataforma_empresas`, `plataforma_empresas_por_tenant`)
 * ficaram na produção sem ACL explícita, ou seja, com EXECUTE para PUBLIC (o valor por omissão do PostgreSQL), quando o
 * código as quer só com EXECUTE para `erp_app` (e o dono). Funcionalmente nada falha (erp_app continua a executá-las);
 * é endurecimento: uma função SECURITY DEFINER que lê através da RLS não deve estar aberta a qualquer role da base.
 *
 * Aditiva e idempotente: só actua quando PUBLIC ainda tem EXECUTE; concede primeiro a `erp_app` e só depois retira a
 * PUBLIC (nunca há janela sem acesso). Não toca em dados, tabelas, RLS nem noutras funções. No-op onde nada falta
 * (desenvolvimento/teste, bases criadas do zero). `down()` é deliberadamente vazio: repor PUBLIC reabriria a lacuna.
 */
return new class extends Migration
{
    /** @var list<string> assinaturas (nome + tipos dos argumentos) */
    private const FUNCOES = [
        'app_empresa_por_token_bi(text)',
        'app_empresa_por_webhook_token(text)',
        'plataforma_empresas()',
        'plataforma_empresas_por_tenant()',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $temErpApp = (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app')");

        foreach (self::FUNCOES as $assinatura) {
            if (! $this->existe($assinatura) || ! $this->publicoExecuta($assinatura)) {
                continue;
            }
            if ($temErpApp) {
                DB::statement("GRANT EXECUTE ON FUNCTION {$assinatura} TO erp_app");
            }
            DB::statement("REVOKE ALL ON FUNCTION {$assinatura} FROM PUBLIC");
        }
    }

    public function down(): void
    {
        // Intencionalmente vazio (ver o comentário da classe).
    }

    private function existe(string $assinatura): bool
    {
        return DB::scalar('SELECT to_regprocedure(?)', [$assinatura]) !== null;
    }

    /** PUBLIC (grantee 0) tem EXECUTE, explicitamente ou pela ACL por omissão (proacl nula). */
    private function publicoExecuta(string $assinatura): bool
    {
        return (bool) DB::scalar(
            "SELECT EXISTS (SELECT 1 FROM pg_proc p, LATERAL aclexplode(COALESCE(p.proacl, acldefault('f', p.proowner))) a
                WHERE p.oid = to_regprocedure(?)::oid AND a.grantee = 0 AND a.privilege_type = 'EXECUTE')",
            [$assinatura],
        );
    }
};
