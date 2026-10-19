<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Manual de códigos: erros devolvidos pela API, com o HTTP e o significado (config/codigos_erro.php). */
final class CodigosController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $r->validate(['q' => ['nullable', 'string', 'max:100']]);
        $termo = mb_strtoupper(trim((string) $r->query('q', '')));

        $codigos = collect(config('codigos_erro'))
            ->when($termo !== '', fn ($c) => $c->filter(fn (array $e) => str_contains($e['codigo'], $termo) || str_contains(mb_strtoupper($e['significado']), $termo)))
            ->sortBy('codigo')->values()->all();

        return RespostaApi::sucesso($codigos, 'Códigos obtidos com sucesso.');
    }
}