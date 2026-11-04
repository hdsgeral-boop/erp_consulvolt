<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retenção na fonte no recibo de cliente (o legado não a tinha). O cliente retém uma parte do pagamento e entrega-a ao
 * Estado: o recibo liquida as facturas pelo valor BRUTO, mas só entra em caixa/banco o valor bruto menos a retenção; a
 * retenção fica como imposto suportado/antecipado (crédito de imposto).
 *
 *  - tipos_retencao_fonte (por empresa): catálogo configurável (código, descrição, percentagem por omissão, conta do imposto
 *    retido própria opcional, activo). O arranque (6,5 % serviços) é semeado pelo serviço, não aqui, para respeitar o tenant;
 *  - recibos_venda_retencoes (por empresa, 1:1 com o recibo): o instantâneo da retenção desse recibo — tipo (código e
 *    descrição copiados, sobrevive a alterações do catálogo), percentagem, base de incidência, valor retido, valor calculado
 *    (para se ver quando foi editado à mão), comprovativo (n.º e data) e a conta do imposto usada. Tabela à parte (e não colunas
 *    em recibos_venda) para não mexer no modelo gerado do recibo e manter os recibos sem retenção exactamente como estavam.
 *
 * Aditiva e idempotente; RLS por IsolamentoRls (empresa_id); GRANT à role erp_app.
 */
return new class extends Migration
{
    private const TABELAS = ['tipos_retencao_fonte', 'recibos_venda_retencoes'];

    public function up(): void
    {
        if (! Schema::hasTable('tipos_retencao_fonte')) {
            Schema::create('tipos_retencao_fonte', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->string('codigo', 30);
                $t->string('descricao', 200);
                $t->decimal('percentagem', 7, 4)->default(0)->comment('0 a 100');
                $t->string('conta_imposto', 20)->nullable()->comment('conta do imposto retido própria deste tipo; nula = a das contas de vendas');
                $t->boolean('ativo')->default(true);
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->unique(['empresa_id', 'codigo'], 'uq_tipos_retencao_fonte_empresa_codigo');
            });
            DB::statement('ALTER TABLE tipos_retencao_fonte ADD CONSTRAINT ck_tipos_retencao_fonte_percentagem CHECK (percentagem >= 0 AND percentagem <= 100)');
        }

        if (! Schema::hasTable('recibos_venda_retencoes')) {
            Schema::create('recibos_venda_retencoes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->foreignId('recibo_venda_id')->constrained('recibos_venda')->cascadeOnDelete();
                $t->foreignId('tipo_retencao_fonte_id')->nullable()->constrained('tipos_retencao_fonte')->nullOnDelete();
                $t->string('codigo_tipo', 30);
                $t->string('descricao_tipo', 200);
                $t->decimal('percentagem', 7, 4);
                $t->decimal('base_incidencia', 15, 2);
                $t->decimal('valor_retido', 15, 2);
                $t->decimal('valor_calculado', 15, 2)->comment('base x percentagem, arredondado; difere do retido quando este foi editado (o comprovativo do cliente manda)');
                $t->string('referencia_comprovativo', 100)->nullable();
                $t->date('data_comprovativo')->nullable();
                $t->string('conta_imposto', 20)->nullable()->comment('conta do imposto retido usada na contabilização');
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->unique('recibo_venda_id', 'uq_recibos_venda_retencoes_recibo');
            });
            DB::statement('ALTER TABLE recibos_venda_retencoes ADD CONSTRAINT ck_recibos_venda_retencoes_valores CHECK (percentagem >= 0 AND percentagem <= 100 AND valor_retido >= 0 AND valor_retido <= base_incidencia)');
        }

        (new IsolamentoRls(DB::connection()))->aplicarATodas();

        $tabelas = implode(', ', array_map(fn ($t) => "'{$t}'", self::TABELAS));
        DB::unprepared(<<<SQL
            DO \$\$ DECLARE t text; BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN
                    FOREACH t IN ARRAY ARRAY[{$tabelas}] LOOP
                        EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON %I TO erp_app', t);
                        EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %I TO erp_app', t || '_id_seq');
                    END LOOP;
                END IF;
            END \$\$
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('recibos_venda_retencoes');
        Schema::dropIfExists('tipos_retencao_fonte');
    }
};
