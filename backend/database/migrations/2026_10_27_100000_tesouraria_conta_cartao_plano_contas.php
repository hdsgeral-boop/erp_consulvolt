<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conta-cartão de tesouraria: uma conta da classe 3 (dívida a terceiros) pode ser marcada como "de tesouraria, do
 * tipo cartão" — um cartão de crédito/débito da empresa liquidado por um banco, não dinheiro disponível. Espelha o
 * padrão já existente para os dados bancários de 42/43 (2026_10_13_220100_fa_tesouraria_dados_bancarios_plano):
 * campos nullable, sem FK (o banco é texto livre, sugerido a partir de `bancos`), guardas hasColumn.
 *
 * `cartao_numero`: só os últimos dígitos do cartão — o número completo (PAN) nunca deve ficar registado no ERP
 * (aviso no formulário, não imposto aqui porque não há forma fiável de distinguir formatos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plano_contas')) {
            return;
        }
        Schema::table('plano_contas', function (Blueprint $t) {
            if (! Schema::hasColumn('plano_contas', 'cartao_tesouraria')) {
                $t->boolean('cartao_tesouraria')->default(false);
            }
            if (! Schema::hasColumn('plano_contas', 'cartao_numero')) {
                $t->string('cartao_numero', 20)->nullable()->comment('só os últimos dígitos; nunca o PAN completo');
            }
            if (! Schema::hasColumn('plano_contas', 'cartao_banco')) {
                $t->string('cartao_banco', 100)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('plano_contas', function (Blueprint $t) {
            foreach (['cartao_tesouraria', 'cartao_numero', 'cartao_banco'] as $coluna) {
                if (Schema::hasColumn('plano_contas', $coluna)) {
                    $t->dropColumn($coluna);
                }
            }
        });
    }
};
