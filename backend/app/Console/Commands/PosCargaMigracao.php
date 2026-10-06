<?php

namespace App\Console\Commands;

use App\Services\Migracao\ServicoMigracaoLegado;
use App\Support\Cache\LimpezaCache;
use Illuminate\Console\Command;

/**
 * Repete os passos pós-carga da migração do legado (ADR-068): tarefas novas do catálogo atribuídas aos perfis que já
 * faziam a acção e contas de diferenças de câmbio 6621/7621. A migração (erp:migrar-backup-legado) já os aplica depois
 * do COMMIT; este comando serve para os repetir se aí falharem. Idempotente.
 */
final class PosCargaMigracao extends Command
{
    protected $signature = 'erp:migracao:pos-carga';

    protected $description = 'Aplica (de novo) os passos pós-carga da migração do legado: permissões novas e contas 6621/7621';

    public function handle(): int
    {
        $r = ServicoMigracaoLegado::aplicarPosCarga();
        if (isset($r['erro'])) {
            $this->error($r['erro']);

            return self::FAILURE;
        }
        foreach ($r['permissoes_atribuidas'] as $tarefa => $n) {
            $this->line("Permissão {$tarefa}: atribuída a {$n} perfil(is).");
        }
        $this->info("Configurações de diferenças de câmbio preenchidas: {$r['diferencas_cambio']['preenchidas']}.");
        if ($r['diferencas_cambio']['empresas_sem_contas']) {
            $this->warn('Empresas sem as contas 6621/7621 de movimento no plano (configurar à mão): '.implode(', ', $r['diferencas_cambio']['empresas_sem_contas']).'.');
        }
        $this->line('Cache: '.LimpezaCache::limparTudo());

        return self::SUCCESS;
    }
}
