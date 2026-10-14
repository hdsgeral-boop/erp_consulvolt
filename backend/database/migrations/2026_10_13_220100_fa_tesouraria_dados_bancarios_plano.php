<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase A (Versão Actual) — dados bancários das contas 42/43 do plano (app_v2.js:9050-9163 do legado novo).
 *
 * O gerador inferiu os tipos a partir do backup, onde quase todos os campos estavam vazios: `banco_iban` ficou INTEGER
 * (um IBAN tem letras: «AO06…») e os textos ficaram curtos de mais (titular com 20 caracteres não cabe o nome de uma
 * empresa). Aqui só se alarga — nenhum valor existente se perde (um inteiro passa a texto sem alteração).
 * Guardas hasColumn: a migração não falha numa base onde as colunas ainda não existam.
 */
return new class extends Migration
{
    /** coluna => [comprimento, campo do legado] */
    private const TEXTOS = [
        'banco_nome' => [100, 'bank_name'],
        'banco_balcao' => [100, 'bank_branch'],
        'banco_numero_conta' => [40, 'bank_account_number'],
        'banco_iban' => [34, 'bank_iban'],     // ISO 13616: máximo 34 caracteres
        'banco_swift' => [20, 'bank_swift'],   // BIC: 8 ou 11 caracteres (a coluna já tem 20 e não se encurta)
        'banco_titular' => [150, 'bank_holder'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('plano_contas')) {
            return;
        }
        foreach (self::TEXTOS as $coluna => [$comprimento, $legado]) {
            $existe = Schema::hasColumn('plano_contas', $coluna);
            // change() do Laravel 12 redefine a coluna inteira: repete-se nullable e o comentário
            Schema::table('plano_contas', function (Blueprint $t) use ($coluna, $comprimento, $legado, $existe) {
                $c = $t->string($coluna, $comprimento)->nullable()->comment("legado: {$legado}");
                $existe && $c->change();
            });
        }
    }

    public function down(): void
    {
        // Sem reversão: voltar a INTEGER/encurtar perderia IBAN e nomes já gravados.
    }
};
