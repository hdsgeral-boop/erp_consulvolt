<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F6 do POS (Q17): notas de crédito sobre factura-recibo de sessão POS. Cada NC fica registada contra a sessão com a repartição do
 * valor pelos meios de pagamento da factura (para abater os totais da sessão e estornar a transitória de cada meio). Tabela
 * nova, aditiva e idempotente; as sessões e vendas existentes não mudam. `abatida_em_aberta`: a NC foi emitida com a sessão aberta
 * (os totais já saem líquidos no fecho Z); caso contrário o instantâneo da sessão foi ajustado no momento da NC.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ncs_sessao_pos')) {
            Schema::create('ncs_sessao_pos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('sessao_pos_id');
                $t->unsignedBigInteger('venda_nc_id');
                $t->unsignedBigInteger('venda_origem_id');
                $t->decimal('valor', 18, 2);
                $t->jsonb('distribuicao');
                $t->date('data');
                $t->boolean('abatida_em_aberta')->default(false);
                $t->string('estado', 12)->default('PENDENTE');
                $t->string('numero_lan', 40)->nullable();
                $t->string('conta_cliente', 20)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_ncs_sessao_pos_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('sessao_pos_id', 'fk_ncs_sessao_pos_sessao_pos_id')->references('id')->on('sessoes_pos')->restrictOnDelete();
                $t->foreign('venda_nc_id', 'fk_ncs_sessao_pos_venda_nc_id')->references('id')->on('vendas')->restrictOnDelete();
                $t->foreign('venda_origem_id', 'fk_ncs_sessao_pos_venda_origem_id')->references('id')->on('vendas')->restrictOnDelete();
                $t->unique('venda_nc_id', 'uq_ncs_sessao_pos_venda_nc');
            });
            DB::statement("ALTER TABLE ncs_sessao_pos ADD CONSTRAINT ck_ncs_sessao_pos_estado CHECK (estado IN ('PENDENTE','LANCADA'))");
            DB::statement('ALTER TABLE ncs_sessao_pos ADD CONSTRAINT ck_ncs_sessao_pos_valor CHECK (valor > 0)');
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_ncs_sessao_pos_sessao ON ncs_sessao_pos (empresa_id, sessao_pos_id)');

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON ncs_sessao_pos TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE ncs_sessao_pos_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        // Aditiva: não se removem tabelas nem dados.
    }
};
