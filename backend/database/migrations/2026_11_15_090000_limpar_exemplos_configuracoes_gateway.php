<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A migração `saas_notificacoes_e_gateways` semeou dados de EXEMPLO nos pagamentos da plataforma (banco, titular e IBAN
 * inventados, e o nome de um cliente como titular), que o ecrã de pagamento do cliente chegava a mostrar. Apaga-os, mas
 * só se ainda forem exactamente os de exemplo: o que a plataforma já tiver preenchido não é tocado. Idempotente.
 */
return new class extends Migration
{
    private const EXEMPLOS = [
        'banco_nome' => 'Banco Angolano de Investimentos (BAI)',
        'titular_conta' => 'Consulvolt Lda.',
        'iban' => 'AO06 0040 0000 1234 5678 9012 3',
        'mcx_merchant_id' => 'CONSULVOLT_SaaS',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('configuracoes_gateway')) {
            return;
        }
        foreach (self::EXEMPLOS as $chave => $exemplo) {
            DB::table('configuracoes_gateway')->where('chave', $chave)->where('valor', $exemplo)->update(['valor' => '', 'atualizado_em' => now()]);
        }
    }

    public function down(): void
    {
        // dados de exemplo: não se repõem
    }
};
