<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Connection;

/**
 * Isolamento por tenant na base de dados (SaaS fase 2, ADR-073): uma política RLS `isolamento_tenant` em cada tabela com
 * `empresa_id`, mais a role `erp_app` (a ligação da aplicação, sem SUPERUSER nem BYPASSRLS).
 *
 * Tudo é idempotente e seguro de repetir. Sem FORCE ROW LEVEL SECURITY, quem é dono das tabelas (migrações) não é
 * afectado pela política: só passa a valer quando a aplicação liga como `erp_app` (e com erp.saas.ativo=true).
 *
 * Requisito para qualquer migração futura que crie uma tabela com `empresa_id`: chamar `aplicarATodas()` no fim
 * (o teste de contrato `IsolamentoRlsContratoTest` falha se ficar alguma tabela sem política).
 */
final class IsolamentoRls
{
    public const POLITICA = 'isolamento_tenant';

    public const ROLE = 'erp_app';

    /** Tabelas com `empresa_id` que ficam fora da RLS (ADR-009: auditoria imutável por privilégios, não por tenant). */
    private const EXCLUIDAS = ['logs_auditoria%'];

    public function __construct(private readonly Connection $bd) {}

    /**
     * Tabelas com `empresa_id` (e `empresas`) sem a política de isolamento.
     *
     * @return list<string>
     */
    public function tabelasSemPolitica(): array
    {
        $linhas = $this->bd->select(<<<'SQL'
            SELECT t.tabela FROM (
                SELECT 'empresas'::text AS tabela
                UNION
                SELECT c.table_name::text FROM information_schema.columns c
                JOIN information_schema.tables tb ON tb.table_schema = c.table_schema AND tb.table_name = c.table_name
                WHERE c.table_schema = 'public' AND c.column_name = 'empresa_id' AND tb.table_type = 'BASE TABLE'
                  AND c.table_name <> 'empresas' AND c.table_name NOT LIKE 'logs_auditoria%'
            ) t
            WHERE NOT EXISTS (
                SELECT 1 FROM pg_policies p WHERE p.schemaname = 'public' AND p.tablename = t.tabela AND p.policyname = ?
            ) OR NOT EXISTS (
                SELECT 1 FROM pg_class k WHERE k.relname = t.tabela AND k.relnamespace = 'public'::regnamespace AND k.relrowsecurity
            )
            ORDER BY 1
        SQL, [self::POLITICA]);

        return array_map(fn ($l) => $l->tabela, $linhas);
    }

    /**
     * Estado REAL do isolamento (não basta haver políticas). `ativo` só é verdadeiro quando todas as verificações passam:
     *  - saas_ativo: `erp.saas.ativo` efectivo (config), senão o tenant nunca é definido na sessão;
     *  - ligacao_sem_privilegios: a ligação actual não é superutilizador nem tem BYPASSRLS;
     *  - nao_dona_das_tabelas: a ligação actual não é dona das tabelas (sem FORCE, o dono ignora a política);
     *  - row_security: `row_security` ligado na sessão;
     *  - politicas: todas as tabelas com `empresa_id` (e `empresas`) têm a política e a RLS activa.
     * Cada verificação traz `ok` e, quando falha, o `motivo`.
     *
     * @return array{ativo: bool, utilizador: ?string, verificacoes: array<string, array{ok: bool, motivo: ?string}>, tabelas_sem_rls: list<string>}
     */
    public function estado(): array
    {
        $utilizador = null;
        $verificacoes = [];

        $verificacoes['saas_ativo'] = (bool) config('erp.saas.ativo', false)
            ? ['ok' => true, 'motivo' => null]
            : ['ok' => false, 'motivo' => 'SAAS_ATIVO está desligado na configuração efectiva: a aplicação não define o tenant e a RLS não é usada.'];

        try {
            $papel = $this->bd->selectOne('SELECT r.rolname, r.rolsuper, r.rolbypassrls FROM pg_roles r WHERE r.rolname = current_user');
            $utilizador = $papel->rolname ?? null;
            $privilegiado = $papel === null || $this->booleano($papel->rolsuper) || $this->booleano($papel->rolbypassrls);
            $verificacoes['ligacao_sem_privilegios'] = $privilegiado
                ? ['ok' => false, 'motivo' => $papel === null
                    ? 'Não foi possível identificar a role da ligação.'
                    : "A ligação actual ({$papel->rolname}) é superutilizador ou tem BYPASSRLS: ignora todas as políticas."]
                : ['ok' => true, 'motivo' => null];

            $donas = $this->bd->select(<<<'SQL'
                SELECT tablename FROM pg_tables
                WHERE schemaname = 'public' AND tableowner = current_user
                  AND (tablename = 'empresas' OR tablename IN (
                        SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'empresa_id'))
                ORDER BY tablename
            SQL);
            $verificacoes['nao_dona_das_tabelas'] = $donas === []
                ? ['ok' => true, 'motivo' => null]
                : ['ok' => false, 'motivo' => 'A ligação actual é dona de '.count($donas).' tabela(s) isoladas (ex.: '.$donas[0]->tablename.'): o dono não é afectado pela política.'];
        } catch (\Throwable $e) {
            $verificacoes['ligacao_sem_privilegios'] = ['ok' => false, 'motivo' => 'Não foi possível consultar pg_roles: '.$e->getMessage()];
            $verificacoes['nao_dona_das_tabelas'] = ['ok' => false, 'motivo' => 'Não foi possível consultar pg_tables.'];
        }

        try {
            $rowSecurity = (string) ($this->bd->selectOne("SELECT current_setting('row_security') AS v")->v ?? '');
            $verificacoes['row_security'] = $rowSecurity === 'on'
                ? ['ok' => true, 'motivo' => null]
                : ['ok' => false, 'motivo' => "row_security está '{$rowSecurity}' na sessão."];
        } catch (\Throwable $e) {
            $verificacoes['row_security'] = ['ok' => false, 'motivo' => 'Não foi possível ler row_security.'];
        }

        try {
            $semPolitica = $this->tabelasSemPolitica();
        } catch (\Throwable $e) {
            $semPolitica = [];
            $verificacoes['politicas'] = ['ok' => false, 'motivo' => 'Não foi possível verificar as políticas: '.$e->getMessage()];
        }
        $verificacoes['politicas'] ??= $semPolitica === []
            ? ['ok' => true, 'motivo' => null]
            : ['ok' => false, 'motivo' => 'Tabelas sem política/RLS activa: '.implode(', ', $semPolitica).'.'];

        return [
            'ativo' => ! in_array(false, array_column($verificacoes, 'ok'), true),
            'utilizador' => $utilizador,
            'verificacoes' => $verificacoes,
            'tabelas_sem_rls' => $semPolitica,
        ];
    }

    private function booleano(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }

    /**
     * Cria a política nas tabelas que não a têm.
     *
     * @return list<string> tabelas protegidas agora
     */
    public function aplicarATodas(): array
    {
        $feitas = [];

        foreach ($this->tabelasSemPolitica() as $tabela) {
            $this->bd->statement("ALTER TABLE {$tabela} ENABLE ROW LEVEL SECURITY");
            $this->bd->statement('DROP POLICY IF EXISTS '.self::POLITICA." ON {$tabela}");

            $condicao = $tabela === 'empresas'
                ? 'tenant_id = app_tenant_id()'
                : ($this->anulavel($tabela)
                    ? 'empresa_id IS NULL OR empresa_id IN (SELECT id FROM empresas)'
                    : 'empresa_id IN (SELECT id FROM empresas)');

            $this->bd->statement('CREATE POLICY '.self::POLITICA." ON {$tabela} USING ({$condicao}) WITH CHECK ({$condicao})");
            $feitas[] = $tabela;
        }

        return $feitas;
    }

    /**
     * Cria/actualiza a role da aplicação e os privilégios. A palavra-passe só é definida se indicada.
     * Privilégios por omissão para tabelas futuras (mesmo dono) e auditoria imutável também na base de dados.
     */
    public function prepararRole(?string $palavraPasse = null): void
    {
        $this->bd->unprepared(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN
                    CREATE ROLE erp_app LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
                END IF;
            END $$
        SQL);

        if ($palavraPasse !== null && $palavraPasse !== '') {
            $this->bd->unprepared('ALTER ROLE erp_app LOGIN PASSWORD '.$this->bd->getPdo()->quote($palavraPasse));
        }
        // Só um superuser altera SUPERUSER/BYPASSRLS (na RDS o dono não é superuser): corrige-se apenas se estiverem mal,
        // e aí falhar é o correcto — a aplicação não pode ligar com uma role que ignora a RLS.
        $atributos = $this->bd->selectOne("SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'erp_app'");
        if ($atributos && ($atributos->rolsuper || $atributos->rolbypassrls)) {
            $this->bd->unprepared('ALTER ROLE erp_app NOSUPERUSER NOBYPASSRLS');
        }

        $base = $this->bd->selectOne('SELECT current_database() AS base')->base;
        $this->bd->statement('GRANT CONNECT ON DATABASE "'.$base.'" TO erp_app');
        $this->bd->statement('GRANT USAGE ON SCHEMA public TO erp_app');
        $this->bd->statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO erp_app');
        $this->bd->statement('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO erp_app');
        $this->bd->statement('GRANT EXECUTE ON FUNCTION app_tenant_id(), app_tenant_da_empresa(bigint) TO erp_app');
        $this->bd->statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO erp_app');
        $this->bd->statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO erp_app');

        $particoes = $this->bd->select("SELECT relname FROM pg_class WHERE relkind IN ('r','p') AND relname LIKE 'logs_auditoria%' AND relnamespace = 'public'::regnamespace");
        foreach ($particoes as $p) {
            $this->bd->statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$p->relname} FROM erp_app");
        }
    }

    private function anulavel(string $tabela): bool
    {
        $r = $this->bd->selectOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'empresa_id'",
            [$tabela],
        );

        return ($r->is_nullable ?? 'NO') === 'YES';
    }
}
