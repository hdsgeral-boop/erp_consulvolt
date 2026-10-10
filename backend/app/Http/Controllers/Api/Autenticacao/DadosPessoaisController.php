<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Services\Autenticacao\ServicoDadosPessoais;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Gerir Dados Pessoais» (menu do utilizador). Só actua sobre o utilizador da sessão — nunca recebe um id — e não precisa
 * de empresa activa nem de permissões do catálogo: cada utilizador gere apenas os seus próprios dados.
 */
final class DadosPessoaisController extends Controller
{
    public function __construct(private readonly ServicoDadosPessoais $servico) {}

    /** GET /api/autenticacao/dados-pessoais */
    public function show(Request $r): JsonResponse
    {
        return RespostaApi::sucesso($this->servico->obter($r->user()), 'Dados pessoais obtidos com sucesso.');
    }

    /** PUT /api/autenticacao/dados-pessoais */
    public function update(Request $r): JsonResponse
    {
        $d = $r->validate([
            'nome_completo' => ['required', 'string', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s.-]*$/'],
            'email' => ['nullable', 'email', 'max:150'],
        ], ['telefone.regex' => 'O telefone só pode ter algarismos, espaços e os sinais + ( ) - .']);
        $u = $this->servico->atualizar($r->user(), $d);

        return RespostaApi::sucesso($this->servico->obter($u), 'Dados pessoais gravados.');
    }

    /** PUT /api/autenticacao/palavra-passe */
    public function palavraPasse(Request $r): JsonResponse
    {
        $d = $r->validate([
            'palavra_passe_actual' => ['required', 'string', 'max:255'],
            'palavra_passe_nova' => ['required', 'string', 'max:255', 'confirmed'],
        ], ['palavra_passe_nova.confirmed' => 'A confirmação não coincide com a nova palavra-passe.']);
        $outras = $this->servico->alterarPalavraPasse($r->user(), $d['palavra_passe_actual'], $d['palavra_passe_nova']);

        return RespostaApi::sucesso(['sessoes_terminadas' => $outras],
            'Palavra-passe alterada.'.($outras ? " Foram terminadas {$outras} outra(s) sessão(ões) aberta(s)." : ''));
    }
}
