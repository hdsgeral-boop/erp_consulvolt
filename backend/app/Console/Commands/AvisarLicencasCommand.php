<?php

namespace App\Console\Commands;

use App\Mail\LicencaAVencerMail;
use App\Mail\LicencaExpiradaMail;
use App\Models\Empresa;
use App\Models\Utilizador;
use App\Support\Email\EnviarEmail;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Avisa os administradores de cada empresa 7 e 3 dias antes e no dia em que a licença expira (ADR-074; da v6, adaptado).
 * Corre uma vez por dia (routes/console.php), cliente a cliente (ContextoTenant::porTenant). Só dispara nos dias exactos
 * [7, 3, 0], por isso nunca repete. Destinatários: administradores activos, com e-mail, com acesso a essa empresa.
 */
final class AvisarLicencasCommand extends Command
{
    /** Dias antes do fim em que se avisa; 0 = no próprio dia (LicencaExpiradaMail). */
    private const DIAS_AVISO = [7, 3, 0];

    protected $signature = 'erp:licencas:avisar';

    protected $description = 'Avisa por e-mail os administradores das empresas com a licença a expirar em breve ou a expirar hoje';

    public function handle(ContextoTenant $tenant): int
    {
        if (! config('erp.licencas.ativo')) {
            $this->info('Licenças desligadas: nada a avisar.');

            return self::SUCCESS;
        }

        $hoje = now()->startOfDay();
        $total = 0;
        $tenant->porTenant(function () use ($hoje, &$total) {
            $empresas = Empresa::query()->where('estado', Empresa::ESTADO_ATIVO)->whereNotNull('licenca_fim')
                ->where('licenca_origem', '<>', Empresa::LICENCA_SEM_LIMITE)->get();
            foreach ($empresas as $empresa) {
                $dias = (int) $hoje->diffInDays($empresa->licenca_fim->copy()->startOfDay(), false);
                if (! in_array($dias, self::DIAS_AVISO, true)) {
                    continue;
                }
                $admins = Utilizador::query()
                    ->whereIn('papel', [Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_SUPER_ADMINISTRADOR])
                    ->where('ativo', true)->whereNotNull('email')
                    ->where(fn ($q) => $q->where('acesso_todas_empresas', true)->orWhereHas('empresas', fn ($e) => $e->where('empresas.id', $empresa->id)))
                    ->orderBy('id')->get();
                foreach ($admins as $admin) {
                    $nome = $admin->nome_completo ?: $admin->nome_utilizador;
                    EnviarEmail::para($admin->email, $dias === 0
                        ? new LicencaExpiradaMail($nome, $empresa->nome)
                        : new LicencaAVencerMail($nome, $empresa->nome, $dias, $empresa->licenca_fim->toDateString()));
                    $total++;
                }
            }
        });

        $this->info("{$total} aviso(s) de licença enviado(s).");

        return self::SUCCESS;
    }
}
