<?php

namespace App\Http\Controllers\Api\Gestao;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Services\Gestao\Fluxos\ServicoFluxos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * /api/gestao/fluxos — Fluxo de Processos (js/fluxo_processos.js e fluxos por módulo). Só consulta: as acções de cada etapa
 * indicam o ecrã onde a etapa se executa. Exige a consulta do ecrã «Fluxo de Processos» e, por fluxo, a consulta do módulo
 * de origem (catálogo: «Cada separador também exige a consulta do módulo de origem»; podeRH, podeVendas…, :555-567).
 */
final class FluxosController extends Controller
{
    public function __construct(private readonly ServicoFluxos $fluxos) {}

    public function index(): JsonResponse
    {
        $this->exigir('fluxo_processos_view');

        return RespostaApi::sucesso($this->fluxos->disponiveis(fn (array $vistas) => Gate::any(self::habilidades($vistas))), 'Fluxos de processos disponíveis.');
    }

    public function mostrar(string $fluxo): JsonResponse
    {
        $this->exigirFluxo($fluxo);

        return RespostaApi::sucesso($this->fluxos->resumo($fluxo), 'Fluxo de processos.');
    }

    public function processos(Request $r, string $fluxo): JsonResponse
    {
        $this->exigirFluxo($fluxo);
        $f = $r->validate([
            'etapa' => ['nullable', 'string', 'max:40'], 'estado' => ['nullable', Rule::in(['em_curso', 'bloqueado', 'concluido', 'com_pendencias'])],
            'pesquisa' => ['nullable', 'string', 'max:100'], 'pagina' => ['nullable', 'integer', 'min:1'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return RespostaApi::paginado($this->fluxos->processos($fluxo, $f), null, 'Processos do fluxo.');
    }

    public function processo(string $fluxo, string $chave): JsonResponse
    {
        $this->exigirFluxo($fluxo);

        return RespostaApi::sucesso($this->fluxos->processo($fluxo, $chave), 'Processo.');
    }

    private function exigirFluxo(string $fluxo): void
    {
        $this->exigir('fluxo_processos_view');
        if (! ServicoFluxos::existe($fluxo)) {
            throw new ErroNegocio('Fluxo desconhecido.', 'FLUXO_INVALIDO', 404);
        }
        if (! Gate::any(self::habilidades(ServicoFluxos::vistas($fluxo)))) {
            throw new AccessDeniedHttpException;
        }
    }

    /** @return list<string> «<ecrã>_view» de cada consulta do módulo */
    private static function habilidades(array $vistas): array
    {
        return array_map(fn ($v) => "{$v}_view", $vistas);
    }
}
