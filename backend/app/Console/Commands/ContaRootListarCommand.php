<?php

namespace App\Console\Commands;

use App\Services\Plataforma\ServicoContaRoot;
use Illuminate\Console\Command;

/** ADR-084 — lista as contas root em vigor (só leitura). */
final class ContaRootListarCommand extends Command
{
    protected $signature = 'erp:root:listar';

    protected $description = 'Lista as contas root globais em vigor (ADR-084)';

    public function handle(ServicoContaRoot $root): int
    {
        $contas = $root->raizes();
        if ($contas->isEmpty()) {
            $this->warn('Nenhuma conta root definida.');

            return self::SUCCESS;
        }
        $this->table(['ID', 'Utilizador', 'Papel', 'Tenant'], $contas->map(fn ($u) => [$u->id, $u->nome_utilizador, $u->papel, $u->tenant_id])->all());

        return self::SUCCESS;
    }
}
