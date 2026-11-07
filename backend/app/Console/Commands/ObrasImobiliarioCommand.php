<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Services\Imobiliario\ServicoObrasImoveis;
use App\Services\Imobiliario\ServicoPlanosManutencaoImo;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rotina diária das obras do imóvel (Onda 4, F5): por empresa activa, gera as ordens dos planos de manutenção e vistoria dentro da antecedência de
 * cada plano (o que o texto das permissões do legado prometia e o código dele nunca fazia) e actualiza as recepções ligadas (entregas do armazém
 * e recepções de Compras) das ordens abertas com material pedido. Repetível e isolada: gerar nunca duplica (UNIQUE plano + data) e a falha de uma
 * empresa não impede as restantes.
 */
final class ObrasImobiliarioCommand extends Command
{
    protected $signature = 'erp:imobiliario:obras';

    protected $description = 'Gera as ordens dos planos de manutenção e actualiza as recepções de material das ordens de obra';

    public function handle(ContextoTenant $tenant, ContextoEmpresa $contexto): int
    {
        $total = ['criadas' => 0, 'ordens' => 0, 'bens' => 0, 'falhas' => 0];
        $erros = 0;
        $tenant->porTenant(function () use ($contexto, &$total, &$erros) {
            foreach (Empresa::query()->where('estado', Empresa::ESTADO_ATIVO)->orderBy('id')->pluck('id') as $empresa) {
                try {
                    $contexto->executarComo((int) $empresa, function () use (&$total) {
                        $g = app(ServicoPlanosManutencaoImo::class)->gerarTodos();
                        $total['criadas'] += $g['criadas'];
                        $total['falhas'] += count($g['falhas']);
                        $s = app(ServicoObrasImoveis::class)->sincronizarAbertas();
                        $total['ordens'] += $s['ordens'];
                        $total['bens'] += $s['bens'];
                    });
                } catch (Throwable $e) {
                    $erros++;
                    report($e);
                    $this->error("Empresa {$empresa}: falha nas obras do imóvel.");
                }
            }
        });
        $this->info("Obras: {$total['criadas']} ordem(ns) criada(s) pelos planos, {$total['ordens']} ordem(ns) com recepções actualizadas, {$total['bens']} bem(ns) entrados no inventário, {$total['falhas']} falha(s).");

        return $erros > 0 || $total['falhas'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
