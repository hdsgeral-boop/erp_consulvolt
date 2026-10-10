<?php

namespace App\Console\Commands;

use App\Services\Migracao\ServicoCorrecoesPosMigracao;
use App\Support\Cache\LimpezaCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Aplica (ou simula, com --simular) as correcções de dados herdados do legado (ADR-070): movimentos de stock num
 * armazém de outra empresa e notas de crédito que abatem a factura. A migração já as aplica; este comando serve para
 * os dados já migrados. Idempotente; cada correcção fica nos logs de auditoria (CORRECAO_MIGRACAO).
 */
final class CorrigirDadosMigracao extends Command
{
    protected $signature = 'erp:migracao:corrigir-dados {--simular : mostra o que seria corrigido e desfaz tudo (ROLLBACK)}';

    protected $description = 'Corrige dados herdados do legado: movimentos de stock em armazém de outra empresa e notas de crédito que abatem a factura';

    public function handle(ServicoCorrecoesPosMigracao $servico): int
    {
        $simular = (bool) $this->option('simular');
        DB::beginTransaction();
        try {
            $r = $servico->aplicar();
            $simular ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('NÃO APLICADA: '.$e->getMessage());

            return self::FAILURE;
        }
        $a = $r['armazens'];
        $n = $r['notas_credito'];
        $this->line("Movimentos de stock em armazém de outra empresa: {$a['reatribuidos']} passado(s) para a empresa dona, {$a['eliminados']} eliminado(s), {$a['acertos']} acerto(s) de saldo.");
        $this->line("Notas de crédito: {$n['facturas']} factura(s) abatida(s), {$n['notas']} NC sem pendente.");
        if ($simular) {
            $this->info('Simulação: nada foi gravado (ROLLBACK).');
        } else {
            $this->line('Cache: '.LimpezaCache::limparTudo());
            $this->info('Correcções gravadas; registo em Sistema › Validações › «Correcções automáticas de dados do legado».');
        }

        return self::SUCCESS;
    }
}
