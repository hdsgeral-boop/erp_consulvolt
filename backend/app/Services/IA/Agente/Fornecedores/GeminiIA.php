<?php

namespace App\Services\IA\Agente\Fornecedores;

use App\Exceptions\ErroNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini pela API generateContent (v1beta), sem SDK. O histórico guarda os `contents` tal como vêm (com as
 * assinaturas de pensamento, que o Gemini exige de volta nas chamadas de funções).
 *
 * Pesquisa na internet: a ferramenta nativa (google_search) não se combina com funções no mesmo pedido em todos os
 * modelos, por isso o agente recebe uma função `pesquisar_internet`, executada aqui com um pedido à parte só com o
 * google_search (texto + fontes), cujo resultado volta como resposta da função.
 */
final class GeminiIA implements FornecedorIA
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public const FERRAMENTA_PESQUISA = 'pesquisar_internet';

    private string $modeloActual = '';

    public function __construct(private readonly string $chave) {}

    public function mensagemUtilizador(string $texto, array $anexos): array
    {
        $partes = [];
        foreach ($anexos as $a) {
            if (isset($a['texto'])) {
                $partes[] = ['text' => "Anexo «{$a['nome']}»:\n".$a['texto']];
            } elseif ($a['mime'] === 'application/pdf' || str_starts_with($a['mime'], 'image/')) {
                $partes[] = ['inlineData' => ['mimeType' => $a['mime'], 'data' => $a['base64']]];
            }
        }
        $partes[] = ['text' => $texto];

        return [['role' => 'user', 'parts' => $partes]];
    }

    public function conversar(string $modelo, string $sistema, array $historico, array $ferramentas, bool $pesquisaWeb): RespostaIA
    {
        $this->modeloActual = $modelo;
        $declaracoes = array_map(fn (array $f) => ['name' => $f['nome'], 'description' => $f['descricao'], 'parameters' => $f['parametros']], $ferramentas);
        if ($pesquisaWeb) {
            $declaracoes[] = ['name' => self::FERRAMENTA_PESQUISA, 'description' => 'Pesquisa na internet (Google) e devolve um resumo com as fontes. Usar para informação actual ou externa ao ERP.',
                'parameters' => ['type' => 'object', 'properties' => ['consulta' => ['type' => 'string', 'description' => 'O que pesquisar']], 'required' => ['consulta']]];
        }
        $m = $this->pedir($modelo, array_filter([
            'systemInstruction' => ['parts' => [['text' => $sistema]]],
            'contents' => $historico,
            'tools' => $declaracoes ? [['functionDeclarations' => $declaracoes]] : null,
            'generationConfig' => ['maxOutputTokens' => 16000],
        ], fn ($v) => $v !== null));

        $cand = $m['candidates'][0] ?? [];
        $conteudo = $cand['content'] ?? ['role' => 'model', 'parts' => []];
        $conteudo['role'] = 'model';
        $texto = '';
        $chamadas = [];
        foreach ($conteudo['parts'] ?? [] as $i => $p) {
            if (isset($p['text']) && empty($p['thought'])) {
                $texto .= ($texto === '' ? '' : "\n\n").$p['text'];
            }
            if (isset($p['functionCall'])) {
                $chamadas[] = ['id' => (string) ($p['functionCall']['id'] ?? $p['functionCall']['name'].'#'.$i), 'nome' => (string) $p['functionCall']['name'],
                    'args' => (array) ($p['functionCall']['args'] ?? [])];
            }
        }
        $u = $m['usageMetadata'] ?? [];
        $recusa = in_array($cand['finishReason'] ?? '', ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST'], true) ? 'O modelo recusou responder a este pedido.' : null;

        return new RespostaIA(
            texto: $texto,
            chamadas: $chamadas,
            historico: $conteudo['parts'] ? [$conteudo] : [],
            tokensEntrada: (int) ($u['promptTokenCount'] ?? 0),
            tokensSaida: (int) (($u['candidatesTokenCount'] ?? 0) + ($u['thoughtsTokenCount'] ?? 0)),
            recusa: $recusa,
        );
    }

    /**
     * Pesquisa na internet com o google_search (pedido à parte). Devolve [texto, fontes, tokens de entrada, tokens de saída].
     *
     * @return array{0: string, 1: list<array{titulo: string, url: string}>, 2: int, 3: int}
     */
    public function pesquisar(string $consulta): array
    {
        $m = $this->pedir($this->modeloActual, [
            'contents' => [['role' => 'user', 'parts' => [['text' => $consulta]]]],
            'tools' => [['google_search' => new \stdClass]],
            'generationConfig' => ['maxOutputTokens' => 4000],
        ]);
        $cand = $m['candidates'][0] ?? [];
        $texto = implode("\n", array_map(fn ($p) => (string) ($p['text'] ?? ''), $cand['content']['parts'] ?? []));
        $fontes = array_values(array_filter(array_map(fn ($c) => isset($c['web']['uri']) ? ['titulo' => (string) ($c['web']['title'] ?? $c['web']['uri']), 'url' => (string) $c['web']['uri']] : null,
            $cand['groundingMetadata']['groundingChunks'] ?? [])));
        $u = $m['usageMetadata'] ?? [];

        return [$texto, $fontes, (int) ($u['promptTokenCount'] ?? 0), (int) (($u['candidatesTokenCount'] ?? 0) + ($u['thoughtsTokenCount'] ?? 0))];
    }

    public function resultados(array $resultados): array
    {
        return [['role' => 'user', 'parts' => array_map(fn (array $r) => ['functionResponse' => array_filter([
            'id' => str_contains($r['id'], '#') ? null : $r['id'],
            'name' => $r['nome'],
            'response' => ['resultado' => json_decode($r['conteudo'], true) ?? $r['conteudo']],
        ], fn ($v) => $v !== null)], $resultados)]];
    }

    public function testar(string $modelo): void
    {
        $this->pedir($modelo, ['contents' => [['role' => 'user', 'parts' => [['text' => 'Responde apenas: ok']]]], 'generationConfig' => ['maxOutputTokens' => 64]], true);
    }

    /**
     * @param  bool  $detalhado  só no teste de ligação (ecrã da plataforma): mostra o motivo devolvido pelo Google; no chat dos clientes a mensagem é genérica
     * @return array<string, mixed>
     */
    private function pedir(string $modelo, array $corpo, bool $detalhado = false): array
    {
        try {
            $r = Http::withHeaders(['x-goog-api-key' => $this->chave])->acceptJson()->timeout(180)->post(sprintf(self::URL, rawurlencode($modelo)), $corpo);
        } catch (ConnectionException $e) {
            throw new ErroNegocio($detalhado ? 'Teste falhou (Gemini): sem ligação ao fornecedor. Verifique a rede do servidor.' : 'Não foi possível contactar o serviço de IA. Tente novamente.', 'IA_FORNECEDOR_ERRO', 502);
        }
        if ($r->failed()) {
            throw new ErroNegocio($detalhado ? 'Teste falhou (Gemini): '.($r->json('error.message') ?: 'HTTP '.$r->status())
                : 'O serviço de IA não conseguiu processar o pedido. Tente novamente ou contacte a plataforma.', 'IA_FORNECEDOR_ERRO', 502);
        }

        return (array) $r->json();
    }
}
