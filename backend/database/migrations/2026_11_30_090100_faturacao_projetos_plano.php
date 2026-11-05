<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plano de facturação do projecto («Financeiro» › Plano de facturação): `faturacao_projetos` passa a guardar as
 * prestações/marcos de facturação previstos (descrição, data prevista, valor previsto em `montante`, retenção) e a
 * ligação à factura de venda que as cumpre (`venda_id`; a emissão continua a ser a da Faturação existente, com a sua
 * série, hash e AGT). Aditiva e idempotente; `estado`: PLANEADO, FACTURADO ou CANCELADO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturacao_projetos', function (Blueprint $t) {
            if (! Schema::hasColumn('faturacao_projetos', 'descricao')) {
                $t->string('descricao', 200)->nullable();
            }
            if (! Schema::hasColumn('faturacao_projetos', 'marco_projeto_id')) {
                $t->unsignedBigInteger('marco_projeto_id')->nullable();
                $t->foreign('marco_projeto_id', 'fk_faturacao_projetos_marco_projeto_id')->references('id')->on('marcos_projeto')->nullOnDelete();
            }
            if (! Schema::hasColumn('faturacao_projetos', 'data_prevista')) {
                $t->date('data_prevista')->nullable();
            }
            if (! Schema::hasColumn('faturacao_projetos', 'retencao_pct')) {
                $t->decimal('retencao_pct', 5, 2)->default(0);
            }
            if (! Schema::hasColumn('faturacao_projetos', 'venda_id')) {
                $t->unsignedBigInteger('venda_id')->nullable();
                $t->foreign('venda_id', 'fk_faturacao_projetos_venda_id')->references('id')->on('vendas')->nullOnDelete();
            }
        });
        DB::statement('CREATE INDEX IF NOT EXISTS ix_faturacao_projetos_venda_id ON faturacao_projetos (venda_id)');

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON faturacao_projetos TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE faturacao_projetos_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        // Aditiva: não se removem colunas nem dados.
    }
};
