<?php

use App\Support\POS\IntegridadePOS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F0 do POS (D-02): CHECK nas colunas de estado/enumeração das tabelas do POS (sessão, contabilização, liquidação, liquidações,
 * senhas, estadias, ordens, recibos e reclamações da lavandaria) com os valores que o código realmente usa; NULL continua permitido.
 *
 * SEGURA com dados reais: cada CHECK só se cria (NOT VALID, depois VALIDATE CONSTRAINT, que não bloqueia escritas) se nenhuma
 * linha tiver valor fora da lista; senão não se cria (um CHECK NOT VALID também recusaria a actualização das linhas antigas),
 * regista aviso no log e NÃO falha o deploy — `erp:pos:auditar-integridade` diz quais ids corrigir. Idempotente; um CHECK que
 * tenha ficado NOT VALID é validado quando os dados o permitirem. Nunca altera nem apaga dados.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new IntegridadePOS(DB::connection()))->aplicar(['check']);
    }

    public function down(): void
    {
        foreach (IntegridadePOS::definicoes() as $d) {
            if ($d['tipo'] === 'check') {
                DB::statement("ALTER TABLE {$d['tabela']} DROP CONSTRAINT IF EXISTS {$d['nome']}");
            }
        }
    }
};
