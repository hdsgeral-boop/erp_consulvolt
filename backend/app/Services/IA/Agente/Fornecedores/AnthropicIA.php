<?php

namespace App\Services\IA\Agente\Fornecedores;

use App\Exceptions\ErroNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic (Claude) pela Messages API, sobre o cliente HTTP do Laravel (sem o SDK oficial: não é dependência deste
 * projecto e `Http::fake()` testa tudo sem pedidos reais — o mesmo critério do `ClienteClaude` do assistente de
 * lançamentos). O histórico guarda-se no formato do fio (os blocos de `content` devolvidos) e reenvia-se tal e qual —
 * inclui os blocos de raciocínio e os resultados da pesquisa.
 *
 *  - pesquisa na internet: ferramenta do servidor web_search_20260209 (a Anthropic pesquisa e devolve as fontes);
 *  - pensamento adaptativo (por omissão nos modelos actuais) e esforço `medium`;
 *  - cache de prompt: instruções de sistema e o prefixo da conversa;
 *  - recusas: nos modelos que o suportam, fallback do lado do servidor («default», beta server-side-fallback);
 *  - pause_turn (pesquisa longa): o agente continua a mesma vez com o histórico tal como está.
 *
 * Nunca se registam prompts nem respostas em logs; as mensagens de erro dos clientes não incluem texto do fornecedor.
 */
final class AnthropicIA implements FornecedorIA
{
    /** Modelos com fallback do lado do servidor na forma «default». */
    private const COM_FALLBACK = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    private const BETA_FALLBACK = 'server-side-fallback-2026-07-01';

    public function __construct(private readonly string $chave) {}

    public function mensagemUtilizador(string $texto, array $anexos): array
    {
        $conteudo = [];
        foreach ($anexos as $a) {
            if (isset($a['texto'])) {
                $conteudo[] = ['type' => 'text', 'text' => "Anexo «{$a['nome']}»:\n".$a['texto']];
            } elseif ($a['mime'] === 'application/pdf') {
                $conteudo[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $a['base64']], 'title' => $a['nome']];
            } elseif (str_starts_with($a['mime'], 'image/')) {
                $conteudo[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $a['mime'], 'data' => $a['base64']]];
            }
        }
        $conteudo[] = ['type' => 'text', 'text' => $texto];

        return [['role' => 'user', 'content' => $conteudo]];
    }

    public function conversar(string $modelo, string $sistema, array $historico, array $ferramentas, bool $pesquisaWeb): RespostaIA
    {
        $tools = array_map(fn (array $f) => ['name' => $f['nome'], 'description' => $f['descricao'], 'input_schema' => $f['parametros']], $ferramentas);
        if ($pesquisaWeb) {
            $tools[] = ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 5];
        }
        $comFallback = in_array($modelo, self::COM_FALLBACK, true);

        $m = $this->pedir(array_filter([
            'model' => $modelo,
            'max_tokens' => 16000,
            'system' => [['type' => 'text', 'text' => $sistema, 'cache_control' => ['type' => 'ephemeral']]],
            'cache_control' => ['type' => 'ephemeral'],
            'output_config' => ['effort' => 'medium'],
            'tools' => $tools ?: null,
            'messages' => $historico,
            'fallbacks' => $comFallback ? 'default' : null,
        ], fn ($v) => $v !== null), $comFallback);

        $blocos = $m['content'] ?? [];
        $texto = '';
        $chamadas = [];
        $fontes = [];
        foreach ($blocos as $b) {
            match ($b['type'] ?? '') {
                'text' => $texto .= ($texto === '' ? '' : "\n\n").($b['text'] ?? ''),
                'tool_use' => $chamadas[] = ['id' => (string) $b['id'], 'nome' => (string) $b['name'], 'args' => (array) ($b['input'] ?? [])],
                'web_search_tool_result' => array_push($fontes, ...array_map(fn ($r) => ['titulo' => (string) ($r['title'] ?? $r['url'] ?? ''), 'url' => (string) ($r['url'] ?? '')],
                    array_values(array_filter(is_array($b['content'] ?? null) && array_is_list($b['content']) ? $b['content'] : [], fn ($r) => ($r['type'] ?? '') === 'web_search_result')))),
                default => null,
            };
        }
        $parar = $m['stop_reason'] ?? null;
        $u = $m['usage'] ?? [];

        return new RespostaIA(
            texto: $texto,
            chamadas: $parar === 'tool_use' ? $chamadas : [],
            historico: $blocos ? [['role' => 'assistant', 'content' => $blocos]] : [],
            tokensEntrada: (int) (($u['input_tokens'] ?? 0) + ($u['cache_read_input_tokens'] ?? 0) + ($u['cache_creation_input_tokens'] ?? 0)),
            tokensSaida: (int) ($u['output_tokens'] ?? 0),
            pesquisas: (int) ($u['server_tool_use']['web_search_requests'] ?? 0),
            fontes: $fontes,
            continuar: $parar === 'pause_turn',
            recusa: $parar === 'refusal' ? (string) ($m['stop_details']['explanation'] ?? 'O modelo recusou responder a este pedido.') : null,
        );
    }

    public function resultados(array $resultados): array
    {
        // todos os resultados numa só mensagem (chamadas paralelas)
        return [['role' => 'user', 'content' => array_map(fn (array $r) => ['type' => 'tool_result', 'tool_use_id' => $r['id'], 'content' => $r['conteudo'],
            'is_error' => (bool) ($r['erro'] ?? false)], $resultados)]];
    }

    public function testar(string $modelo): void
    {
        $this->pedir(['model' => $modelo, 'max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => 'Responde apenas: ok']], 'output_config' => ['effort' => 'low']], false, true);
    }

    /**
     * @param  bool  $detalhado  só no teste de ligação (ecrã da plataforma): mostra o motivo devolvido pela Anthropic; no chat dos clientes a mensagem é genérica
     * @return array<string, mixed>
     */
    private function pedir(array $corpo, bool $comFallback, bool $detalhado = false): array
    {
        $cabecalhos = ['x-api-key' => $this->chave, 'anthropic-version' => (string) config('assistente_ia.versao_api'), 'content-type' => 'application/json'];
        if ($comFallback) {
            $cabecalhos['anthropic-beta'] = self::BETA_FALLBACK;
        }
        try {
            $r = Http::timeout(180)->withHeaders($cabecalhos)
                ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 529], true)), throw: false)
                ->post((string) config('assistente_ia.url'), $corpo);
        } catch (ConnectionException $e) {
            throw new ErroNegocio($detalhado ? 'Teste falhou (Anthropic): sem ligação à Anthropic.' : 'Não foi possível contactar o serviço de IA. Tente novamente.', 'IA_FORNECEDOR_ERRO', 502);
        }
        if ($r->failed()) {
            throw new ErroNegocio($detalhado ? 'Teste falhou (Anthropic): '.($r->json('error.message') ?: 'HTTP '.$r->status())
                : 'O serviço de IA não conseguiu processar o pedido. Tente novamente ou contacte a plataforma.', 'IA_FORNECEDOR_ERRO', 502);
        }

        return (array) $r->json();
    }
}
