<?php

use App\Support\POS\IntegridadePOS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F0 do POS (docs/paridade/POS_ACTUAL_INVENTARIO.md D-01): UNIQUE por empresa nas chaves de numeração do POS
 * (código da sessão, número do Z, ordem e recibo da lavandaria, código da peça), UNIQUE (empresa_id) na configuração da
 * lavandaria, nome de mesa sem distinguir maiúsculas e uma conta de mesa por venda.
 *
 * SEGURA com dados reais: cada índice só é criado se a auditoria (IntegridadePOS, o mesmo que o comando
 * `erp:pos:auditar-integridade`) não encontrar duplicados; senão fica por criar, regista aviso no log e NÃO falha o deploy.
 * Idempotente (IF NOT EXISTS; correr de novo depois de corrigir os dados cria o que faltava). Nunca altera nem apaga dados.
 * Os índices únicos não precisam de RLS nem GRANT próprios (herdam os da tabela).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new IntegridadePOS(DB::connection()))->aplicar(['unico']);
    }

    public function down(): void
    {
        foreach (IntegridadePOS::definicoes() as $d) {
            if ($d['tipo'] === 'unico') {
                DB::statement("DROP INDEX IF EXISTS {$d['nome']}");
            }
        }
    }
};
