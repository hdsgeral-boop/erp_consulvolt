<?php

namespace App\Services\Vendas\Agt;

use App\Exceptions\ErroNegocio;
use App\Models\ComunicacaoAgt;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Cliente HTTP dos serviços da AGT (POST JSON, autenticação Basic — doc-agt «api.html»):
 *   - TLS verificado e só HTTPS; tempo de ligação e tempo total limitados (AGT_TIMEOUT_LIGACAO / AGT_TIMEOUT);
 *   - retentativas com recuo exponencial e «jitter» só quando a falha é transitória: sem ligação, tempo esgotado ou
 *     500/502/503/504. Um 4xx é a resposta da AGT (erros de validação) e devolve-se sem repetir; o 429 (E98) também
 *     não se repete aqui — o ciclo adia a operação;
 *   - cada tentativa fica em agt_comunicacoes com o pedido mascarado (MascaraAgt), a resposta, o HTTP, a duração e o
 *     código de erro.
 * Um pedido que falha por tempo esgotado pode ter chegado à AGT: quem chama reenvia com o MESMO submissionUUID
 * (idempotência; a AGT devolve o requestID original a um pedido duplicado — doc-agt «registar.html», requestID).
 */
final class ClienteHttpAgt
{
    private const TRANSITORIOS = [500, 502, 503, 504];

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * @param  array{submission_uuid?: ?string, documentos?: list<string>, request_id?: ?string}  $meta
     * @return array{http: int, resposta: array, tentativas: int}
     */
    public function post(LigacaoAgt $ligacao, string $operacao, array $pedido, array $meta = []): array
    {
        if (! $ligacao->temCredenciais()) {
            throw new ErroNegocio('As credenciais de acesso aos serviços da AGT não estão configuradas (empresa ou servidor).', 'SEM_CREDENCIAIS', 503);
        }
        $url = $ligacao->url.$operacao;
        $maximo = max(1, (int) config('erp.agt.retentativas', 3));
        for ($tentativa = 1; ; $tentativa++) {
            $inicio = microtime(true);
            try {
                $r = Http::withBasicAuth((string) $ligacao->utilizador, (string) $ligacao->palavraPasse)
                    ->withOptions(['verify' => true])
                    ->acceptJson()->asJson()
                    ->connectTimeout(max(1, (int) config('erp.agt.timeout_ligacao', 10)))
                    ->timeout(max(1, (int) config('erp.agt.timeout', 30)))
                    ->post($url, $pedido);
            } catch (ConnectionException $e) {
                $this->registar($ligacao, $operacao, $url, $pedido, null, null, $inicio, $tentativa, $meta, 'SEM_LIGACAO', $e->getMessage());
                if ($tentativa < $maximo) {
                    $this->esperar($tentativa);

                    continue;
                }
                throw new ErroNegocio('Não foi possível contactar a AGT ('.mb_substr($e->getMessage(), 0, 200).'). O documento fica em contingência e é reenviado automaticamente.',
                    'AGT_INDISPONIVEL', 502, ['incerto' => true]);
            }
            $json = $this->json($r);
            $this->registar($ligacao, $operacao, $url, $pedido, $r->status(), $json, $inicio, $tentativa, $meta);
            if (in_array($r->status(), self::TRANSITORIOS, true)) {
                if ($tentativa < $maximo) {
                    $this->esperar($tentativa, $r);

                    continue;
                }
                throw new ErroNegocio("A AGT respondeu com erro {$r->status()} (serviço indisponível). O documento fica em contingência e é reenviado automaticamente.",
                    'AGT_INDISPONIVEL', 502, ['incerto' => true, 'http' => $r->status()]);
            }

            return ['http' => $r->status(), 'resposta' => $json, 'tentativas' => $tentativa];
        }
    }

    /** Recuo exponencial com «jitter»: aleatório entre metade e o tecto base·2^(n-1), limitado a $maxMs. */
    public static function atraso(int $tentativa, int $baseMs, int $maxMs): int
    {
        $teto = min($maxMs, $baseMs * (2 ** max(0, $tentativa - 1)));

        return $teto <= 0 ? 0 : random_int(intdiv($teto, 2), $teto);
    }

    private function esperar(int $tentativa, ?Response $r = null): void
    {
        $max = (int) config('erp.agt.recuo_max_ms', 8000);
        $ms = self::atraso($tentativa, (int) config('erp.agt.recuo_base_ms', 500), $max);
        $retryAfter = $r?->header('Retry-After');
        if ($retryAfter !== null && $retryAfter !== '' && ctype_digit((string) $retryAfter)) {
            $ms = max($ms, min((int) $retryAfter * 1000, $max));
        }
        if ($ms > 0) {
            Sleep::usleep($ms * 1000);
        }
    }

    private function json(Response $r): array
    {
        $json = $r->json();

        return is_array($json) ? $json : ['respostaNaoJSON' => mb_substr($r->body(), 0, 2000)];
    }

    private function registar(LigacaoAgt $l, string $operacao, string $url, array $pedido, ?int $http, ?array $resposta, float $inicio, int $tentativa,
        array $meta, ?string $codigo = null, ?string $mensagem = null): void
    {
        $duracao = (int) round((microtime(true) - $inicio) * 1000);
        $erros = is_array($resposta['errorList'] ?? null) ? array_values($resposta['errorList']) : [];
        $primeiro = is_array($erros[0] ?? null) ? $erros[0] : [];
        $codigo ??= $http !== null && $http >= 400 ? ((string) ($primeiro['idError'] ?? $primeiro['errorCode'] ?? '') ?: "HTTP_{$http}") : null;
        $mensagem ??= $primeiro ? (string) ($primeiro['descriptionError'] ?? $primeiro['errorDescription'] ?? '') : null;
        try {
            $this->contexto->executarComo($l->empresaId, fn () => ComunicacaoAgt::create([
                'operacao' => $operacao, 'ambiente' => $l->ambiente, 'endpoint' => mb_substr(parse_url($url, PHP_URL_PATH) ?: $url, 0, 255), 'http_estado' => $http,
                'sucesso' => $http !== null && $http < 400, 'codigo_erro' => $codigo ? mb_substr($codigo, 0, 60) : null,
                'mensagem' => $mensagem ? mb_substr($mensagem, 0, 1000) : null,
                'duracao_ms' => $duracao, 'tentativa' => $tentativa,
                'submission_uuid' => $meta['submission_uuid'] ?? (isset($pedido['submissionUUID']) ? (string) $pedido['submissionUUID'] : null),
                'request_id' => isset($resposta['requestID']) ? mb_substr((string) $resposta['requestID'], 0, 40) : ($meta['request_id'] ?? null),
                'documentos' => $meta['documentos'] ?? null,
                'pedido' => MascaraAgt::limitar(MascaraAgt::aplicar($pedido)),
                'resposta' => MascaraAgt::limitar(MascaraAgt::aplicar($resposta)),
                'utilizador' => Auth::user()?->nome_utilizador,
            ]));
        } catch (Throwable $e) {
            report($e);   // o registo nunca impede a comunicação
        }
        Log::info("AGT {$operacao} empresa={$l->empresaId} http=".($http ?? '-')." tentativa={$tentativa} {$duracao}ms");
    }
}
