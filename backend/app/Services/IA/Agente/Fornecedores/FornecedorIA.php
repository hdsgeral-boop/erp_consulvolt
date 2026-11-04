<?php

namespace App\Services\IA\Agente\Fornecedores;

/**
 * Contrato comum dos fornecedores de IA do assistente (Anthropic, OpenAI, Google Gemini).
 *
 * O histórico de cada conversa fica no formato próprio do fornecedor (o que a API devolveu, tal e qual), para o
 * reenviar sem perdas (blocos de raciocínio, assinaturas, resultados de pesquisa). O agente só fala com esta
 * interface: ferramentas descritas uma vez (nome, descrição, JSON Schema), chamadas e resultados normalizados.
 */
interface FornecedorIA
{
    /**
     * Mensagem do utilizador no formato do fornecedor.
     *
     * @param  list<array{nome: string, mime: string, base64?: string, texto?: string}>  $anexos  imagens/PDF em base64; folhas e texto já convertidos para `texto`
     * @return list<array<string, mixed>> mensagens a acrescentar ao histórico
     */
    public function mensagemUtilizador(string $texto, array $anexos): array;

    /**
     * Um passo da conversa.
     *
     * @param  list<array<string, mixed>>  $historico  mensagens no formato do fornecedor
     * @param  list<array{nome: string, descricao: string, parametros: array<string, mixed>}>  $ferramentas
     */
    public function conversar(string $modelo, string $sistema, array $historico, array $ferramentas, bool $pesquisaWeb): RespostaIA;

    /**
     * Resultados das ferramentas pedidas no passo anterior, no formato do fornecedor.
     *
     * @param  list<array{id: string, nome: string, conteudo: string, erro?: bool}>  $resultados
     * @return list<array<string, mixed>>
     */
    public function resultados(array $resultados): array;

    /** Verifica a chave e o modelo com um pedido mínimo (lança excepção se falhar). */
    public function testar(string $modelo): void;
}
