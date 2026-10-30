<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Período de férias por ano, definido pelo administrador por empresa: o colaborador só pode pedir férias (pelo
 * Portal) dentro deste período. Um só período por empresa+ano (constraint única); `ServicoFerias::criarPedido()`
 * passa a validar as datas pedidas contra o período activo do ano — sem período definido, o pedido é recusado com
 * uma mensagem explícita. A criação directa pelo RH (`gravar()`) não fica sujeita a este período (histórico/excepções).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('periodos_ferias_empresa')) {
            Schema::create('periodos_ferias_empresa', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->integer('ano');
                $t->date('data_inicio');
                $t->date('data_fim');
                $t->string('criado_por', 150)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_periodos_ferias_empresa_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
            DB::statement('CREATE UNIQUE INDEX uq_periodos_ferias_empresa_ano ON periodos_ferias_empresa (empresa_id, ano)');
            DB::statement('ALTER TABLE periodos_ferias_empresa ADD CONSTRAINT ck_periodos_ferias_empresa_datas CHECK (data_fim >= data_inicio)');
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON periodos_ferias_empresa TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE periodos_ferias_empresa_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos_ferias_empresa');
    }
};
