<?php

namespace App\Services\Integracoes\IA;

use App\Exceptions\ErroNegocio;
use App\Services\IA\Agente\ServicoConfiguracaoIA;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Extracção estruturada do assistente de Contabilidade. Fornecedor, chave, modelo e preços vêm da configuração da
 * plataforma (Plataforma › IA: atribuição do cliente ou, sem ela, o primeiro fornecedor activo), por isso trocar aí
 * troca aqui — Anthropic (Messages API abaixo), OpenAI ou Gemini (mesmo esquema JSON pedido no prompt). As variáveis do
 * servidor (ANTHROPIC_API_KEY, ERP_IA_MODELO…) só valem quando a plataforma não tem nenhum fornecedor activo.
 *
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

    private readonly ServicoConfiguracaoIA $plataforma;

    private readonly ContextoTenant $tenant;

    /** Compatível com `new ClienteClaude()` (como antes do Bloco B): sem argumentos resolve-os do contentor. */
    public function __construct(?ServicoConfiguracaoIA $plataforma = null, ?ContextoTenant $tenant = null)
    {
        $this->plataforma = $plataforma ?? app(ServicoConfiguracaoIA::class);
        $this->tenant = $tenant ?? app(ContextoTenant::class);
    }

    /**
     * O que usar neste momento: a plataforma manda; sem fornecedor activo, as variáveis do servidor (Anthropic).
     *
     * @return array{origem: string, fornecedor: string, modelo: string, chave: string, preco_entrada: float, preco_saida: float}|null
     */
    public function definicao(): ?array
    {
        $d = $this->plataforma->definicaoParaExtracao($this->tenant->id());
        $chave = $d ? $this->plataforma->chaveDe($d['fornecedor']) : null;
        if ($d && $chave) {
            return ['origem' => 'plataforma', 'chave' => $chave] + $d;
        }
        $env = trim((string) config('assistente_ia.chave'));

        return $env === '' ? null : ['origem' => 'servidor', 'fornecedor' => 'anthropic', 'modelo' => (string) config('assistente_ia.modelo'), 'chave' => $env,
            'preco_entrada' => (float) config('assistente_ia.preco_entrada_mtok'), 'preco_saida' => (float) config('assistente_ia.preco_saida_mtok')];
    }

    public function configurado(): bool
    {
        return $this->definicao() !== null;
    }

    /** Modelo em uso (para o registo de utilização e o ecrã). */
    public function modelo(): string
    {
        return $this->definicao()['modelo'] ?? (string) config('assistente_ia.modelo');
    }

    /** Custo estimado em USD de um pedido, com os preços do fornecedor em uso. */
    public function custo(int $tokensEntrada, int $tokensSaida): float
    {
        $d = $this->definicao();

        return $tokensEntrada / 1e6 * (float) ($d['preco_entrada'] ?? config('assistente_ia.preco_entrada_mtok')) + $tokensSaida / 1e6 * (float) ($d['preco_saida'] ?? config('assistente_ia.preco_saida_mtok'));
    }

    /**
     * @param  list<array<string, mixed>>  $conteudo  blocos do turno do utilizador (document/image/text)
     * @param  array<string, mixed>  $esquema  JSON Schema da resposta
     * @return array{dados: array<string, mixed>, modelo: string, tokens_entrada: int, tokens_saida: int}
     */
    public function extrair(string $sistema, array $conteudo, array $esquema): array
    {
        $d = $this->definicao();
        if ($d === null) {
            throw new ErroNegocio('O assistente IA não está configurado: a plataforma não tem nenhum fornecedor de IA activo e o servidor não tem a variável ANTHROPIC_API_KEY.', 'IA_SEM_CHAVE', 422);
        }

        return $d['fornecedor'] === 'anthropic' ? $this->extrairAnthropic($d, $sistema, $conteudo, $esquema) : $this->extrairGenerico($d, $sistema, $conteudo, $esquema);
    }

    /**
     * OpenAI e Gemini: o esquema vai no prompt e a resposta é lida como JSON (sem blocos markdown).
     *
     * @param  array<string, mixed>  $d
     * @param  list<array<string, mixed>>  $conteudo
     * @param  array<string, mixed>  $esquema
     * @return array{dados: array<string, mixed>, modelo: string, tokens_entrada: int, tokens_saida: int}
     */
    private function extrairGenerico(array $d, string $sistema, array $conteudo, array $esquema): array
    {
        $texto = '';
        $anexos = [];
        foreach ($conteudo as $b) {
            if (($b['type'] ?? '') === 'text') {
                $texto .= ($texto === '' ? '' : '

').($b['text'] ?? '');
            } elseif (in_array($b['type'] ?? '', ['document', 'image'], true)) {
                $anexos[] = ['nome' => (string) ($b['title'] ?? (($b['type'] === 'document') ? 'documento.pdf' : 'imagem')), 'mime' => (string) $b['source']['media_type'], 'base64' => (string) $b['source']['data']];
            }
        }
        $cliente = $this->plataforma->cliente($d['fornecedor']);
        $r = $cliente->conversar($d['modelo'], $sistema.'

Responde APENAS com um objecto JSON válido que cumpra este JSON Schema, sem markdown nem texto antes ou depois:
'
            .json_encode($esquema, JSON_UNESCAPED_UNICODE), $cliente->mensagemUtilizador($texto, $anexos), [], false);
        if ($r->recusa !== null) {
            throw new ErroNegocio('O serviço de IA recusou analisar este conteúdo. Lance manualmente ou use uma regra interna.', 'IA_RECUSA', 422);
        }
        $dados = self::jsonDe($r->texto);
        if ($dados === null) {
            throw new ErroNegocio('A resposta do serviço de IA não veio no formato esperado.', 'IA_RESPOSTA_INVALIDA', 502);
        }

        return ['dados' => $dados, 'modelo' => $d['modelo'], 'tokens_entrada' => $r->tokensEntrada, 'tokens_saida' => $r->tokensSaida];
    }

    /** @return array<string, mixed>|null */
    private static function jsonDe(string $texto): ?array
    {
        $texto = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($texto)));
        $dados = json_decode($texto, true);
        if (! is_array($dados) && ($a = strpos($texto, '{')) !== false && ($b = strrpos($texto, '}')) !== false && $b > $a) {
            $dados = json_decode(substr($texto, $a, $b - $a + 1), true);
        }

        return is_array($dados) ? $dados : null;
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  list<array<string, mixed>>  $conteudo
     * @param  array<string, mixed>  $esquema
     * @return array{dados: array<string, mixed>, modelo: string, tokens_entrada: int, tokens_saida: int}
     */
    private function extrairAnthropic(array $d, string $sistema, array $conteudo, array $esquema): array
    {
        $corpo = [
            'model' => $d['modelo'],
            'max_tokens' => (int) config('assistente_ia.max_tokens'),
            'system' => [['type' => 'text', 'text' => $sistema, 'cache_control' => ['type' => 'ephemeral']]],
            'output_config' => ['effort' => (string) config('assistente_ia.esforco'), 'format' => ['type' => 'json_schema', 'schema' => $esquema]],
            'fallbacks' => 'default',
            'messages' => [['role' => 'user', 'content' => $conteudo]],
        ];
        try {
            $r = Http::timeout((int) config('assistente_ia.tempo_limite'))
                ->withHeaders(['x-api-key' => $d['chave'], 'anthropic-version' => (string) config('assistente_ia.versao_api'),
                    'anthropic-beta' => self::BETA_FALLBACK, 'content-type' => 'application/json'])
                ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 529], true)), throw: false)
                ->post((string) config('assistente_ia.url'), $corpo);
        } catch (ConnectionException) {
            throw new ErroNegocio('Não foi possível contactar o serviço de IA (sem ligação ou tempo limite excedido).', 'IA_INDISPONIVEL', 502);
        }
        if (! $r->successful()) {
            $s = $r->status();
            [$codigo, $msg] = match (true) {
                $s === 401, $s === 403 => ['IA_CHAVE_INVALIDA', 'O serviço de IA está temporariamente indisponível. Contacte a plataforma.'],
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
