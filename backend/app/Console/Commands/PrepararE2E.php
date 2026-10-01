<?php

namespace App\Console\Commands;

use Database\Seeders\E2E\E2ESeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Prepara a base dos testes ponta-a-ponta (Playwright) com dados FICTÍCIOS: migrate:fresh + seeder E2E.
 *
 * Protecção: só corre se a base configurada terminar em "_e2e" (ex.: erp_consulvolt_e2e, serviço app_e2e do
 * docker-compose.e2e.yml). Nunca toca na base de desenvolvimento erp_consulvolt (dados reais migrados).
 * Se a base ainda não existir, cria-a a partir da base de manutenção "postgres".
 */
final class PrepararE2E extends Command
{
    protected $signature = 'erp:e2e:preparar {--force : Não pedir confirmação}';

    protected $description = 'Recria a base E2E (*_e2e) com migrate:fresh e dados fictícios de demonstração';

    public function handle(): int
    {
        $ligacao = (string) config('database.default');
        $base = (string) config("database.connections.{$ligacao}.database");
        if (! str_ends_with($base, '_e2e') || ! preg_match('/^[a-z0-9_]+$/', $base)) {
            $this->error("RECUSADO: a base configurada é '{$base}'. Este comando só corre numa base cujo nome termine em '_e2e'.");
            $this->line('Use o serviço app_e2e: docker compose -f docker-compose.yml -f docker-compose.e2e.yml exec -T app_e2e php artisan erp:e2e:preparar --force');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Apagar e recriar TODA a base '{$base}'?")) {
            return self::FAILURE;
        }

        $this->criarBaseSeNecessario($ligacao, $base);

        $this->info("1/3 migrate:fresh em {$base}");
        $codigo = $this->call('migrate:fresh', ['--force' => true]);
        if ($codigo !== self::SUCCESS) {
            return $codigo;
        }

        // a cache (Redis, prefixo próprio do ambiente E2E) guarda acessos e menus de utilizadores com ids reaproveitados
        // (o flush do Redis limpa a base lógica inteira: só se não for a 0/1 do ambiente de desenvolvimento)
        try {
            if (config('cache.default') !== 'redis' || ! in_array((string) config('database.redis.cache.database'), ['0', '1'], true)) {
                Cache::flush();
            } else {
                $this->warn('Cache não limpa: a base lógica Redis da cache é a do desenvolvimento (defina REDIS_CACHE_DB).');
            }
        } catch (Throwable $e) {
            $this->warn('Não foi possível limpar a cache: '.$e->getMessage());
        }

        $this->info('2/3 dados fictícios (Database\\Seeders\\E2E\\E2ESeeder)');
        $codigo = $this->call('db:seed', ['--class' => E2ESeeder::class, '--force' => true]);
        if ($codigo !== self::SUCCESS) {
            return $codigo;
        }

        $this->info('3/3 concluído. Utilizadores de teste (palavra-passe comum: '.E2ESeeder::PALAVRA_PASSE.'):');
        foreach (E2ESeeder::UTILIZADORES as $nome => $descricao) {
            $this->line("  - {$nome}: {$descricao}");
        }

        return self::SUCCESS;
    }

    private function criarBaseSeNecessario(string $ligacao, string $base): void
    {
        $cfg = config("database.connections.{$ligacao}");
        $pdo = new PDO("pgsql:host={$cfg['host']};port={$cfg['port']};dbname=postgres", $cfg['username'], $cfg['password']);
        $existe = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $existe->execute([$base]);
        if ($existe->fetchColumn()) {
            return;
        }
        $this->info("A criar a base {$base}");
        $pdo->exec("CREATE DATABASE \"{$base}\" ENCODING 'UTF8' TEMPLATE template0");
        DB::purge($ligacao);
    }
}
