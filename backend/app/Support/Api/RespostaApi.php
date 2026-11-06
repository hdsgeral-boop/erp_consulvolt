<?php

namespace App\Support\Api;

use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Envelope padronizado de todas as respostas da API (directiva, secção 4):
 *
 *   { "sucesso": bool, "mensagem": string, "dados": mixed, "metadados": { empresa_id, executado_em, ... } }
 *
 * Em erro acrescenta "codigo" (identificador estável para o frontend), "erros" (por campo, quando existir) e "referencia" (5xx).
 */
final class RespostaApi
{
    public static function sucesso(mixed $dados = null, string $mensagem = 'Operação executada com sucesso.', int $estado = 200, array $metadados = []): JsonResponse
    {
        return self::montar(true, $mensagem, self::resolverDados($dados), $estado, $metadados);
    }

    public static function criado(mixed $dados = null, string $mensagem = 'Registo criado com sucesso.'): JsonResponse
    {
        return self::sucesso($dados, $mensagem, 201);
    }

    /**
     * Listagem paginada: "dados" é a lista da página e "metadados.paginacao" descreve a página.
     *
     * @param  class-string<JsonResource>|null  $recurso
     */
    public static function paginado(LengthAwarePaginator $pagina, ?string $recurso = null, string $mensagem = 'Listagem obtida com sucesso.'): JsonResponse
    {
        $itens = $recurso ? $recurso::collection($pagina->getCollection()) : $pagina->getCollection();

        return self::sucesso($itens, $mensagem, 200, [
            'paginacao' => [
                'pagina_atual' => $pagina->currentPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }

    /**
     * Erro no envelope. A `mensagem` e os textos de `erros` passam SEMPRE por MensagemSegura (nunca saem SQL, caminhos,
     * classes ou mensagens cruas de excepções). `referencia` (5xx) liga a resposta ao log do servidor.
     */
    public static function erro(string $mensagem, int $estado, string $codigo, ?array $erros = null, array $metadados = [], ?string $referencia = null): JsonResponse
    {
        $resposta = self::montar(false, MensagemSegura::sanitizar($mensagem, $estado), null, $estado, $metadados, $codigo);

        if ($erros !== null || $referencia !== null) {
            $corpo = $resposta->getData(true);
            if ($referencia !== null) {
                $corpo['referencia'] = $referencia;
                $resposta->headers->set('X-Referencia-Erro', $referencia);
            }
            if ($erros !== null) {
                $corpo['erros'] = self::limparErros($erros, $estado);
            }
            $resposta->setData($corpo);
        }

        return $resposta;
    }

    /** @param  array<mixed>  $erros */
    private static function limparErros(array $erros, int $estado): array
    {
        return array_map(function ($v) use ($estado) {
            return match (true) {
                is_array($v) => self::limparErros($v, $estado),
                is_string($v) => MensagemSegura::sanitizar($v, $estado),
                default => $v,
            };
        }, $erros);
    }

    private static function montar(bool $sucesso, string $mensagem, mixed $dados, int $estado, array $metadados, ?string $codigo = null): JsonResponse
    {
        $corpo = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($codigo !== null) {
            $corpo['codigo'] = $codigo;
        }
        $corpo['dados'] = $dados;
        $corpo['metadados'] = array_merge([
            'empresa_id' => app(ContextoEmpresa::class)->id(),
            'executado_em' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], $metadados);

        return new JsonResponse($corpo, $estado, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function resolverDados(mixed $dados): mixed
    {
        if ($dados instanceof JsonResource || $dados instanceof ResourceCollection) {
            return $dados->resolve(request());
        }

        return $dados;
    }
}
