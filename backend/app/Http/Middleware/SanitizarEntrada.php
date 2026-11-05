<?php

namespace App\Http\Middleware;

use App\Support\Seguranca\DetectorInjecao;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Normalizador/filtro global de entrada (ADR-083), para todos os pedidos /api:
 *  - nos parâmetros de pesquisa/filtro/ordenação (q, pesquisa, search, filtro*, ordenar*, sort…), da query string E
 *    do corpo, recusa com 422 padrões evidentes de injecção (ver DetectorInjecao) sem tocar em texto legítimo;
 *  - parâmetros de pesquisa «escalares» (q, ordenar…) nunca podem ser listas/objectos (evita TypeError → 500);
 *  - chaves que começam por «$» (operadores NoSQL/ORM) são recusadas nesses parâmetros;
 *  - normaliza Unicode (NFC) e retira caracteres invisíveis dos textos de pesquisa aceites.
 * É a segunda linha: a primeira é não concatenar SQL (bindings + listas brancas de colunas).
 * Nunca regista o valor recebido (pode conter dados pessoais): só o nome do parâmetro, a rota e o IP.
 */
final class SanitizarEntrada
{
    private const PROFUNDIDADE_MAXIMA = 4;

    public function handle(Request $request, Closure $next): Response
    {
        foreach (['query', 'request'] as $origem) {
            $bag = $origem === 'query' ? $request->query : $request->request;
            $todos = $bag->all();
            $alterado = false;
            foreach ($todos as $nome => $valor) {
                if (! is_string($nome) || ! DetectorInjecao::eParametroAlvo($nome)) {
                    continue;
                }
                $novo = $this->verificar($request, $nome, $valor, 1);
                if ($novo !== $valor) {
                    $todos[$nome] = $novo;
                    $alterado = true;
                }
            }
            if ($alterado) {
                $bag->replace($todos);
            }
        }

        return $next($request);
    }

    private function verificar(Request $request, string $nome, mixed $valor, int $nivel): mixed
    {
        if (is_array($valor)) {
            if ($nivel === 1 && DetectorInjecao::eEscalar($nome)) {
                $this->recusar($request, $nome, 'o parâmetro não aceita listas');
            }
            if ($nivel > self::PROFUNDIDADE_MAXIMA) {
                $this->recusar($request, $nome, 'estrutura demasiado profunda');
            }
            $saida = [];
            foreach ($valor as $k => $v) {
                if (is_string($k) && (str_starts_with($k, '$') || DetectorInjecao::motivo($k) !== null)) {
                    $this->recusar($request, $nome, 'chave inválida');
                }
                $saida[$k] = $this->verificar($request, $nome, $v, $nivel + 1);
            }

            return $saida;
        }
        if (! is_string($valor)) {
            return $valor;
        }
        $limpo = DetectorInjecao::normalizar($valor);
        if (($motivo = DetectorInjecao::motivo($limpo)) !== null) {
            $this->recusar($request, $nome, $motivo);
        }

        return $limpo;
    }

    private function recusar(Request $request, string $nome, string $motivo): never
    {
        Log::warning('seguranca.entrada_recusada', [
            'parametro' => $nome, 'motivo' => $motivo, 'rota' => $request->path(), 'metodo' => $request->method(), 'ip' => $request->ip(),
        ]);

        throw ValidationException::withMessages([$nome => 'O valor indicado não é válido para este campo de pesquisa/filtro.']);
    }
}
