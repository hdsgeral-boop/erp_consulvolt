<?php

namespace App\Console\Commands;

use App\Mail\TicketSlaVioladoMail;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Support\Tenancy\DefinicoesPlataforma;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa a equipa de suporte da plataforma quando um ticket excede o prazo de SLA (Fase 6 da extensão SaaS, secção 14
 * de `segurança.txt`, ponto 269 "Alertas"). Nunca duplica o aviso: marca `sla_violado_avisado_em` ao enviar e só
 * considera tickets ainda sem esse carimbo (se o ticket for reaberto depois de resolvido e voltar a violar o SLA,
 * `atualizar()`/a reabertura automática deviam repor o campo a null para voltar a avisar — fora do âmbito desta
 * fase, que só cobre o primeiro aviso).
 */
final class SlaSuporteAvisarCommand extends Command
{
    protected $signature = 'erp:suporte:avisar-sla';

    protected $description = 'Avisa por email a equipa de suporte dos tickets que excederam o prazo de SLA';

    public function handle(): int
    {
        $emailSuporte = DefinicoesPlataforma::emailSuporte();
        if (! $emailSuporte) {
            $this->info('Sem email de suporte configurado: nada a avisar.');

            return self::SUCCESS;
        }

        $tickets = TicketSuporte::query()
            ->whereNotNull('prazo_sla')
            ->whereNotIn('estado', TicketSuporte::ESTADOS_TERMINAIS)
            ->where('prazo_sla', '<', now())
            ->whereNull('sla_violado_avisado_em')
            ->get();

        $nomes = Tenant::query()->pluck('nome', 'id');
        foreach ($tickets as $ticket) {
            Mail::to($emailSuporte)->send(new TicketSlaVioladoMail(
                $ticket->id, $nomes[$ticket->tenant_id] ?? '', $ticket->assunto, $ticket->prioridade, $ticket->prazo_sla->toDateTimeString(),
            ));
            $ticket->update(['sla_violado_avisado_em' => now()]);
        }

        $this->info("{$tickets->count()} aviso(s) de SLA violado enviado(s).");

        return self::SUCCESS;
    }
}
