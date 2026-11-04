<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Compatibilidade com a base de dados da PRODUÇÃO (ensaio de 2026-11-04). Só actua onde existem os objectos que a
 * produção tem e o repositório não — no desenvolvimento/teste é um no-op.
 *
 * 1) PERFIS POR CLIENTE. A produção guarda `perfis_utilizador.tenant_id` (uma cópia de cada perfil por cliente). O código
 *    trata os perfis como globais: sem esta fusão cada cliente veria as cópias de todos e não poderia criar um nome já
 *    usado por outro. As cópias idênticas (mesmo nome e mesmas permissões) fundem-se na de menor id, os utilizadores são
 *    remapeados, e as variantes divergentes do mesmo nome ficam todas, as segundas e seguintes com o sufixo « (cliente N)».
 *    `tenant_id` fica (não se apaga) mas passa a anulável, sem valor por omissão e a NULL, para o INSERT do código novo.
 *    ANTES de alterar copia-se o estado original para `perfis_utilizador_pre_fusao` e `utilizadores_perfil_pre_fusao`
 *    (só se não existirem, para a reversão manual não perder o original numa 2.ª execução); down() repõe a partir delas.
 *
 * 2) AUDITORIA DA PLATAFORMA. Na produção `logs_auditoria` tem RLS (`auditoria_ler_tenant`); o código assume-a fora da RLS e
 *    a consola da plataforma leria só os seus próprios registos. Acrescenta-se a política de leitura
 *    `auditoria_ler_plataforma` (tenant da plataforma vê tudo, incluindo as linhas sem tenant). A RLS por cliente mantém-se.
 *
 * Idempotente. Corre como dono das tabelas (as políticas RLS não se aplicam ao dono).
 */
return new class extends Migration
{
    private const POLITICA_CLIENTE = 'auditoria_ler_tenant';

    private const POLITICA_PLATAFORMA = 'auditoria_ler_plataforma';

    public function up(): void
    {
        $this->fundirPerfis();
        $this->politicaAuditoria();
    }

    public function down(): void
    {
        $this->reporPerfis();
        if (Schema::hasTable('logs_auditoria') && $this->existePolitica(self::POLITICA_PLATAFORMA)) {
            DB::statement('DROP POLICY '.self::POLITICA_PLATAFORMA.' ON logs_auditoria');
        }
    }

    private function fundirPerfis(): void
    {
        if (! Schema::hasTable('perfis_utilizador') || ! Schema::hasColumn('perfis_utilizador', 'tenant_id')) {
            return;
        }

        // salvaguarda do estado original (uma só vez)
        if (! Schema::hasTable('perfis_utilizador_pre_fusao')) {
            DB::statement('CREATE TABLE perfis_utilizador_pre_fusao AS SELECT * FROM perfis_utilizador');
        }
        if (! Schema::hasTable('utilizadores_perfil_pre_fusao')) {
            DB::statement('CREATE TABLE utilizadores_perfil_pre_fusao AS SELECT id, perfil_utilizador_id FROM utilizadores WHERE perfil_utilizador_id IS NOT NULL');
        }

        // o código novo insere perfis sem tenant: a coluna não pode exigi-lo nem preenchê-lo por omissão
        $coluna = DB::selectOne("SELECT is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'perfis_utilizador' AND column_name = 'tenant_id'");
        if ($coluna && $coluna->is_nullable === 'NO') {
            DB::statement('ALTER TABLE perfis_utilizador ALTER COLUMN tenant_id DROP NOT NULL');
        }
        if ($coluna && $coluna->column_default !== null) {
            DB::statement('ALTER TABLE perfis_utilizador ALTER COLUMN tenant_id DROP DEFAULT');
        }

        DB::statement('DROP TABLE IF EXISTS _perfis_mapa');
        DB::statement('DROP TABLE IF EXISTS _perfis_renomear');
        DB::statement('CREATE TEMP TABLE _perfis_mapa AS
            SELECT p.id, min(p.id) OVER (PARTITION BY p.nome, p.permissoes::text) AS canonico FROM perfis_utilizador p');
        DB::statement("CREATE TEMP TABLE _perfis_renomear AS
            SELECT c.id, c.nome, row_number() OVER (PARTITION BY c.nome ORDER BY c.id) AS ordem,
              (SELECT string_agg(DISTINCT p.tenant_id::text, ',' ORDER BY p.tenant_id::text) FROM perfis_utilizador p JOIN _perfis_mapa m ON m.id = p.id WHERE m.canonico = c.id) AS clientes
            FROM perfis_utilizador c WHERE c.id IN (SELECT canonico FROM _perfis_mapa)");

        $remapeados = DB::affectingStatement('UPDATE utilizadores u SET perfil_utilizador_id = m.canonico FROM _perfis_mapa m WHERE u.perfil_utilizador_id = m.id AND m.canonico <> m.id');
        $fundidos = DB::affectingStatement('DELETE FROM perfis_utilizador p USING _perfis_mapa m WHERE p.id = m.id AND m.canonico <> m.id');
        $renomeados = DB::affectingStatement("UPDATE perfis_utilizador p SET nome = left(p.nome, 100 - length(' (cliente ' || r.clientes || ')')) || ' (cliente ' || r.clientes || ')', atualizado_em = now()
            FROM _perfis_renomear r WHERE r.id = p.id AND r.ordem > 1 AND r.clientes IS NOT NULL AND p.nome = r.nome");
        $semCliente = DB::affectingStatement('UPDATE perfis_utilizador SET tenant_id = NULL WHERE tenant_id IS NOT NULL');

        DB::statement('DROP TABLE IF EXISTS _perfis_mapa');
        DB::statement('DROP TABLE IF EXISTS _perfis_renomear');

        $orfaos = (int) DB::scalar('SELECT count(*) FROM utilizadores u WHERE u.perfil_utilizador_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM perfis_utilizador p WHERE p.id = u.perfil_utilizador_id)');
        if ($orfaos > 0) {
            throw new RuntimeException("Fusão de perfis abortada: {$orfaos} utilizador(es) ficariam com um perfil inexistente.");
        }
        if ($fundidos || $remapeados || $renomeados || $semCliente) {
            Log::info('Perfis fundidos para a compatibilidade com a produção', compact('fundidos', 'remapeados', 'renomeados') + ['tenant_id_limpos' => $semCliente]);
        }
    }

    private function reporPerfis(): void
    {
        if (! Schema::hasTable('perfis_utilizador') || ! Schema::hasTable('perfis_utilizador_pre_fusao')) {
            return;
        }
        $colunas = array_values(array_intersect(Schema::getColumnListing('perfis_utilizador_pre_fusao'), Schema::getColumnListing('perfis_utilizador')));
        $lista = implode(', ', array_map(fn ($c) => '"'.$c.'"', $colunas));
        $origem = implode(', ', array_map(fn ($c) => 'b."'.$c.'"', $colunas));
        $actualizar = implode(', ', array_map(fn ($c) => '"'.$c.'" = b."'.$c.'"', array_values(array_diff($colunas, ['id']))));

        // repõe os perfis eliminados na fusão e o estado original (nome, tenant_id, …) dos que ficaram
        DB::statement("INSERT INTO perfis_utilizador ({$lista}) SELECT {$origem} FROM perfis_utilizador_pre_fusao b WHERE NOT EXISTS (SELECT 1 FROM perfis_utilizador p WHERE p.id = b.id)");
        if ($actualizar !== '') {
            DB::statement("UPDATE perfis_utilizador p SET {$actualizar} FROM perfis_utilizador_pre_fusao b WHERE p.id = b.id");
        }
        DB::statement("SELECT setval(pg_get_serial_sequence('perfis_utilizador', 'id'), GREATEST((SELECT max(id) FROM perfis_utilizador), 1))");

        if (Schema::hasTable('utilizadores_perfil_pre_fusao')) {
            DB::statement('UPDATE utilizadores u SET perfil_utilizador_id = b.perfil_utilizador_id FROM utilizadores_perfil_pre_fusao b
                WHERE u.id = b.id AND EXISTS (SELECT 1 FROM perfis_utilizador p WHERE p.id = b.perfil_utilizador_id)');
        }
    }

    private function politicaAuditoria(): void
    {
        if (! Schema::hasTable('logs_auditoria') || ! $this->existePolitica(self::POLITICA_CLIENTE) || $this->existePolitica(self::POLITICA_PLATAFORMA)) {
            return;
        }
        $plataforma = (int) config('erp.dominio.plataforma_tenant_id', 1);
        DB::statement('CREATE POLICY '.self::POLITICA_PLATAFORMA." ON logs_auditoria FOR SELECT USING (app_tenant_id() = {$plataforma})");
    }

    private function existePolitica(string $nome): bool
    {
        return DB::table('pg_policies')->where('schemaname', DB::raw('current_schema()'))->where('tablename', 'logs_auditoria')->where('policyname', $nome)->exists();
    }
};
