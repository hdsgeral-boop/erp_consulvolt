<?php

namespace App\Console\Commands;

use App\Mail\LicencaAVencerMail;
use App\Mail\LicencaExpiradaMail;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Support\Email\EnviarEmail;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Avisa os administradores de cada CLIENTE 7 e 3 dias antes e no dia em que a licença expira (ADR-074, revisto: a licença é
 * do cliente). Corre uma vez por dia (routes/console.php). Só dispara nos dias exactos [7, 3, 0], por isso nunca repete, e
 * envia um único aviso por cliente, mesmo que tenha várias empresas. Destinatários: administradores activos, com e-mail.
 */
final class AvisarLicencasCommand extends Command
{
    /** Dias antes do fim em que se avisa; 0 = no próprio dia (LicencaExpiradaMail). */
    private const DIAS_AVISO = [7, 3, 0];

    protected $signature = 'erp:licencas:avisar';

    protected $description = 'Avisa por e-mail os administradores dos clientes com a licença a expirar em breve ou a expirar hoje';

    public function handle(ContextoTenant $tenant): int
    {
        if (! config('erp.licencas.ativo')) {
            $this->info('Licenças desligadas: nada a avisar.');

            return self::SUCCESS;
        }

        $hoje = now()->startOfDay();
        $total = 0;
        $clientes = Tenant::query()->where('estado', Tenant::ESTADO_ATIVO)->whereNotNull('licenca_fim')
            ->where('licenca_origem', '<>', Tenant::LICENCA_SEM_LIMITE)
            ->where('id', '<>', (int) config('erp.dominio.plataforma_tenant_id', 1))->orderBy('id')->get();

        foreach ($clientes as $cliente) {
            $dias = (int) $hoje->diffInDays($cliente->licenca_fim->copy()->startOfDay(), false);
            if (! in_array($dias, self::DIAS_AVISO, true)) {
                continue;
            }
            $tenant->executarComo((int) $cliente->id, function () use ($cliente, $dias, &$total) {
                $admins = Utilizador::query()->where('tenant_id', $cliente->id)
                    ->whereIn('papel', [Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_SUPER_ADMINISTRADOR])
                    ->where('ativo', true)->whereNotNull('email')->orderBy('id')->get();
                foreach ($admins as $admin) {
                    $nome = $admin->nome_completo ?: $admin->nome_utilizador;
                    EnviarEmail::para($admin->email, $dias === 0
                        ? new LicencaExpiradaMail($nome, $cliente->nome)
                        : new LicencaAVencerMail($nome, $cliente->nome, $dias, $cliente->licenca_fim->toDateString()));
                    $total++;
                }
            });
        }

        $this->info("{$total} aviso(s) de licença enviado(s).");

        return self::SUCCESS;
    }
}
