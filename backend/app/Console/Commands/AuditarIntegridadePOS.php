<?php

namespace App\Console\Commands;

use App\Support\POS\IntegridadePOS;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * F0 do POS: auditoria SÓ DE LEITURA da integridade das tabelas do POS (duplicados nas chaves de numeração, valores fora das
 * listas de estado, órfãos nas chaves estrangeiras em falta). É o que decide se as migrações `2026_12_05_09*` criam ou não
 * cada restrição: onde houver linhas a corrigir a restrição fica para depois e este comando diz quais.
 * Não altera nem apaga dados; não mostra dados pessoais (só ids, códigos de numeração e valores de enumerações).
 * Sai com 1 se alguma restrição estiver bloqueada por dados (útil em verificações de deploy); `--json` para máquinas.
 */
final class AuditarIntegridadePOS extends Command
{
    protected $signature = 'erp:pos:auditar-integridade
        {--json : Devolve o relatório em JSON}
        {--database= : Ligação a usar (por omissão a do dono da base, para ler todas as empresas apesar da RLS)}';

    protected $description = 'Audita (só leitura) duplicados, estados inválidos e órfãos que impedem as restrições de integridade do POS';

    public function handle(): int
    {
        $ligacao = $this->option('database') ?: config('erp.saas.ligacao_dono');
        $auditor = new IntegridadePOS(DB::connection($ligacao));
        $restricoes = $auditor->auditar();
        $indices = $auditor->auditarIndices();
        $bloqueadas = array_values(array_filter($restricoes, fn ($r) => $r['problemas'] !== []));

        if ($this->option('json')) {
            $this->line(json_encode(['restricoes' => $restricoes, 'indices' => $indices, 'bloqueadas' => count($bloqueadas)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $bloqueadas ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['Restrição', 'Tabela', 'Tipo', 'Estado', 'Linhas a corrigir'], array_map(fn ($r) => [$r['nome'], $r['tabela'], $r['tipo'], $r['estado'], array_sum(array_column($r['problemas'], 'linhas'))], $restricoes));
        $this->line('Índices de desempenho: '.implode(', ', array_map(fn ($i) => "{$i['nome']} [{$i['estado']}]", $indices)));
        foreach ($bloqueadas as $r) {
            $this->warn("{$r['nome']} ({$r['tabela']}) — {$r['porque']}");
            foreach ($r['problemas'] as $p) {
                $this->line(sprintf('   %s: %d linha(s); ids %s%s', $p['chave'], $p['linhas'], implode(',', $p['ids']), $p['linhas'] > count($p['ids']) ? ' …' : ''));
            }
        }
        if (! $bloqueadas) {
            $this->info('Nada a corrigir: as restrições em falta podem ser criadas (php artisan migrate) e as existentes estão válidas.');

            return self::SUCCESS;
        }
        $this->error(count($bloqueadas).' restrição(ões) bloqueada(s) por dados. Corrija as linhas indicadas (sem apagar vendas: renumere/ajuste o estado) e volte a correr este comando e php artisan migrate.');

        return self::FAILURE;
    }
}
