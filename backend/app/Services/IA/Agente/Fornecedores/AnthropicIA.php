<?php

namespace App\Services\IA\Agente\Fornecedores;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Exceptions\ErroNegocio;

/**
 * Anthropic (Claude) pelo SDK oficial para PHP (anthropic-ai/sdk). O histórico guarda-se no formato do fio (o
 * jsonSerialize das respostas) e reenvia-se tal e qual — inclui os blocos de raciocínio e os resultados da pesquisa.
 *
 *  - pesquisa na internet: ferramenta do servidor web_search_20260209 (a Anthropic pesquisa e devolve as fontes);
 *  - pensamento adaptativo (por omissão nos modelos actuais) e esforço `medium`;
 *  - cache de prompt: instruções de sistema e o prefixo da conversa (cacheControl automático);
 *  - recusas: nos modelos que o suportam, fallback do lado do servidor («default», beta server-side-fallback);
 *  - pause_turn (pesquisa longa): o agente continua a mesma vez com o histórico tal como está.
 */
final class AnthropicIA implements FornecedorIA
{
    /** Modelos com fallback do lado do servidor na forma «default». */
    private const COM_FALLBACK = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    private readonly Client $cliente;

    public function __construct(string $chave)
    {
        $this->cliente = new Client(apiKey: $chave);
    }

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
        $tools = array_map(fn (array $f) => ['name' => $f['nome'], 'description' => $f['descricao'], 'inputSchema' => $f['parametros']], $ferramentas);
        if ($pesquisaWeb) {
            $tools[] = ['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => 5];
        }
        $comFallback = in_array($modelo, self::COM_FALLBACK, true);

        try {
            $resposta = $this->cliente->beta->messages->create(
                model: $modelo,
                maxTokens: 16000,
                system: [['type' => 'text', 'text' => $sistema, 'cacheControl' => ['type' => 'ephemeral']]],
                cacheControl: ['type' => 'ephemeral'],
                outputConfig: ['effort' => 'medium'],
                tools: $tools ?: null,
                messages: $historico,
                fallbacks: $comFallback ? 'default' : null,
                betas: $comFallback ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (APIException $e) {
            throw new ErroNegocio('O fornecedor de IA (Anthropic) recusou o pedido: '.$e->getMessage(), 'IA_FORNECEDOR_ERRO', 502);
        }

        $m = json_decode((string) json_encode($resposta), true);
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
        try {
            $this->cliente->messages->create(model: $modelo, maxTokens: 16, messages: [['role' => 'user', 'content' => 'Responde apenas: ok']], outputConfig: ['effort' => 'low']);
        } catch (APIException $e) {
            throw new ErroNegocio('Teste falhou (Anthropic): '.$e->getMessage(), 'IA_TESTE_FALHOU', 422);
        }
    }
}
