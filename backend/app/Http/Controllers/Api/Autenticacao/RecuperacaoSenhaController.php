<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Services\Autenticacao\ServicoRecuperacaoSenha;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecuperacaoSenhaController extends Controller
{
    public function __construct(private readonly ServicoRecuperacaoSenha $servico) {}

    /** POST /api/autenticacao/recuperar-senha — resposta sempre genérica. */
    public function recuperar(Request $r): JsonResponse
    {
        $d = $r->validate(['identificador' => ['required', 'string', 'max:150']], [], ['identificador' => 'e-mail ou utilizador']);
        $this->servico->solicitar($d['identificador'], $r);

        return RespostaApi::sucesso(null, 'Se os dados corresponderem a uma conta com e-mail registado, enviámos as instruções para redefinir a palavra-passe.');
    }

    /** POST /api/autenticacao/redefinir-senha */
    public function redefinir(Request $r): JsonResponse
    {
        $d = $r->validate([
            'token' => ['required', 'string', 'max:100'],
            'palavra_passe' => ['required', 'string', 'max:200', 'confirmed'],
        ], ['palavra_passe.confirmed' => 'A confirmação não coincide com a nova palavra-passe.'], ['palavra_passe' => 'palavra-passe']);
        $this->servico->redefinir($d['token'], $d['palavra_passe'], $r);

        return RespostaApi::sucesso(null, 'Palavra-passe redefinida. Já pode entrar com a nova palavra-passe.');
    }
}
