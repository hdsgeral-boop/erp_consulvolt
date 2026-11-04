<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\TokenAcesso;
use App\Services\Autenticacao\ServicoSessoes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «As minhas sessões» (ADR-082): cada utilizador vê e termina SÓ as suas sessões (dispositivos). Todas as consultas partem
 * de `$utilizador->tokens()`, por isso um id de sessão de outro utilizador nunca é encontrado (404, sem revelar que existe).
 */
final class MinhasSessoesController extends Controller
{
    public function __construct(private readonly ServicoSessoes $sessoes) {}

    /** GET /api/autenticacao/sessoes */
    public function index(Request $request): JsonResponse
    {
        return RespostaApi::sucesso($this->sessoes->listar($request->user(), $this->actual($request)), 'Sessões obtidas com sucesso.');
    }

    /** DELETE /api/autenticacao/sessoes/{id} */
    public function terminar(Request $request, int $id): JsonResponse
    {
        if ($id === $this->actual($request)) {
            throw new ErroNegocio('Não pode terminar a sessão que está a usar; use «Sair».', 'SESSAO_PROPRIA', 422);
        }
        if (! $this->sessoes->terminar($request->user(), $id)) {
            throw new ErroNegocio('Sessão não encontrada.', 'SESSAO_NAO_ENCONTRADA', 404);
        }

        return RespostaApi::sucesso(null, 'Sessão terminada.');
    }

    /** POST /api/autenticacao/sessoes/terminar-outras */
    public function terminarOutras(Request $request): JsonResponse
    {
        $n = $this->sessoes->terminarOutras($request->user(), (int) $this->actual($request));

        return RespostaApi::sucesso(['terminadas' => $n], $n === 0 ? 'Não havia outras sessões.' : "{$n} sessão(ões) terminada(s).");
    }

    private function actual(Request $request): ?int
    {
        $t = $request->user()->currentAccessToken();

        return $t instanceof TokenAcesso ? (int) $t->getKey() : null;
    }
}
