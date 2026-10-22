<?php

namespace App\Services\IA\Agente\Fornecedores;

/** Um passo do modelo, normalizado: texto, chamadas de ferramentas, as mensagens a guardar no histórico e o consumo. */
final class RespostaIA
{
    /**
     * @param  list<array{id: string, nome: string, args: array<string, mixed>}>  $chamadas
     * @param  list<array<string, mixed>>  $historico  mensagens do assistente a acrescentar, no formato do fornecedor
     * @param  list<array{titulo: string, url: string}>  $fontes  páginas consultadas na internet
     * @param  bool  $continuar  o fornecedor pausou a vez (ex.: pesquisa longa) e pede para continuar sem nova mensagem
     */
    public function __construct(
        public readonly string $texto,
        public readonly array $chamadas,
        public readonly array $historico,
        public readonly int $tokensEntrada,
        public readonly int $tokensSaida,
        public readonly int $pesquisas = 0,
        public readonly array $fontes = [],
        public readonly bool $continuar = false,
        public readonly ?string $recusa = null,
    ) {}
}
