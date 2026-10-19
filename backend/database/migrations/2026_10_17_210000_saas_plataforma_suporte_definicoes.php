<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS, fase 4 (fatia 2): `tickets_suporte` (pedidos dos clientes, geridos pela plataforma; pertencem ao cliente, não a
 * uma empresa, por isso sem `empresa_id` nem RLS) e `definicoes_plataforma` (chave/valor global). Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets_suporte')) {
            Schema::create('tickets_suporte', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('assunto', 200);
                $t->text('descricao')->nullable();
                $t->string('estado', 20)->default('ABERTO');
                $t->string('prioridade', 10)->default('NORMAL');
                $t->string('categoria', 20)->default('GERAL');
                $t->string('criado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('tenant_id', 'fk_tickets_suporte_tenant_id')->references('id')->on('tenants');
                $t->index(['estado', 'criado_em'], 'idx_tickets_suporte_estado');
            });
        }

        if (! Schema::hasTable('definicoes_plataforma')) {
            Schema::create('definicoes_plataforma', function (Blueprint $t) {
                $t->string('chave', 60)->primary();
                $t->text('valor')->nullable();
                $t->timestampTz('atualizado_em')->useCurrent();
            });
            foreach (['nome_plataforma' => 'ERP SaaS', 'email_suporte' => null, 'mensagem_manutencao' => null] as $chave => $valor) {
                DB::table('definicoes_plataforma')->insert(['chave' => $chave, 'valor' => $valor, 'atualizado_em' => now()]);
            }
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON tickets_suporte, definicoes_plataforma TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE tickets_suporte_id_seq TO erp_app; END IF; END $$');
    }

    public function down(): void
    {
        Schema::dropIfExists('definicoes_plataforma');
        Schema::dropIfExists('tickets_suporte');
    }
};