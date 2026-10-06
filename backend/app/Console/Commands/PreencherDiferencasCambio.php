<?php

namespace App\Console\Commands;

use App\Services\Tesouraria\ServicoConfigTesouraria;
use Illuminate\Console\Command;

/**
 * Decisão 19 do utilizador: pré-preenche as contas de diferenças de câmbio do legado (6621 favoráveis / 7621 desfavoráveis)
 * nas configurações de tesouraria e de compras das empresas que as têm no plano. Idempotente. A migração do backup do legado
 * (erp:migrar-backup-legado) já o aplica depois de carregar os planos de contas (passo pós-carga, ADR-068).
 */
final class PreencherDiferencasCambio extends Command
{
    protected $signature = 'erp:tesouraria:diferencas-cambio {--empresa= : só esta empresa}';

    protected $description = 'Pré-preenche as contas de diferenças de câmbio 6621/7621 (tesouraria e compras) onde estão vazias';

    public function handle(): int
    {
        $r = ServicoConfigTesouraria::preencherDiferencasCambio($this->option('empresa') ? (int) $this->option('empresa') : null);
        $this->info("Configurações preenchidas: {$r['preenchidas']}.");
        if ($r['empresas_sem_contas']) {
            $this->warn('Empresas sem as contas 6621/7621 de movimento no plano (configurar à mão): '.implode(', ', $r['empresas_sem_contas']).'.');
        }

        return self::SUCCESS;
    }
}
