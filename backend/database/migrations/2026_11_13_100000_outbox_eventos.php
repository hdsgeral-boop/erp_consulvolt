<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional Outbox genérico (ADR-080). `outbox_eventos` guarda os efeitos externos de uma escrita de negócio (notificações,
 * e-mails, integrações) na MESMA transacção dessa escrita; o publicador entrega-os depois, «pelo menos uma vez», a manipuladores
 * idempotentes. `empresa_id` nulo = evento global da plataforma (sem empresa). A política RLS (IsolamentoRls) deixa ver os
 * eventos globais e os das empresas do tenant activo: o publicador percorre os tenants (ContextoTenant::porTenant).
 *
 * Acrescenta também `notificacoes_sistema.chave_origem` (única quando preenchida): o manipulador `notificacao.rh` usa-a para não
 * duplicar uma notificação numa reentrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outbox_eventos')) {
            Schema::create('outbox_eventos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id')->nullable();
                $t->string('tipo', 60);
                $t->string('agregado_tipo', 60)->nullable();
                $t->unsignedBigInteger('agregado_id')->nullable();
                $t->string('chave', 150);
                $t->jsonb('carga')->default('{}');
                $t->string('estado', 10)->default('PENDENTE');
                $t->smallInteger('tentativas')->default(0);
                $t->timestampTz('proxima_tentativa_em', 6)->useCurrent();
                $t->timestampTz('reservado_ate', 6)->nullable();
                $t->text('ultimo_erro')->nullable();
                $t->timestampTz('criado_em', 6)->useCurrent();
                $t->timestampTz('publicado_em', 6)->nullable();
                $t->foreign('empresa_id', 'fk_outbox_eventos_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
            DB::statement('CREATE UNIQUE INDEX uq_outbox_eventos_chave ON outbox_eventos ((coalesce(empresa_id, 0)), chave)');
            DB::statement("CREATE INDEX ix_outbox_eventos_pendentes ON outbox_eventos (proxima_tentativa_em) WHERE estado = 'PENDENTE'");
            DB::statement("CREATE INDEX ix_outbox_eventos_mortos ON outbox_eventos (id) WHERE estado = 'MORTO'");
            DB::statement("ALTER TABLE outbox_eventos ADD CONSTRAINT ck_outbox_eventos_estado CHECK (estado IN ('PENDENTE', 'PUBLICADO', 'MORTO'))");
            DB::statement('ALTER TABLE outbox_eventos ADD CONSTRAINT ck_outbox_eventos_tentativas CHECK (tentativas >= 0)');
        }

        if (! Schema::hasColumn('notificacoes_sistema', 'chave_origem')) {
            Schema::table('notificacoes_sistema', function (Blueprint $t) {
                $t->string('chave_origem', 190)->nullable();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_notificacoes_sistema_chave_origem ON notificacoes_sistema (chave_origem) WHERE chave_origem IS NOT NULL');

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON outbox_eventos TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE outbox_eventos_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_eventos');
        DB::statement('DROP INDEX IF EXISTS uq_notificacoes_sistema_chave_origem');
        if (Schema::hasColumn('notificacoes_sistema', 'chave_origem')) {
            Schema::table('notificacoes_sistema', fn (Blueprint $t) => $t->dropColumn('chave_origem'));
        }
    }
};
