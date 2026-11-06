<?php

namespace App\Exceptions;

use App\Support\Api\RespostaApi;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Converte qualquer excepção num pedido /api no envelope padronizado, com mensagens em português
 * e um `codigo` estável. Detalhes técnicos só são expostos com APP_DEBUG=true.
 */
final class ManipuladorExcecoesApi
{
    public static function renderizar(Throwable $e): JsonResponse
    {
        if ($e instanceof ErroOrcamental) {   // já fora da transacção desfeita do documento
            try {
                $e->registar();
            } catch (Throwable) {
                // o registo do alerta nunca impede a resposta
            }
        }

        if (self::eNifDeEmpresaDuplicado($e)) {
            return RespostaApi::erro('Já existe uma empresa activa com este NIF.', 422, 'NIF_DUPLICADO');
        }

        return match (true) {
            $e instanceof ValidationException => RespostaApi::erro(
                'Os dados enviados são inválidos.', 422, 'VALIDACAO', $e->errors()),

            $e instanceof ErroNegocio => RespostaApi::erro(
                $e->getMessage(), $e->estadoHttp, $e->codigo, $e->detalhes ?: null),

            $e instanceof AuthenticationException => RespostaApi::erro(
                'Sessão inválida ou expirada. Inicie sessão novamente.', 401, 'NAO_AUTENTICADO'),

            $e instanceof AccessDeniedHttpException => RespostaApi::erro(
                'Não tem permissão para executar esta operação.', 403, 'SEM_PERMISSAO'),

            $e instanceof NotFoundHttpException, $e instanceof ModelNotFoundException => RespostaApi::erro(
                'O recurso pedido não existe.', 404, 'NAO_ENCONTRADO'),

            $e instanceof MethodNotAllowedHttpException => RespostaApi::erro(
                'Método HTTP não suportado neste endereço.', 405, 'METODO_NAO_PERMITIDO'),

            $e instanceof ThrottleRequestsException, $e instanceof TooManyRequestsHttpException => RespostaApi::erro(
                sprintf('Demasiadas tentativas. Tente novamente dentro de %d segundos.', (int) ($e->getHeaders()['Retry-After'] ?? 60)),
                429, 'LIMITE_PEDIDOS'),

            $e instanceof HttpExceptionInterface => RespostaApi::erro(
                $e->getMessage() ?: 'Pedido não pode ser processado.', $e->getStatusCode(), 'ERRO_HTTP'),

            default => self::erroInterno($e),
        };
    }

    /**
     * O índice único do NIF de empresas é global (todos os clientes) e a RLS impede a validação normal de ver as empresas
     * de outros clientes, por isso a duplicação só se descobre na base. Procura-se em toda a cadeia de excepções porque o
     * erro verdadeiro pode vir como «previous» de outro (a transacção abortada).
     */
    private static function eNifDeEmpresaDuplicado(Throwable $e): bool
    {
        for ($atual = $e; $atual !== null; $atual = $atual->getPrevious()) {
            if ($atual instanceof UniqueConstraintViolationException && str_contains($atual->getMessage(), 'uq_empresas_nif_ativas')) {
                return true;
            }
        }

        return false;
    }

    private static function erroInterno(Throwable $e): JsonResponse
    {
        $depuracao = config('app.debug')
            ? ['excecao' => $e::class, 'mensagem' => $e->getMessage(), 'ficheiro' => $e->getFile().':'.$e->getLine()]
            : [];

        return RespostaApi::erro('Ocorreu um erro interno. A equipa técnica foi notificada.', 500, 'ERRO_INTERNO', null, $depuracao ? ['depuracao' => $depuracao] : []);
    }
}
