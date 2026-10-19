<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Services\Plataforma\ServicoPlataformaInicial;
use App\Services\Sistema\ServicoUtilizadores;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Primeiro acesso da plataforma (público): ver ServicoPlataformaInicial para o risco da janela inicial. */
final class PlataformaInicialController extends Controller
{
    public function __construct(private readonly ServicoPlataformaInicial $servico) {}

    /** GET /api/plataforma-inicial/estado */
    public function estado(Request $request): JsonResponse
    {
        $precisa = $this->servico->disponivel($request);

        return RespostaApi::sucesso([
            'precisa_criar_admin' => $precisa,
            'exige_segredo' => $precisa && $this->servico->exigeSegredo(),
        ], 'Estado obtido com sucesso.');
    }

    /** POST /api/plataforma-inicial/criar-admin — não inicia sessão: o cliente entra a seguir pelo login normal. */
    public function criarAdmin(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nome_utilizador' => ['required', 'string', 'max:100'],
            'nome_completo' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200'],
            'palavra_passe' => ['required', 'string', 'min:'.ServicoUtilizadores::MINIMO_PALAVRA_PASSE, 'max:255', 'confirmed'],
        ]);

        $admin = $this->servico->criar($request, $d);

        return RespostaApi::criado(['nome_utilizador' => $admin->nome_utilizador], 'Administrador da plataforma criado. Já pode entrar.');
    }
}
