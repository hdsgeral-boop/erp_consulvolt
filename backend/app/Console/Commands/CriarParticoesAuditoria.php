<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cria as partições anuais de logs_auditoria em falta (ano corrente + N seguintes).
 * Se a partição DEFAULT já tiver linhas desse ano, move-as na mesma transacção
 * (o PostgreSQL recusa criar a partição enquanto a DEFAULT contiver linhas do intervalo).
 * Agendado anualmente em routes/console.php.
 */
final class CriarParticoesAuditoria extends Command
{
    protected $signature = 'erp:auditoria:particoes {--anos=2 : Número de anos futuros a preparar} {--desde= : Primeiro ano (por omissão, o corrente)}';

    protected $description = 'Cria as partições anuais em falta da tabela logs_auditoria';

    public function handle(): int
    {
        $inicio = (int) ($this->option('desde') ?: now()->year);
        $fim = now()->year + (int) $this->option('anos');

        for ($ano = $inicio; $ano <= $fim; $ano++) {
            $tabela = "logs_auditoria_{$ano}";
            if ($this->existe($tabela)) {
                $this->line("  {$tabela}: já existe");

                continue;
            }

            DB::transaction(function () use ($ano, $tabela) {
                $de = "{$ano}-01-01 00:00:00+00";
                $ate = ($ano + 1).'-01-01 00:00:00+00';

                DB::statement('CREATE TEMP TABLE _mover_logs ON COMMIT DROP AS
                               SELECT * FROM logs_auditoria_padrao WHERE ocorrido_em >= ? AND ocorrido_em < ?', [$de, $ate]);
                DB::delete('DELETE FROM logs_auditoria_padrao WHERE ocorrido_em >= ? AND ocorrido_em < ?', [$de, $ate]);
                DB::statement("CREATE TABLE {$tabela} PARTITION OF logs_auditoria FOR VALUES FROM ('{$de}') TO ('{$ate}')");
                $movidas = DB::affectingStatement('INSERT INTO logs_auditoria SELECT * FROM _mover_logs');

                $this->info("  {$tabela}: criada".($movidas ? " ({$movidas} linhas movidas da partição DEFAULT)" : ''));
            });
        }

        return self::SUCCESS;
    }

    private function existe(string $tabela): bool
    {
        return DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS existe', [$tabela])->existe;
    }
}
