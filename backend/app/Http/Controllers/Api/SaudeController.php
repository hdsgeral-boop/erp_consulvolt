<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integracoes\Cambios\ServicoCambiosBAIAutomaticos;
use App\Support\Api\RespostaApi;
use App\Support\Cache\Batimentos;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * GET /api/saude — estado das dependências, para healthchecks e monitorização (docs/PRODUCAO.md).
 *
 * Componentes: base de dados (PostgreSQL), Redis, filas (tamanho de cada fila e trabalhos falhados) e
 * armazenamento (storage/ gravável). Responde 503 se algum componente falhar. É pública: as mensagens
 * de erro só são mostradas em modo de depuração e nunca há segredos na resposta.
 *
 * `informacao` (só na resposta de sucesso) traz estados meramente informativos que nunca tornam o serviço indisponível:
 * a obtenção automática dos câmbios do BAI (DESACTIVADO, OK, PENDENTE ou FALHA da última obtenção).
 */
final class SaudeController extends Controller
{
    /** Filas consumidas pelo worker (docker-compose*.yml), por prioridade. */
    private const FILAS = ['alta', 'agt', 'default', 'pdfs', 'baixa'];

    public function __invoke(): JsonResponse
    {
        $verificacoes = [
            'base_dados' => $this->verificar(fn () => 'PostgreSQL '.DB::selectOne('show server_version')->server_version),
            'redis' => $this->verificar(fn () => 'Redis '.(Redis::connection()->info('server')['redis_version'] ?? '?')),
            'filas' => $this->verificar(fn () => $this->estadoFilas()),
            'armazenamento' => $this->verificar(fn () => $this->estadoArmazenamento()),
            // batimentos do scheduler e do worker (R11); a mensagem não tem segredos e mostra-se sempre (excepto se a cache falhar)
            'processos' => $this->verificar(fn () => Batimentos::estado(), mostrarErro: true),
        ];

        $ok = collect($verificacoes)->every(fn ($v) => $v['estado'] === 'OK');

        return $ok
            ? RespostaApi::sucesso(['estado' => 'OK', 'versao' => $this->versao(), 'componentes' => $verificacoes, 'informacao' => $this->informacao()], 'Todos os serviços estão operacionais.')
            : RespostaApi::erro('Um ou mais serviços estão indisponíveis.', 503, 'SERVICO_INDISPONIVEL', $verificacoes);
    }

    /** @return array<string, array{estado: string, detalhe: string}> estados informativos (uma falha aqui não dá 503) */
    private function informacao(): array
    {
        try {
            $bai = app(ServicoCambiosBAIAutomaticos::class)->informacaoSaude();
        } catch (Throwable) {
            $bai = ['estado' => 'DESCONHECIDO', 'detalhe' => 'indisponível'];
        }

        return ['cambios_bai' => $bai];
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

    /** Versão da imagem (ficheiro VERSAO gravado no build de produção); "dev" fora das imagens de produção. */
    private function versao(): string
    {
        $ficheiro = base_path('VERSAO');

        return is_file($ficheiro) ? (trim((string) file_get_contents($ficheiro)) ?: 'desconhecida') : 'dev';
    }

    /** @return array{estado: string, detalhe: string, latencia_ms: float} */
    private function verificar(callable $teste, bool $mostrarErro = false): array
    {
        $inicio = microtime(true);
        try {
            $detalhe = (string) $teste();
            $estado = 'OK';
        } catch (Throwable $e) {
            $detalhe = config('app.debug') || ($mostrarErro && $e::class === RuntimeException::class) ? $e->getMessage() : 'indisponível';
            $estado = 'FALHA';
        }

        return ['estado' => $estado, 'detalhe' => $detalhe, 'latencia_ms' => round((microtime(true) - $inicio) * 1000, 2)];
    }
}
