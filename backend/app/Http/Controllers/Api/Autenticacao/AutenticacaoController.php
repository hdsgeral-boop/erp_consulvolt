<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autenticacao\EntrarRequest;
use App\Http\Resources\Sistema\EmpresaResource;
use App\Http\Resources\Sistema\UtilizadorResource;
use App\Mail\RecuperacaoPasswordMail;
use App\Services\Autenticacao\ServicoAutenticacao;
use App\Services\Sistema\ServicoEmpresas;
use App\Services\Sistema\ServicoPermissoes;
use App\Services\Sistema\ServicoUtilizadores;
use App\Models\Utilizador;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

final class AutenticacaoController extends Controller
{
    public function __construct(
        private readonly ServicoAutenticacao $autenticacao,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoUtilizadores $utilizadores,
    ) {}

    /** POST /api/autenticacao/entrar */
    public function entrar(EntrarRequest $request): JsonResponse
    {
        $sessao = $this->autenticacao->entrar(
            $request->string('nome_utilizador')->toString(),
            $request->string('palavra_passe')->toString(),
            $request->string('dispositivo')->toString() ?: 'api',
            $request->ip(),
            $request->userAgent(),
        );

        // MFA (Fase 4 da extensão SaaS): credenciais válidas, mas a sessão só fica completa depois do código TOTP
        // (ver MfaController::verificar) — o token devolvido aqui só serve para esse pedido, nunca para mais nada.
        if ($sessao['mfa_pendente']) {
            return RespostaApi::sucesso([
                'mfa_pendente' => true,
                'token_mfa' => $sessao['token_mfa'],
                'tipo_token' => 'Bearer',
                'expira_em' => $sessao['expira_em']->toAtomString(),
            ], 'Introduza o código da aplicação de autenticação.');
        }

        return RespostaApi::sucesso([
            'mfa_pendente' => false,
            'token' => $sessao['token'],
            'tipo_token' => 'Bearer',
            'expira_em' => $sessao['expira_em']->toAtomString(),
            'inatividade_minutos' => config('erp.sessao.inatividade_minutos'),
            ...$this->perfilSessao($sessao['utilizador']),
        ], 'Sessão iniciada com sucesso.');
    }

    /** POST /api/autenticacao/sair */
    public function sair(Request $request): JsonResponse
    {
        $this->autenticacao->sair($request->user());

        return RespostaApi::sucesso(null, 'Sessão terminada com sucesso.');
    }

    /** GET /api/autenticacao/eu */
    public function eu(Request $request): JsonResponse
    {
        return RespostaApi::sucesso($this->perfilSessao($request->user()), 'Dados da sessão obtidos com sucesso.');
    }

    /** POST /api/autenticacao/recuperar-password */
    public function recuperarPassword(Request $request): JsonResponse
    {
        $request->validate([
            'identificador' => ['required', 'string', 'max:255'],
        ]);

        $identificador = trim($request->string('identificador')->toString());

        // Tal como no login: ainda não se sabe a que tenant pertence, por isso a pesquisa por nome ou email tem de
        // atravessar todos (login_localizar_utilizador, SECURITY DEFINER — ver ServicoAutenticacao::entrar).
        $utilizador = Utilizador::fromQuery('SELECT * FROM login_localizar_utilizador(?, ?)', [$identificador, $identificador])->first();

        // Mensagem sempre igual quer o utilizador exista ou não, para não revelar se uma conta existe (igual à
        // lógica de autenticação, ServicoAutenticacao::entrar): só muda se um email é mesmo enviado.
        if ($utilizador && $utilizador->ativo && $utilizador->email) {
            $palavraPasse = $this->utilizadores->redefinirPorRecuperacao($utilizador);
            Mail::to($utilizador->email)->send(new RecuperacaoPasswordMail(
                $utilizador->nome_utilizador, $utilizador->nome_completo ?: $utilizador->nome_utilizador, $palavraPasse,
            ));
        }

        return RespostaApi::sucesso([
            'processado' => true,
            'mensagem' => 'Se os dados introduzidos corresponderem a uma conta ativa, foi enviada uma nova password para o e-mail associado.',
        ], 'Pedido de recuperação de palavra-passe processado.');
    }

    /** @return array<string, mixed> */
    private function perfilSessao($utilizador): array
    {
        $utilizador->loadMissing('perfil');

        return [
            'utilizador' => UtilizadorResource::make($utilizador)->resolve(),
            'permissoes' => $this->permissoes->efectivas($utilizador),
            'perfil_formato_v2' => $this->permissoes->formatoV2($utilizador),
            'empresas' => EmpresaResource::collection($this->empresas->acessiveis($utilizador))->resolve(),
        ];
    }
}
