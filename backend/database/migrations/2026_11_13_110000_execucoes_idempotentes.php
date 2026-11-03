<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Execuções idempotentes (ADR-080): registo, por empresa e chave determinística, de um trabalho de fila que pode ser repetido.
 * EM_CURSO com `reservado_ate` (lease) enquanto um worker o executa; CONCLUIDO com o `resultado` guardado — uma repetição devolve
 * esse resultado sem voltar a executar. Usado pelos jobs de contabilização em lote (ExecucaoIdempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('execucoes_idempotentes')) {
            Schema::create('execucoes_idempotentes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('chave', 190);
                $t->string('estado', 12)->default('EM_CURSO');
                $t->jsonb('resultado')->nullable();
                $t->smallInteger('tentativas')->default(0);
                $t->timestampTz('reservado_ate', 6)->nullable();
                $t->timestampTz('criado_em', 6)->useCurrent();
                $t->timestampTz('concluido_em', 6)->nullable();
                $t->foreign('empresa_id', 'fk_execucoes_idempotentes_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
            DB::statement('CREATE UNIQUE INDEX uq_execucoes_idempotentes_chave ON execucoes_idempotentes (empresa_id, chave)');
            DB::statement("ALTER TABLE execucoes_idempotentes ADD CONSTRAINT ck_execucoes_idempotentes_estado CHECK (estado IN ('EM_CURSO', 'CONCLUIDO'))");
            DB::statement("CREATE INDEX ix_execucoes_idempotentes_concluidas ON execucoes_idempotentes (concluido_em) WHERE estado = 'CONCLUIDO'");
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON execucoes_idempotentes TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE execucoes_idempotentes_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        Schema::dropIfExists('execucoes_idempotentes');
    }
};
