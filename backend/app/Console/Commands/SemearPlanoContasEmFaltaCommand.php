<?php

namespace App\Console\Commands;

use App\Services\Contabilidade\ServicoPlanoContasModelo;
use Illuminate\Console\Command;

/**
 * Correcção pontual (da v6): empresas sem nenhuma conta em `plano_contas` recebem a estrutura do plano modelo
 * (ServicoPlanoContasModelo). Idempotente: nunca toca numa empresa que já tenha alguma conta.
 */
final class SemearPlanoContasEmFaltaCommand extends Command
{
    protected $signature = 'erp:contabilidade:semear-plano-contas-em-falta';

    protected $description = 'Semeia a estrutura do plano de contas modelo nas empresas que ainda não têm nenhuma conta';

    public function handle(ServicoPlanoContasModelo $servico): int
    {
        $feitas = $servico->semearEmFalta();
        if (! $feitas) {
            $this->info('Nenhuma empresa em falta (ou sem empresa modelo definida).');

            return self::SUCCESS;
        }
        foreach ($feitas as $empresa => $contas) {
            $this->info("Empresa #{$empresa}: {$contas} conta(s).");
        }

        return self::SUCCESS;
    }
}
