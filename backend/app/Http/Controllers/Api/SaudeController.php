<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** GET /api/saude — estado das dependências (PostgreSQL, Redis). 503 se alguma falhar. */
final class SaudeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $verificacoes = [
            'base_dados' => $this->verificar(fn () => DB::selectOne('select version() as v')->v),
            'redis' => $this->verificar(fn () => 'Redis '.(Redis::connection()->info('server')['redis_version'] ?? '?')),
        ];

        $ok = collect($verificacoes)->every(fn ($v) => $v['estado'] === 'OK');

        return $ok
            ? RespostaApi::sucesso(['estado' => 'OK', 'componentes' => $verificacoes], 'Todos os serviços estão operacionais.')
            : RespostaApi::erro('Um ou mais serviços estão indisponíveis.', 503, 'SERVICO_INDISPONIVEL', $verificacoes);
    }

    /** @return array{estado: string, detalhe: string, latencia_ms: float} */
    private function verificar(callable $teste): array
    {
        $inicio = microtime(true);
        try {
            $detalhe = (string) $teste();
            $estado = 'OK';
        } catch (Throwable $e) {
            $detalhe = config('app.debug') ? $e->getMessage() : 'indisponível';
            $estado = 'FALHA';
        }

        return ['estado' => $estado, 'detalhe' => $detalhe, 'latencia_ms' => round((microtime(true) - $inicio) * 1000, 2)];
    }
}
