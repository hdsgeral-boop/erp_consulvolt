<?php

namespace App\Console\Commands;

use App\Services\Tesouraria\ServicoRecebimentosVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ronda 3 (r3_teso): cria o recebimento de tesouraria dos recibos de venda que ainda não o têm (recibos emitidos antes
 * da ligação recibo → tesouraria). Idempotente (só trata recibos sem documento ligado; o índice único impede duplicados);
 * cada recibo na sua transacção; com --simular nada é gravado (cada criação é desfeita) e o relatório mostra o que seria
 * feito. O espelho não cria lançamentos: um recibo contabilizado fica INTEGRADO com o lançamento que já tem.
 * Não mostra nomes nem NIF: só números de recibo e contagens.
 */
final class ReconciliarRecibosTesouraria extends Command
{
    protected $signature = 'erp:tesouraria:reconciliar-recibos
        {--empresa= : só esta empresa (id)}
        {--simular : não grava nada; mostra o que seria criado}
        {--limite=0 : máximo de recibos por empresa (0 = todos)}';

    protected $description = 'Cria os recebimentos de tesouraria em falta para os recibos de venda (idempotente; use --simular primeiro)';

    public function handle(ContextoEmpresa $contexto, ServicoRecebimentosVendas $servico): int
    {
        if (! ServicoRecebimentosVendas::activo()) {
            $this->error('Falta a migração 2026_10_17_094200_r3_teso_recibo_venda_tesouraria (corra php artisan migrate).');

            return self::FAILURE;
        }
        $simular = (bool) $this->option('simular');
        $empresas = DB::table('recibos_venda')->when($this->option('empresa'), fn ($q, $v) => $q->where('empresa_id', (int) $v))
            ->distinct()->orderBy('empresa_id')->pluck('empresa_id');
        $this->info(($simular ? '[SIMULAÇÃO] ' : '').'Empresas com recibos: '.$empresas->count().'.');
        $total = ['recibos' => 0, 'criados' => 0, 'sem_conta' => 0, 'erros' => 0];
        foreach ($empresas as $empresa) {
            $r = $contexto->executarComo((int) $empresa, fn () => $servico->reconciliar($simular, (int) $this->option('limite')));
            $this->line(sprintf('Empresa %d: %d recibo(s) sem registo; %s %d (pendentes %d, integrados %d, anulados %d); sem conta de tesouraria (42/43/45): %d; erros: %d.',
                $empresa, $r['recibos'], $simular ? 'a criar' : 'criados', $r['criados'], $r['por_estado']['PENDENTE'] ?? 0, $r['por_estado']['INTEGRADO'] ?? 0,
                $r['por_estado']['ANULADO'] ?? 0, $r['sem_conta_tesouraria'], count($r['erros'])));
            foreach ($r['erros'] as $e) {
                $this->warn("  {$e['recibo']}: {$e['mensagem']}");
            }
            $total['recibos'] += $r['recibos'];
            $total['criados'] += $r['criados'];
            $total['sem_conta'] += $r['sem_conta_tesouraria'];
            $total['erros'] += count($r['erros']);
        }
        $this->info(sprintf('%sTotal: %d recibo(s) sem registo; %s: %d; sem conta de tesouraria: %d; erros: %d.',
            $simular ? '[SIMULAÇÃO — nada gravado] ' : '', $total['recibos'], $simular ? 'seriam criados' : 'criados', $total['criados'], $total['sem_conta'], $total['erros']));

        return $total['erros'] ? self::FAILURE : self::SUCCESS;
    }
}
