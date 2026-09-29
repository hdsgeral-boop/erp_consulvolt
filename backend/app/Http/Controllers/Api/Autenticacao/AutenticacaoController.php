<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autenticacao\EntrarRequest;
use App\Http\Resources\Sistema\EmpresaResource;
use App\Http\Resources\Sistema\UtilizadorResource;
use App\Services\Autenticacao\ServicoAutenticacao;
use App\Services\Sistema\ServicoEmpresas;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AutenticacaoController extends Controller
{
    public function __construct(
        private readonly ServicoAutenticacao $autenticacao,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoPermissoes $permissoes,
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

        return RespostaApi::sucesso([
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
