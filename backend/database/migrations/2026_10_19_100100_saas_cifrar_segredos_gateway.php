<?php

use App\Services\Pagamentos\SegredosGateway;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cifra os segredos já existentes em `configuracoes_gateway` (chaves API e segredos de webhook) sem os perder.
 * Idempotente: valores vazios ou já cifrados (prefixo `enc:`) são ignorados.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('configuracoes_gateway')) {
            return;
        }

        $linhas = DB::table('configuracoes_gateway')->whereIn('chave', SegredosGateway::CHAVES_SECRETAS)->get(['chave', 'valor']);
        foreach ($linhas as $linha) {
            $valor = (string) $linha->valor;
            if ($valor === '' || str_starts_with($valor, SegredosGateway::PREFIXO)) {
                continue;
            }
            DB::table('configuracoes_gateway')->where('chave', $linha->chave)->update(['valor' => SegredosGateway::cifrar($valor)]);
        }
    }

    public function down(): void
    {
        // Intencionalmente vazio: nunca reverter para texto simples.
    }
};
