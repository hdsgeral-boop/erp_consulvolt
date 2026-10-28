<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de precificação SaaS — ciclos de facturação com desconto progressivo (Mensal/Trimestral/Semestral/Anual,
 * CalculadoraPrecoPlano). `subscricoes_empresa.valor` continua a ser o valor final a cobrar; as colunas novas
 * guardam o detalhe do cálculo tal como foi aplicado no momento (a tabela de ciclos, em config/erp.php, pode mudar
 * no futuro sem invalidar o que já foi pago).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscricoes_empresa', function (Blueprint $t) {
            $t->string('ciclo_codigo', 20)->default('MENSAL')->after('meses');
            $t->decimal('valor_bruto', 14, 2)->default(0)->after('valor');
            $t->decimal('desconto_ciclo_percentagem', 5, 2)->default(0)->after('valor_bruto');
            $t->decimal('valor_desconto_ciclo', 14, 2)->default(0)->after('desconto_ciclo_percentagem');
            $t->decimal('valor_apos_ciclo', 14, 2)->default(0)->after('valor_desconto_ciclo');
        });

        DB::statement("ALTER TABLE subscricoes_empresa ADD CONSTRAINT ck_subscricoes_empresa_ciclo_codigo "
            ."CHECK (ciclo_codigo IN ('MENSAL','TRIMESTRAL','SEMESTRAL','ANUAL','PERSONALIZADO'))");

        // Subscrições já existentes nunca tiveram desconto: valor_bruto e valor_apos_ciclo ficam iguais a valor.
        DB::statement('UPDATE subscricoes_empresa SET valor_bruto = valor, valor_apos_ciclo = valor');
    }

    public function down(): void
    {
        Schema::table('subscricoes_empresa', function (Blueprint $t) {
            $t->dropColumn(['ciclo_codigo', 'valor_bruto', 'desconto_ciclo_percentagem', 'valor_desconto_ciclo', 'valor_apos_ciclo']);
        });
    }
};
