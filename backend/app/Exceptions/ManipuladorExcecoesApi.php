<?php

namespace App\Exceptions;

use App\Support\Api\MensagemSegura;
use App\Support\Api\RespostaApi;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Converte qualquer excepção num pedido /api no envelope padronizado, com mensagens em português
 * e um `codigo` estável. Nenhum detalhe técnico chega ao cliente (nem com APP_DEBUG): os 5xx devolvem texto genérico
 * e uma `referencia` que o suporte cruza com o log do servidor. Ver docs/qualidade/MENSAGENS_ERRO.md.
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
                'Os dados enviados são inválidos. Reveja os campos assinalados.', 422, 'VALIDACAO', $e->errors()),

            $e instanceof ErroNegocio => self::deNegocio($e),

            $e instanceof AuthenticationException => RespostaApi::erro(
                MensagemSegura::generica(401), 401, 'NAO_AUTENTICADO'),

            $e instanceof AccessDeniedHttpException => RespostaApi::erro(
                MensagemSegura::generica(403), 403, 'SEM_PERMISSAO'),

            $e instanceof NotFoundHttpException, $e instanceof ModelNotFoundException => RespostaApi::erro(
                MensagemSegura::generica(404), 404, 'NAO_ENCONTRADO'),

            $e instanceof MethodNotAllowedHttpException => RespostaApi::erro(
                MensagemSegura::generica(405), 405, 'METODO_NAO_PERMITIDO'),

            $e instanceof ThrottleRequestsException, $e instanceof TooManyRequestsHttpException => self::limitePedidos($e),

            $e instanceof HttpExceptionInterface => self::deHttp($e),

            default => self::erroInterno($e),
        };
    }

    private static function deNegocio(ErroNegocio $e): JsonResponse
    {
        if ($e->estadoHttp >= 500) {   // falha de serviço externo/indisponibilidade: mensagem própria + referência para o suporte
            $ref = MensagemSegura::novaReferencia();
            self::registar($e, $ref, 'warning');

            return RespostaApi::erro($e->getMessage(), $e->estadoHttp, $e->codigo, $e->detalhes ?: null, [], $ref);
        }

        return RespostaApi::erro($e->getMessage(), $e->estadoHttp, $e->codigo, $e->detalhes ?: null);
    }

    private static function limitePedidos(Throwable $e): JsonResponse
    {
        $espera = max(1, (int) ($e->getHeaders()['Retry-After'] ?? 60));

        return RespostaApi::erro(
            sprintf('Demasiadas tentativas. Aguarde %d segundos e tente novamente.', $espera), 429, 'LIMITE_PEDIDOS', ['espera_segundos' => $espera]);
    }

    /** Estados HTTP sem excepção própria (413, 419, 502/503/504, abort(...)): o texto cru do framework nunca chega ao cliente. */
    private static function deHttp(HttpExceptionInterface $e): JsonResponse
    {
        $estado = $e->getStatusCode();
        $codigo = match ($estado) {
            413 => 'PEDIDO_DEMASIADO_GRANDE',
            415 => 'TIPO_NAO_SUPORTADO',
            419 => 'SESSAO_CSRF_EXPIRADA',
            502, 503, 504 => 'INDISPONIVEL',
            default => $estado >= 500 ? 'ERRO_INTERNO' : 'ERRO_HTTP',
        };
        if ($estado >= 500) {
            $ref = MensagemSegura::novaReferencia();
            self::registar($e, $ref, 'warning');

            return RespostaApi::erro(MensagemSegura::generica($estado), $estado, $codigo, null, [], $ref);
        }
        // abort(4xx, 'texto próprio') escrito para o utilizador passa se for seguro; os textos em inglês do framework não.
        $proprio = in_array($estado, [400, 403, 409, 410, 422, 423], true) ? trim($e->getMessage()) : '';

        return RespostaApi::erro(
            $proprio !== '' && ! preg_match('/^[ -~]+$/', $proprio) ? MensagemSegura::sanitizar($proprio, $estado) : MensagemSegura::generica($estado),
            $estado, $codigo);
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

    /** 500: texto genérico + referência; a causa completa fica só no log do servidor, sob a mesma referência. */
    private static function erroInterno(Throwable $e): JsonResponse
    {
        $ref = MensagemSegura::novaReferencia();
        self::registar($e, $ref, 'error');

        return RespostaApi::erro(MensagemSegura::generica(500), 500, 'ERRO_INTERNO', null, [], $ref);
    }

    private static function registar(Throwable $e, string $referencia, string $nivel): void
    {
        try {
            Log::log($nivel, "[{$referencia}] ".$e::class, [
                'referencia' => $referencia,
                'excecao' => $e,
                'url' => request()->method().' '.request()->path(),
            ]);
        } catch (Throwable) {
            // registar nunca impede a resposta
        }
    }
}
