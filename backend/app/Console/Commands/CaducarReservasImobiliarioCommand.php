<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Services\Imobiliario\ServicoReservasFinanceiro;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Caducidade das reservas de áreas comuns (Onda 3, F4; agendada de 15 em 15 minutos). Por empresa activa: a reserva a aguardar pagamento cujo
 * prazo passou é confirmada se afinal está tudo pago, ou cancelada (libertando o horário, com nota de crédito da factura emitida) se não
 * recebeu nada; com pagamento parcial fica sinalizada para decisão humana. Repetível e isolada: a falha de uma empresa não impede as restantes.
 */
final class CaducarReservasImobiliarioCommand extends Command
{
    protected $signature = 'erp:imobiliario:caducar-reservas';

    protected $description = 'Caduca as reservas de áreas comuns com o prazo de pagamento esgotado';

    public function handle(ContextoTenant $tenant, ContextoEmpresa $contexto): int
    {
        $total = ['caducadas' => 0, 'confirmadas' => 0, 'parciais' => 0, 'falhas' => 0];
        $erros = 0;
        $tenant->porTenant(function () use ($contexto, &$total, &$erros) {
            foreach (Empresa::query()->where('estado', Empresa::ESTADO_ATIVO)->orderBy('id')->pluck('id') as $empresa) {
                try {
                    $contexto->executarComo((int) $empresa, function () use (&$total) {
                        foreach (app(ServicoReservasFinanceiro::class)->caducarPendentes() as $k => $n) {
                            $total[$k] += $n;
                        }
                    });
                } catch (Throwable $e) {
                    $erros++;
                    report($e);
                    $this->error("Empresa {$empresa}: falha ao caducar reservas.");
                }
            }
        });
        $this->info("Reservas: {$total['caducadas']} caducada(s), {$total['confirmadas']} confirmada(s), {$total['parciais']} com pagamento parcial, {$total['falhas']} falha(s).");

        return $erros > 0 || $total['falhas'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
