<?php

namespace App\Services\Sistema;

use App\Services\Integracoes\Cambios\ServicoCambiosBAIAutomaticos;
use App\Support\Cache\Batimentos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Estado das dependências (base de dados, Redis, filas, armazenamento, batimentos do scheduler/worker), partilhado
 * por `GET /api/saude` (público, para healthchecks de infraestrutura) e `GET /api/plataforma/monitorizacao` (Fase 7
 * da extensão SaaS, segurança.txt secção 16: a mesma informação, mas só para perfis de back-office — nunca expor
 * internals de infraestrutura aos clientes).
 */
final class ServicoSaude
{
    /** Filas consumidas pelo worker (docker-compose*.yml), por prioridade. */
    private const FILAS = ['alta', 'agt', 'default', 'pdfs', 'baixa'];

    /** @return array<string, array{estado: string, detalhe: string, latencia_ms: float}> */
    public function verificacoes(bool $versoes): array
    {
        return [
            'base_dados' => $this->verificar(function () use ($versoes) {
                $versao = DB::selectOne('show server_version')->server_version;

                return $versoes ? "PostgreSQL {$versao}" : 'PostgreSQL';
            }),
            'redis' => $this->verificar(function () use ($versoes) {
                $versao = Redis::connection()->info('server')['redis_version'] ?? '?';

                return $versoes ? "Redis {$versao}" : 'Redis';
            }),
            'filas' => $this->verificar(fn () => $this->estadoFilas()),
            'armazenamento' => $this->verificar(fn () => $this->estadoArmazenamento()),
            // batimentos do scheduler e do worker (R11); a mensagem não tem segredos e mostra-se sempre (excepto se a cache falhar)
            'processos' => $this->verificar(fn () => Batimentos::estado(), mostrarErro: true),
        ];
    }

    /** @return array<string, array{estado: string, detalhe: string}> estados informativos (uma falha aqui não dá 503) */
    public function informacao(): array
    {
        try {
            $bai = app(ServicoCambiosBAIAutomaticos::class)->informacaoSaude();
        } catch (Throwable) {
            $bai = ['estado' => 'DESCONHECIDO', 'detalhe' => 'indisponível'];
        }

        try {
            $outbox = app(ServicoOutbox::class)->saude();
        } catch (Throwable) {
            $outbox = ['estado' => 'DESCONHECIDO', 'detalhe' => 'indisponível'];
        }

        // Outbox (ADR-080): AVISO se o pendente mais antigo tem mais de 10 min ou há eventos MORTOS — informativo, nunca dá 503.
        return ['cambios_bai' => $bai, 'outbox' => $outbox];
    }

    /** Versão da imagem (ficheiro VERSAO gravado no build de produção); "dev" fora das imagens de produção. */
    public function versao(): string
    {
        $ficheiro = base_path('VERSAO');

        return is_file($ficheiro) ? (trim((string) file_get_contents($ficheiro)) ?: 'desconhecida') : 'dev';
    }

    /** Tamanho de cada fila e trabalhos falhados (informativo: uma fila longa não torna o serviço indisponível). */
    private function estadoFilas(): string
    {
        $ligacao = Queue::connection();
        $tamanhos = collect(self::FILAS)->map(fn (string $fila) => $fila.'='.$ligacao->size($fila))->implode(', ');
        $falhados = DB::table((string) config('queue.failed.table', 'trabalhos_falhados'))->count();

        return config('queue.default')." ({$tamanhos}); falhados={$falhados}";
    }

    private function estadoArmazenamento(): string
    {
        foreach (['app' => storage_path('app'), 'cache' => storage_path('framework/cache')] as $nome => $pasta) {
            if (! is_dir($pasta) || ! is_writable($pasta)) {
                throw new RuntimeException("storage/{$nome} sem permissão de escrita");
            }
        }

        return 'gravável';
    }

    /** @return array{estado: string, detalhe: string, latencia_ms: float} */
    private function verificar(callable $teste, bool $mostrarErro = false): array
    {
        $inicio = microtime(true);
        try {
            $detalhe = (string) $teste();
            $estado = 'OK';
        } catch (Throwable $e) {
            $detalhe = $mostrarErro && $e::class === RuntimeException::class ? MensagemSegura::sanitizar($e->getMessage(), 503) : 'indisponível';
            $estado = 'FALHA';
        }

        return ['estado' => $estado, 'detalhe' => $detalhe, 'latencia_ms' => round((microtime(true) - $inicio) * 1000, 2)];
    }
}
