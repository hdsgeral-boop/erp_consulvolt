<?php

namespace App\Console\Commands;

use App\Mail\LicencaAVencerMail;
use App\Mail\LicencaExpiradaMail;
use App\Models\Empresa;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DefinicoesPlataforma;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa o administrador de cada empresa antes (7 e 3 dias) e no dia em que a licença expira (ADR-074). Corre uma vez
 * por dia (ver routes/console.php); por tenant (ContextoTenant::porTenant — RLS de `empresas`/`utilizadores`), nunca
 * duplica (compara exactamente os dias restantes com [7, 3, 0], não um intervalo, por isso só dispara no dia certo).
 */
final class AvisarLicencasCommand extends Command
{
    /** Dias antes da expiração em que se avisa; 0 = no próprio dia (LicencaExpiradaMail). */
    private const DIAS_AVISO = [7, 3, 0];

    protected $signature = 'erp:licencas:avisar';

    protected $description = 'Avisa por email os administradores de empresas com a licença a expirar em breve ou expirada hoje';

    public function __construct(private readonly ContextoTenant $tenant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! DefinicoesPlataforma::licencasAtivas()) {
            $this->info('Licenças desligadas (erp.licencas.ativo=false): nada a avisar.');

            return self::SUCCESS;
        }

        $hoje = now()->startOfDay();
        $total = 0;

        $this->tenant->porTenant(function () use ($hoje, &$total) {
            $empresas = Empresa::query()->where('estado', Empresa::ESTADO_ATIVO)->whereNotNull('licenca_fim')->get();
            foreach ($empresas as $empresa) {
                // diffInDays devolve float (ex.: 3.0); in_array estrito com os inteiros de DIAS_AVISO exige o cast.
                $dias = (int) $hoje->diffInDays($empresa->licenca_fim->copy()->startOfDay(), false);
                if (! in_array($dias, self::DIAS_AVISO, true)) {
                    continue;
                }
                $admin = Utilizador::query()
                    ->whereIn('papel', [Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_SUPER_ADMINISTRADOR])
                    ->whereNotNull('email')->orderBy('id')->first();
                if (! $admin?->email) {
                    continue;
                }
                $nome = $admin->nome_completo ?: $admin->nome_utilizador;
                $mail = $dias === 0
                    ? new LicencaExpiradaMail($nome, $empresa->nome)
                    : new LicencaAVencerMail($nome, $empresa->nome, $dias, $empresa->licenca_fim->toDateString());
                Mail::to($admin->email)->send($mail);
                $total++;
            }
        });

        $this->info("{$total} aviso(s) de licença enviado(s).");

        return self::SUCCESS;
    }
}
