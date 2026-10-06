<?php

namespace App\Http\Controllers\Api\Integracoes;

use App\Http\Controllers\Controller;
use App\Services\Integracoes\Operacoes\ServicoOperacoes;
use App\Services\Integracoes\Preferencias\ServicoPreferencias;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/sistema/preferencias/{tipo}[/{nome}] — preferências do próprio utilizador (M-19), sem permissão do catálogo
 * (cada um só vê as suas). /api/sistema/operacoes/{id} — estado de uma operação em segundo plano do próprio (M-05).
 */
final class PreferenciasOperacoesController extends Controller
{
    public function __construct(
        private readonly ServicoPreferencias $preferencias,
        private readonly ServicoOperacoes $operacoes,
    ) {}

    public function listar(Request $r, string $tipo): JsonResponse
    {
        return RespostaApi::sucesso($this->preferencias->listar((int) $r->user()->getKey(), $tipo), 'Preferências.');
    }

    public function gravar(Request $r, string $tipo, string $nome): JsonResponse
    {
        $r->validate(['valor' => ['present']]);

        return RespostaApi::sucesso($this->preferencias->gravar((int) $r->user()->getKey(), $tipo, $nome, $r->input('valor')), 'Preferência gravada.');
    }

    public function eliminar(Request $r, string $tipo, string $nome): JsonResponse
    {
        $this->preferencias->eliminar((int) $r->user()->getKey(), $tipo, $nome);

        return RespostaApi::sucesso(null, 'Preferência eliminada.');
    }

    public function operacao(Request $r, string $id): JsonResponse
    {
        return RespostaApi::sucesso($this->operacoes->estado($id, (int) $r->user()->getKey()), 'Estado da operação.');
    }

    public function cancelarOperacao(Request $r, string $id): JsonResponse
    {
        return RespostaApi::sucesso($this->operacoes->cancelar($id, (int) $r->user()->getKey()), 'Operação cancelada.');
    }
}
