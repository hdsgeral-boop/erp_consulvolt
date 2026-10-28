<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\RespostaRapidaSuporte;
use App\Models\TicketSuporte;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Respostas pré-definidas ("macros") para a equipa de suporte inserir rapidamente numa conversa. Catálogo partilhado. */
final class RespostaRapidaSuporteController extends Controller
{
    public function index(): JsonResponse
    {
        $this->exigir(...array_values(TicketSuporte::CHAVE_POR_CATEGORIA));

        return RespostaApi::sucesso(RespostaRapidaSuporte::query()->orderBy('titulo')->get(), 'Respostas rápidas obtidas com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir(...array_values(TicketSuporte::CHAVE_POR_CATEGORIA));

        return RespostaApi::criado(RespostaRapidaSuporte::create($this->validar($r)), 'Resposta rápida criada.');
    }

    public function update(Request $r, RespostaRapidaSuporte $resposta): JsonResponse
    {
        $this->exigir(...array_values(TicketSuporte::CHAVE_POR_CATEGORIA));
        $resposta->update($this->validar($r));

        return RespostaApi::sucesso($resposta->refresh(), 'Resposta rápida actualizada.');
    }

    public function destroy(RespostaRapidaSuporte $resposta): JsonResponse
    {
        $this->exigir(...array_values(TicketSuporte::CHAVE_POR_CATEGORIA));
        $resposta->delete();

        return RespostaApi::sucesso(null, 'Resposta rápida eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r): array
    {
        return $r->validate(['titulo' => ['required', 'string', 'max:150'], 'texto' => ['required', 'string', 'max:5000']]);
    }
}
