<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Séries do painel da plataforma (integração da produção, Bloco A.9): receita recebida por mês e acessos por dia de
 * TODOS os clientes. `subscricoes_empresa` e `utilizadores` têm RLS (por empresa / tenant): sob a role `erp_app` a
 * consulta directa só veria o cliente da plataforma. Funções SECURITY DEFINER devolvem só agregados (contagens e somas),
 * nunca dados de negócio — o mesmo padrão de plataforma_empresas_por_tenant(). Aditiva (CREATE OR REPLACE).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION plataforma_receita_por_mes(p_desde timestamptz) RETURNS TABLE (mes text, total numeric)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT to_char(pago_em, 'YYYY-MM'), COALESCE(SUM(valor), 0)
                FROM subscricoes_empresa WHERE estado = 'PAGO' AND pago_em >= p_desde GROUP BY 1
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION plataforma_acessos_por_dia(p_desde timestamptz, p_excluir_tenant bigint) RETURNS TABLE (dia text, total bigint)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
                SELECT to_char(ultimo_acesso_em, 'YYYY-MM-DD'), COUNT(*)
                FROM utilizadores
                WHERE eliminado_em IS NULL AND ultimo_acesso_em >= p_desde AND tenant_id IS DISTINCT FROM p_excluir_tenant
                GROUP BY 1
            $$
        SQL);

        foreach (['plataforma_receita_por_mes(timestamptz)', 'plataforma_acessos_por_dia(timestamptz, bigint)'] as $assinatura) {
            DB::statement("REVOKE EXECUTE ON FUNCTION {$assinatura} FROM PUBLIC");
            DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN GRANT EXECUTE ON FUNCTION {$assinatura} TO erp_app; END IF; END \$\$");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS plataforma_receita_por_mes(timestamptz)');
        DB::statement('DROP FUNCTION IF EXISTS plataforma_acessos_por_dia(timestamptz, bigint)');
    }
};
