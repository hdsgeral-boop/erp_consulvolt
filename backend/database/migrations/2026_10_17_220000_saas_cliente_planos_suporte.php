<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS, fase 5 (cliente): limite de empresas no plano, versões de plano (rascunho -> publicada, imutável), conversa dos
 * tickets de suporte e nome do sistema por cliente. Aditiva e idempotente; nenhuma tabela tem `empresa_id` (pertencem
 * ao cliente/plataforma), por isso sem RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('planos_licenca', 'max_empresas')) {
            Schema::table('planos_licenca', fn (Blueprint $t) => $t->unsignedInteger('max_empresas')->nullable());
        }

        if (! Schema::hasColumn('tenants', 'nome_sistema')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->string('nome_sistema', 80)->nullable());
        }

        if (! Schema::hasTable('planos_licenca_versoes')) {
            Schema::create('planos_licenca_versoes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('plano_id')->constrained('planos_licenca')->cascadeOnDelete();
                $t->unsignedInteger('numero');
                $t->string('estado', 15)->default('RASCUNHO');
                $t->decimal('preco_mensal', 14, 2)->default(0);
                $t->string('moeda', 3)->default('AOA');
                $t->unsignedInteger('max_empresas')->nullable();
                $t->unsignedInteger('max_utilizadores')->nullable();
                $t->jsonb('modulos')->default('[]');
                $t->timestampTz('publicada_em')->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->unique(['plano_id', 'numero'], 'uq_planos_licenca_versoes_numero');
            });
        }

        if (! Schema::hasTable('mensagens_suporte')) {
            Schema::create('mensagens_suporte', function (Blueprint $t) {
                $t->id();
                $t->foreignId('ticket_id')->constrained('tickets_suporte')->cascadeOnDelete();
                $t->string('autor_tipo', 12);
                $t->string('autor_nome', 100)->nullable();
                $t->text('mensagem');
                $t->timestampTz('criado_em')->useCurrent();
                $t->index(['ticket_id', 'criado_em'], 'idx_mensagens_suporte_ticket');
            });
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON planos_licenca_versoes, mensagens_suporte TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE planos_licenca_versoes_id_seq, mensagens_suporte_id_seq TO erp_app; END IF; END $$');
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens_suporte');
        Schema::dropIfExists('planos_licenca_versoes');
        if (Schema::hasColumn('tenants', 'nome_sistema')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('nome_sistema'));
        }
        if (Schema::hasColumn('planos_licenca', 'max_empresas')) {
            Schema::table('planos_licenca', fn (Blueprint $t) => $t->dropColumn('max_empresas'));
        }
    }
};