<?php

namespace App\Console\Commands;

use App\Services\Contabilidade\ServicoPlanoContasModelo;
use Illuminate\Console\Command;

/**
 * Backfill (correcção pontual): empresas sem nenhuma conta própria em `plano_contas` — normalmente criadas antes de
 * ServicoPlanoContasModelo existir, fora dos dois pontos normais de criação (ServicoGestaoEmpresas::criar() e
 * RegistoEmpresaController::store()) — recebem a cópia do plano de contas modelo. Idempotente: nunca toca numa
 * empresa que já tenha alguma conta (ainda que incompleta ou já personalizada pela empresa).
 */
final class SemearPlanoContasEmFaltaCommand extends Command
{
    protected $signature = 'erp:contabilidade:semear-plano-contas-em-falta';

    protected $description = 'Semeia o plano de contas modelo nas empresas que ainda não têm nenhuma conta própria';

    public function handle(ServicoPlanoContasModelo $servico): int
    {
        $semeadas = $servico->semearEmFalta();

        if (! $semeadas) {
            $this->info('Nenhuma empresa em falta: todas já têm plano de contas.');

            return self::SUCCESS;
        }

        $this->info(count($semeadas).' empresa(s) semeada(s): '.implode(', ', $semeadas).'.');

        return self::SUCCESS;
    }
}
