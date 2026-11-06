<?php

namespace App\Console\Commands;

use App\Services\POS\ServicoMigracaoLavandaria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F0b do POS — modo `completar` do ETL da lavandaria: preenche, sobre dados JÁ migrados, o que a carga original perdeu
 * (opções de preço lav_prices, modo/tamanho das linhas e de service_prices, contadores FR do terminal). Idempotente e sem
 * sobrescrever o que foi editado no sistema novo. Com o backup Dexie original lê lav_prices e lav_fr_counters; sem ele
 * reconstrói as opções de unidade+preço (origem DERIVADA) e traduz o que já está na base. `--simular` desfaz no fim.
 */
final class CompletarMigracaoLavandaria extends Command
{
    protected $signature = 'erp:lavandaria:completar-migracao
        {backup? : Ficheiro JSON do backup Dexie do legado (lê lav_prices e lav_fr_counters)}
        {--simular : Executa tudo e desfaz no fim (ROLLBACK)}
        {--json : Imprime o resultado em JSON}
        {--database= : Ligação a usar (por omissão a do dono da base)}';

    protected $description = 'Completa (idempotente) os dados da lavandaria já migrados: opções de preço, modo/tamanho, contadores FR';

    public function handle(): int
    {
        $ligacao = $this->option('database') ?: config('erp.saas.ligacao_dono');
        if ($ligacao) {
            DB::setDefaultConnection($ligacao);
        }
        try {
            $legado = $this->argument('backup') ? ServicoMigracaoLavandaria::legadoDoBackup($this->argument('backup')) : null;
            $r = app(ServicoMigracaoLavandaria::class)->completar($legado, (bool) $this->option('simular'));
        } catch (Throwable $e) {
            $this->error('Falhou — nada foi gravado (ROLLBACK): '.$e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Indicador', 'Valor'], collect($r)->except('erros')->map(fn ($v, $k) => [str_replace('_', ' ', $k), is_bool($v) ? ($v ? 'sim' : 'não') : $v])->values()->all());
            foreach (array_slice($r['erros'], 0, 50) as $e) {
                $this->warn("{$e['tabela']} #{$e['id']}: {$e['motivo']}");
            }
            $this->line(count($r['erros']).' ocorrência(s) a rever.'.($r['simulacao'] ? ' [SIMULAÇÃO: base intacta]' : ''));
        }

        return self::SUCCESS;
    }
}
