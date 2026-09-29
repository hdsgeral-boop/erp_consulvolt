<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\DiarioContabil;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/contabilidade/diarios — diários contabilísticos (tabelas auxiliares do legado). */
final class DiarioController extends Controller
{
    public function index(): JsonResponse
    {
        $this->exigir('tabelas_aux_view', 'lancamentos_view');

        return RespostaApi::sucesso(DiarioContabil::query()->orderBy('codigo')->get(['id', 'codigo', 'descricao', 'nome']), 'Diários obtidos com sucesso.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->exigir('aux_gerir');
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            'descricao' => ['required', 'string', 'max:255'],
        ], [], ['codigo' => 'código', 'descricao' => 'descrição']);
        $dados['codigo'] = mb_strtoupper($dados['codigo']);
        if (DiarioContabil::query()->where('codigo', $dados['codigo'])->exists()) {
            throw new ErroNegocio("Já existe o diário {$dados['codigo']}.", 'DIARIO_DUPLICADO', 422);
        }

        return RespostaApi::criado(DiarioContabil::create($dados), 'Diário criado com sucesso.');
    }
}
