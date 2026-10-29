<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Services\Plataforma\MaquinaEstadosLicenca;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DefinicoesPlataforma;
use Illuminate\Console\Command;

/**
 * Sincroniza `estado_licenca` de todas as empresas com a data de validade da licença (Fase 3 da extensão SaaS,
 * secção 9 de `segurança.txt`): sem este comando a correr periodicamente, o campo introduzido na Fase 1 só mudava
 * por acção explícita (criação, pagamento confirmado, alteração administrativa) — o simples passar do tempo nunca
 * transicionava uma empresa activa para TOLERANCIA/SUSPENSA. Idempotente (MaquinaEstadosLicenca::sincronizar() não
 * faz nada se o estado já estiver correcto) e nunca toca em estados manuais (CANCELADA/EM_ENCERRAMENTO/ENCERRADA, ou
 * SUSPENSA por motivo que não seja incumprimento) — só avança a máquina de estados por datas, nunca por decisões
 * administrativas, que só mudam por acção explícita (PlataformaSaasController::definirEstadoLicenca).
 */
final class SincronizarEstadoLicencasCommand extends Command
{
    protected $signature = 'erp:licencas:sincronizar';

    protected $description = 'Sincroniza o estado explícito da licença de cada empresa com a data de validade (trial/activa/tolerância/suspensa)';

    public function __construct(
        private readonly ContextoTenant $tenant,
        private readonly MaquinaEstadosLicenca $estadosLicenca,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! DefinicoesPlataforma::licencasAtivas()) {
            $this->info('Licenças desligadas (erp.licencas.ativo=false): nada a sincronizar.');

            return self::SUCCESS;
        }

        $total = 0;
        $alterados = 0;

        $this->tenant->porTenant(function () use (&$total, &$alterados) {
            foreach (Empresa::query()->cursor() as $empresa) {
                $total++;
                $antes = $empresa->estado_licenca;
                $this->estadosLicenca->sincronizar($empresa);
                if ($empresa->estado_licenca !== $antes) {
                    $alterados++;
                    $this->line("  {$empresa->nome}: {$antes} → {$empresa->estado_licenca}");
                }
            }
        });

        $this->info("{$total} empresa(s) verificada(s), {$alterados} com estado actualizado.");

        return self::SUCCESS;
    }
}
