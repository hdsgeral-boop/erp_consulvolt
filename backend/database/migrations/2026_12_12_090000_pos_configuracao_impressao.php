<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F7 do POS: configuração de impressão guardada no servidor (antes só no localStorage do navegador). Uma linha por empresa
 * (terminal_pos_id nulo = valores da empresa) e, opcionalmente, uma sobreposição por posto/terminal (campos nulos = herda da
 * empresa). Tabela nova, aditiva e idempotente; o ficheiro de preferências local passa a ser só cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('configuracoes_impressao_pos')) {
            Schema::create('configuracoes_impressao_pos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('terminal_pos_id')->nullable();
                $t->string('formato', 10)->nullable();
                $t->unsignedSmallInteger('largura')->nullable();
                $t->boolean('automatico')->nullable();
                $t->string('consulta', 14)->nullable();
                $t->string('rodape', 200)->nullable();
                $t->string('atualizado_por', 150)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_config_impressao_pos_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('terminal_pos_id', 'fk_config_impressao_pos_terminal_pos_id')->references('id')->on('terminais_pos')->cascadeOnDelete();
            });
            DB::statement("ALTER TABLE configuracoes_impressao_pos ADD CONSTRAINT ck_config_impressao_pos_formato CHECK (formato IS NULL OR formato IN ('TERMICO','A4'))");
            DB::statement('ALTER TABLE configuracoes_impressao_pos ADD CONSTRAINT ck_config_impressao_pos_largura CHECK (largura IS NULL OR largura IN (58,80))');
            DB::statement("ALTER TABLE configuracoes_impressao_pos ADD CONSTRAINT ck_config_impressao_pos_consulta CHECK (consulta IS NULL OR consulta IN ('PREVISUALIZAR','DIRECTO'))");
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_config_impressao_pos_posto ON configuracoes_impressao_pos (empresa_id, COALESCE(terminal_pos_id, 0))');

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON configuracoes_impressao_pos TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE configuracoes_impressao_pos_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        // Aditiva: não se removem tabelas nem dados.
    }
};
