<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Services\Imobiliario\ServicoSubarrendamento;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Subarrendamento e margem (Onda 2, ecrã imo_sub). Só leitura: ver imo_sub_view. Debitar um encargo ao inquilino é uma acção da renda
 * (imo_sub_debitar, em RendasController).
 *  GET /imobiliario/subarrendamento/margem?de=AAAA-MM&ate=AAAA-MM   margem por fracção (rendas mensalizadas) e mês a mês
 *  GET /imobiliario/subarrendamento/cadeia/{fracao}                  contratos tomados da fracção, subarrendamentos e margem acumulada
 */
final class SubarrendamentoController extends Controller
{
    public function __construct(private readonly ServicoSubarrendamento $subarrendamento) {}

    public function margem(Request $r): JsonResponse
    {
        $this->exigir('imo_sub_view');
        $d = $r->validate(['de' => ['nullable', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/'], 'ate' => ['nullable', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/']],
            ['de.regex' => 'O início tem de ter o formato AAAA-MM.', 'ate.regex' => 'O fim tem de ter o formato AAAA-MM.']);

        return RespostaApi::sucesso(new ImobiliarioResource($this->subarrendamento->margem($d['de'] ?? null, $d['ate'] ?? null)), 'Margem do subarrendamento.');
    }

    public function cadeia(int $fracao): JsonResponse
    {
        $this->exigir('imo_sub_view');

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->subarrendamento->cadeiaDaFracao($fracao)), 'Cadeia da fracção.');
    }
}
