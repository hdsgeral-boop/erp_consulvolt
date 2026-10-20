<?php

namespace App\Console\Commands;

use App\Services\Sistema\ServicoEmpresas;
use Illuminate\Console\Command;

/**
 * Invalida (nova versão da chave) as listas de empresas acessíveis guardadas em cache por utilizador.
 * Corre a cada deploy: uma lista calculada sem contexto de empresa (ex.: antes da correção do login sob RLS) ficaria
 * vazia até expirar. Não toca em mais nada do cache nem na fila.
 */
final class InvalidarCacheEmpresas extends Command
{
    protected $signature = 'erp:empresas:invalidar-cache';

    protected $description = 'Invalida as listas de empresas acessíveis em cache (por utilizador)';

    public function handle(ServicoEmpresas $empresas): int
    {
        $empresas->invalidarTodos();
        $this->info('Listas de empresas em cache invalidadas.');

        return self::SUCCESS;
    }
}
