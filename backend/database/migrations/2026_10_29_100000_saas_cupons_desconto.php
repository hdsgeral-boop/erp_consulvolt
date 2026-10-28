<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cupões e campanhas promocionais da subscrição SaaS (motor de precificação, Módulo B). `cupons_desconto` é global
 * (catálogo da plataforma, como `planos_licenca` — sem `empresa_id`/`tenant_id`, sem RLS por desenho). `usos_cupom`
 * é o ledger de utilização: conta-se por `tenant_id` (um tenant pode ter várias empresas — `limite_por_cliente` tem
 * de contar por cliente, não por empresa), mas leva `empresa_id` só para herdar a política RLS automaticamente
 * (IsolamentoRls só protege tabelas com `empresa_id` ou em TENANT_DIRETAS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cupons_desconto', function (Blueprint $t) {
            $t->id();
            $t->string('codigo', 30);
            $t->text('descricao')->nullable();
            $t->string('tipo_desconto', 12);
            $t->decimal('valor_desconto', 14, 2);
            $t->string('moeda', 3)->default('AOA');
            $t->date('data_inicio');
            $t->date('data_fim');
            $t->unsignedInteger('limite_usos')->nullable();
            $t->unsignedInteger('usos_atuais')->default(0);
            $t->unsignedSmallInteger('limite_por_cliente')->default(1);
            $t->jsonb('planos_aplicaveis')->nullable();
            $t->jsonb('ciclos_aplicaveis')->nullable();
            $t->boolean('ativo')->default(true);
            $t->unsignedBigInteger('criado_por')->nullable();
            $t->timestampTz('criado_em')->useCurrent();
            $t->timestampTz('atualizado_em')->useCurrent();
            $t->foreign('criado_por', 'fk_cupons_desconto_criado_por')->references('id')->on('utilizadores')->nullOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX uq_cupons_desconto_codigo ON cupons_desconto (codigo)');
        DB::statement("ALTER TABLE cupons_desconto ADD CONSTRAINT ck_cupons_desconto_codigo_maiusculas CHECK (codigo = upper(codigo))");
        DB::statement("ALTER TABLE cupons_desconto ADD CONSTRAINT ck_cupons_desconto_tipo CHECK (tipo_desconto IN ('PERCENTAGEM','VALOR_FIXO'))");
        DB::statement("ALTER TABLE cupons_desconto ADD CONSTRAINT ck_cupons_desconto_percentagem_max "
            ."CHECK (tipo_desconto <> 'PERCENTAGEM' OR valor_desconto <= 15)");
        DB::statement('ALTER TABLE cupons_desconto ADD CONSTRAINT ck_cupons_desconto_periodo CHECK (data_fim >= data_inicio)');

        Schema::create('usos_cupom', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cupom_id');
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('empresa_id');
            $t->unsignedBigInteger('subscricao_id');
            $t->decimal('valor_desconto_aplicado', 14, 2);
            $t->timestampTz('criado_em')->useCurrent();
            $t->foreign('cupom_id', 'fk_usos_cupom_cupom_id')->references('id')->on('cupons_desconto')->restrictOnDelete();
            $t->foreign('tenant_id', 'fk_usos_cupom_tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $t->foreign('empresa_id', 'fk_usos_cupom_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            $t->foreign('subscricao_id', 'fk_usos_cupom_subscricao_id')->references('id')->on('subscricoes_empresa')->cascadeOnDelete();
            $t->unique('subscricao_id', 'uq_usos_cupom_subscricao');
            $t->index(['cupom_id', 'tenant_id'], 'idx_usos_cupom_cupom_tenant');
        });

        Schema::table('subscricoes_empresa', function (Blueprint $t) {
            $t->unsignedBigInteger('cupom_id')->nullable()->after('valor_apos_ciclo');
            $t->string('cupom_codigo', 30)->nullable()->after('cupom_id');
            $t->string('desconto_cupom_tipo', 15)->nullable()->after('cupom_codigo');
            $t->decimal('desconto_cupom_valor', 14, 2)->nullable()->after('desconto_cupom_tipo');
            $t->decimal('valor_desconto_cupom', 14, 2)->default(0)->after('desconto_cupom_valor');
            $t->foreign('cupom_id', 'fk_subscricoes_empresa_cupom_id')->references('id')->on('cupons_desconto')->nullOnDelete();
        });

        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'erp_app'") !== null) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON cupons_desconto, usos_cupom TO erp_app');
            DB::statement('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO erp_app');
        }

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        Schema::table('subscricoes_empresa', function (Blueprint $t) {
            $t->dropForeign('fk_subscricoes_empresa_cupom_id');
            $t->dropColumn(['cupom_id', 'cupom_codigo', 'desconto_cupom_tipo', 'desconto_cupom_valor', 'valor_desconto_cupom']);
        });
        Schema::dropIfExists('usos_cupom');
        Schema::dropIfExists('cupons_desconto');
    }
};
