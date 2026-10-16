<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fecho da Versão Actual (ADR-071) — RH, itens #8 e #10 do caderno 04_rh_salarios.md. Migração ADITIVA e com guardas
 * (numa base gerada de novo as duas colunas já vêm do gerador — campos_codigo_legado.json/TIPOS_FORCADOS — e só corre a cópia;
 * pode correr sobre uma base já migrada):
 *   - contratos_trabalho.motivo_termo (text) e periodo_experimental_dias (integer): minuta do contrato pela Lei n.º 12/23
 *     (art. 13.º/2-3 — razões do contrato a termo; art. 18.º — período experimental). Legado: contracts.motivo_termo e
 *     contracts.periodo_experimental_dias (js/app_v2.js:5063-5068);
 *   - cargos_funcoes: o antigo `descricao` valia como responsabilidades (window.funcaoResponsabilidades, app_v2.js:4013);
 *     copia-se para `responsabilidades` onde esta estiver vazia (as colunas objetivo_geral/responsabilidades já existem).
 * As percentagens dos subsídios de férias e de Natal ficam na configuração de RH (configuracoes_rh, chave/valor): sem colunas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contratos_trabalho')) {
            Schema::table('contratos_trabalho', function (Blueprint $t) {
                if (! Schema::hasColumn('contratos_trabalho', 'motivo_termo')) {
                    $t->text('motivo_termo')->nullable()->comment('legado: motivo_termo · razões determinantes do contrato a termo (art. 13.º/2 da Lei 12/23)');
                }
                if (! Schema::hasColumn('contratos_trabalho', 'periodo_experimental_dias')) {
                    $t->integer('periodo_experimental_dias')->nullable()->comment('legado: periodo_experimental_dias · período experimental (art. 18.º; vazio = 60 sem termo, 30 a termo)');
                }
            });
        }

        if (Schema::hasTable('cargos_funcoes') && Schema::hasColumn('cargos_funcoes', 'responsabilidades') && Schema::hasColumn('cargos_funcoes', 'descricao')) {
            DB::statement("UPDATE cargos_funcoes SET responsabilidades = descricao
                           WHERE (responsabilidades IS NULL OR btrim(responsabilidades) = '') AND descricao IS NOT NULL AND btrim(descricao) <> ''");
        }
    }

    public function down(): void
    {
        // a cópia das responsabilidades não se desfaz (o `descricao` mantém-se intacto)
        if (Schema::hasTable('contratos_trabalho')) {
            Schema::table('contratos_trabalho', function (Blueprint $t) {
                foreach (['motivo_termo', 'periodo_experimental_dias'] as $c) {
                    if (Schema::hasColumn('contratos_trabalho', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
