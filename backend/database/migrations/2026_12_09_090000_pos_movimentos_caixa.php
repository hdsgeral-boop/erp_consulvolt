<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1b do POS: suprimentos (dinheiro que entra na gaveta durante a sessão) e sangrias (dinheiro retirado da gaveta para o cofre/banco).
 * O legado não tinha nenhum dos dois. Tabela nova, aditiva e idempotente; anular não apaga (estado ANULADO com quem, quando e porquê).
 * As contas (transitória do numerário e conta de liquidação do meio) ficam gravadas no movimento, como nas liquidações.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('movimentos_caixa_pos')) {
            Schema::create('movimentos_caixa_pos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('sessao_pos_id');
                $t->string('tipo', 12);
                $t->decimal('valor', 18, 2);
                $t->string('motivo', 300);
                $t->date('data');
                $t->string('meio_id', 60)->nullable();
                $t->string('conta_transitoria', 20)->nullable();
                $t->string('conta_contrapartida', 20)->nullable();
                $t->unsignedBigInteger('operador_id')->nullable();
                $t->string('nome_operador', 150)->nullable();
                $t->string('estado', 12)->default('ATIVO');
                $t->timestampTz('anulado_em')->nullable();
                $t->string('anulado_por', 150)->nullable();
                $t->string('motivo_anulacao', 300)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_movimentos_caixa_pos_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('sessao_pos_id', 'fk_movimentos_caixa_pos_sessao_pos_id')->references('id')->on('sessoes_pos')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE movimentos_caixa_pos ADD CONSTRAINT ck_movimentos_caixa_pos_tipo CHECK (tipo IN ('SUPRIMENTO','SANGRIA'))");
            DB::statement("ALTER TABLE movimentos_caixa_pos ADD CONSTRAINT ck_movimentos_caixa_pos_estado CHECK (estado IN ('ATIVO','ANULADO'))");
            DB::statement('ALTER TABLE movimentos_caixa_pos ADD CONSTRAINT ck_movimentos_caixa_pos_valor CHECK (valor > 0)');
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_movimentos_caixa_pos_sessao ON movimentos_caixa_pos (empresa_id, sessao_pos_id)');

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON movimentos_caixa_pos TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE movimentos_caixa_pos_id_seq TO erp_app; END IF; END $$');

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        // Aditiva: não se removem tabelas nem dados.
    }
};
