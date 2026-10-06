<?php

namespace App\Services\Integracoes\IA;

use App\Exceptions\ErroNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo da Messages API da Anthropic (POST /v1/messages) para o assistente IA (decisão 26).
 *
 * Feito sobre o cliente HTTP do Laravel (e não sobre o SDK PHP oficial `anthropic-ai/sdk`) por dois motivos registados no
 * ADR: (1) o serviço precisa de uma única chamada de extracção estruturada — o SDK traria uma dependência nova ao
 * composer.lock partilhado a meio da ronda; (2) os testes usam o cliente HTTP falso do Laravel (Http::fake), sem chamadas
 * reais. Migrar para o SDK oficial é directo (mesmo corpo do pedido) e fica como melhoria.
 *
 * Pedido: modelo de config/assistente_ia.php (Claude Opus 5.5 — pensamento adaptativo sempre activo, sem parâmetro
 * `thinking`), `output_config.effort` e `output_config.format` (json_schema: a resposta é JSON válido no esquema das
 * propostas), prompt de sistema em cache (`cache_control`: o plano de contas repete-se entre pedidos da mesma empresa) e
 * `fallbacks: "default"` (beta server-side-fallback-2026-07-01: se os classificadores de segurança recusarem, o servidor
 * repete com o modelo de recurso adequado). Recusa final (`stop_reason: refusal`) e corte por `max_tokens` dão erro claro.
 */
final class ClienteClaude
{
    public const BETA_FALLBACK = 'server-side-fallback-2026-07-01';

    public function configurado(): bool
    {
        return trim((string) config('assistente_ia.chave')) !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $conteudo  blocos do turno do utilizador (document/image/text)
     * @param  array<string, mixed>  $esquema  JSON Schema da resposta
     * @return array{dados: array<string, mixed>, modelo: string, tokens_entrada: int, tokens_saida: int}
     */
    public function extrair(string $sistema, array $conteudo, array $esquema): array
    {
        if (! $this->configurado()) {
            throw new ErroNegocio('O assistente IA não está configurado no servidor (falta a variável ANTHROPIC_API_KEY).', 'IA_SEM_CHAVE', 422);
        }
        $corpo = [
            'model' => (string) config('assistente_ia.modelo'),
            'max_tokens' => (int) config('assistente_ia.max_tokens'),
            'system' => [['type' => 'text', 'text' => $sistema, 'cache_control' => ['type' => 'ephemeral']]],
            'output_config' => ['effort' => (string) config('assistente_ia.esforco'), 'format' => ['type' => 'json_schema', 'schema' => $esquema]],
            'fallbacks' => 'default',
            'messages' => [['role' => 'user', 'content' => $conteudo]],
        ];
        try {
            $r = Http::timeout((int) config('assistente_ia.tempo_limite'))
                ->withHeaders(['x-api-key' => (string) config('assistente_ia.chave'), 'anthropic-version' => (string) config('assistente_ia.versao_api'),
                    'anthropic-beta' => self::BETA_FALLBACK, 'content-type' => 'application/json'])
                ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 529], true)), throw: false)
                ->post((string) config('assistente_ia.url'), $corpo);
        } catch (ConnectionException) {
            throw new ErroNegocio('Não foi possível contactar o serviço de IA (sem ligação ou tempo limite excedido).', 'IA_INDISPONIVEL', 502);
        }
        if (! $r->successful()) {
            $s = $r->status();
            [$codigo, $msg] = match (true) {
                $s === 401, $s === 403 => ['IA_CHAVE_INVALIDA', 'A chave do serviço de IA foi recusada. Verifique ANTHROPIC_API_KEY no servidor.'],
                $s === 429 => ['IA_LIMITE', 'O serviço de IA está com limite de pedidos. Tente dentro de alguns minutos.'],
                $s === 400, $s === 413 => ['IA_PEDIDO_INVALIDO', 'O serviço de IA recusou o pedido (documento demasiado grande ou formato não suportado).'],
                default => ['IA_INDISPONIVEL', "O serviço de IA respondeu com erro {$s}. Tente mais tarde."],
            };
            throw new ErroNegocio($msg, $codigo, 502);
        }
        $j = $r->json();
        $stop = $j['stop_reason'] ?? null;
        if ($stop === 'refusal') {
            throw new ErroNegocio('O serviço de IA recusou analisar este conteúdo. Lance manualmente ou use uma regra interna.', 'IA_RECUSA', 422);
        }
        if ($stop === 'max_tokens') {
            throw new ErroNegocio('A resposta do serviço de IA ficou incompleta. Reduza o documento ou o texto e tente de novo.', 'IA_RESPOSTA_INCOMPLETA', 502);
        }
        $texto = collect($j['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        $dados = json_decode($texto, true);
        if (! is_array($dados)) {
            throw new ErroNegocio('A resposta do serviço de IA não veio no formato esperado.', 'IA_RESPOSTA_INVALIDA', 502);
        }
        $uso = $j['usage'] ?? [];

        return ['dados' => $dados, 'modelo' => (string) ($j['model'] ?? $corpo['model']),
            'tokens_entrada' => (int) ($uso['input_tokens'] ?? 0) + (int) ($uso['cache_creation_input_tokens'] ?? 0) + (int) ($uso['cache_read_input_tokens'] ?? 0),
            'tokens_saida' => (int) ($uso['output_tokens'] ?? 0)];
    }
}
