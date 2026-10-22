<?php

namespace App\Services\IA\Agente\Fornecedores;

use App\Exceptions\ErroNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI pela Responses API (POST /v1/responses), sem SDK (não há SDK oficial para PHP). Sem guardar nada do lado
 * da OpenAI (store=false): o histórico (itens de entrada/saída, incluindo o raciocínio cifrado) fica connosco e é
 * reenviado em cada passo. Pesquisa na internet: ferramenta nativa `web_search`; funções: `function` / `function_call_output`.
 */
final class OpenAIIA implements FornecedorIA
{
    private const URL = 'https://api.openai.com/v1/responses';

    public function __construct(private readonly string $chave) {}

    public function mensagemUtilizador(string $texto, array $anexos): array
    {
        $conteudo = [];
        foreach ($anexos as $a) {
            if (isset($a['texto'])) {
                $conteudo[] = ['type' => 'input_text', 'text' => "Anexo «{$a['nome']}»:\n".$a['texto']];
            } elseif ($a['mime'] === 'application/pdf') {
                $conteudo[] = ['type' => 'input_file', 'filename' => $a['nome'], 'file_data' => 'data:application/pdf;base64,'.$a['base64']];
            } elseif (str_starts_with($a['mime'], 'image/')) {
                $conteudo[] = ['type' => 'input_image', 'image_url' => "data:{$a['mime']};base64,{$a['base64']}"];
            }
        }
        $conteudo[] = ['type' => 'input_text', 'text' => $texto];

        return [['role' => 'user', 'content' => $conteudo]];
    }

    public function conversar(string $modelo, string $sistema, array $historico, array $ferramentas, bool $pesquisaWeb): RespostaIA
    {
        $tools = array_map(fn (array $f) => ['type' => 'function', 'name' => $f['nome'], 'description' => $f['descricao'], 'parameters' => $f['parametros']], $ferramentas);
        if ($pesquisaWeb) {
            $tools[] = ['type' => 'web_search'];
        }
        $m = $this->pedir(array_filter([
            'model' => $modelo, 'instructions' => $sistema, 'input' => $historico, 'tools' => $tools ?: null,
            'store' => false, 'include' => ['reasoning.encrypted_content'], 'max_output_tokens' => 16000,
        ], fn ($v) => $v !== null));

        $saida = $m['output'] ?? [];
        $texto = '';
        $chamadas = [];
        $fontes = [];
        $pesquisas = 0;
        foreach ($saida as $item) {
            switch ($item['type'] ?? '') {
                case 'message':
                    foreach ($item['content'] ?? [] as $c) {
                        if (($c['type'] ?? '') === 'output_text') {
                            $texto .= ($texto === '' ? '' : "\n\n").($c['text'] ?? '');
                            foreach ($c['annotations'] ?? [] as $a) {
                                if (($a['type'] ?? '') === 'url_citation') {
                                    $fontes[] = ['titulo' => (string) ($a['title'] ?? $a['url']), 'url' => (string) $a['url']];
                                }
                            }
                        }
                    }
                    break;
                case 'function_call':
                    $chamadas[] = ['id' => (string) $item['call_id'], 'nome' => (string) $item['name'], 'args' => (array) (json_decode((string) ($item['arguments'] ?? '{}'), true) ?: [])];
                    break;
                case 'web_search_call':
                    $pesquisas++;
                    break;
            }
        }
        $u = $m['usage'] ?? [];

        return new RespostaIA(
            texto: $texto,
            chamadas: $chamadas,
            historico: array_values($saida),
            tokensEntrada: (int) ($u['input_tokens'] ?? 0),
            tokensSaida: (int) ($u['output_tokens'] ?? 0),
            pesquisas: $pesquisas,
            fontes: $fontes,
            recusa: null,
        );
    }

    public function resultados(array $resultados): array
    {
        return array_map(fn (array $r) => ['type' => 'function_call_output', 'call_id' => $r['id'], 'output' => $r['conteudo']], $resultados);
    }

    public function testar(string $modelo): void
    {
        $this->pedir(['model' => $modelo, 'input' => 'Responde apenas: ok', 'store' => false, 'max_output_tokens' => 64]);
    }

    /** @return array<string, mixed> */
    private function pedir(array $corpo): array
    {
        try {
            $r = Http::withToken($this->chave)->acceptJson()->timeout(180)->post(self::URL, $corpo);
        } catch (ConnectionException $e) {
            throw new ErroNegocio('Não foi possível contactar o serviço de IA. Tente novamente.', 'IA_FORNECEDOR_ERRO', 502);
        }
        if ($r->failed()) {
            throw new ErroNegocio('O serviço de IA não conseguiu processar o pedido. Tente novamente ou contacte a plataforma.', 'IA_FORNECEDOR_ERRO', 502);
        }

        return (array) $r->json();
    }
}
