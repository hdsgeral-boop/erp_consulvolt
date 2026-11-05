<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Exceptions\ErroContextoEmpresa;
use App\Http\Controllers\Controller;
use App\Http\Requests\Autenticacao\EntrarRequest;
use App\Http\Resources\Sistema\EmpresaResource;
use App\Http\Resources\Sistema\UtilizadorResource;
use App\Models\Empresa;
use App\Models\TokenAcesso;
use App\Services\Autenticacao\ServicoAutenticacao;
use App\Services\Autenticacao\ServicoRecuperacaoPassword;
use App\Services\Sistema\ServicoEmpresas;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Api\RespostaApi;
use App\Support\Autenticacao\CookiesSessao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AutenticacaoController extends Controller
{
    public function __construct(
        private readonly ServicoAutenticacao $autenticacao,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoRecuperacaoPassword $recuperacao,
    ) {}

    /** POST /api/autenticacao/entrar */
    public function entrar(EntrarRequest $request): JsonResponse
    {
        // O dispositivo é decidido pelo servidor (ADR-082): vem do cookie HttpOnly assinado que o próprio servidor emitiu num
        // login anterior; sem ele (ou com assinatura inválida) cria-se um dispositivo novo. O campo `dispositivo` do corpo,
        // que já foi escolhido pelo cliente, é ignorado.
        $dispositivo = CookiesSessao::dispositivoDoPedido($request);
        $sessao = $this->autenticacao->entrar(
            $request->string('nome_utilizador')->toString(),
            $request->string('palavra_passe')->toString(),
            $dispositivo['id'],
            $request->ip(),
            $request->userAgent(),
        );

        // MFA (Fase 4 da extensão SaaS): credenciais válidas, mas a sessão só fica completa depois do código TOTP
        // (ver MfaController::verificar) — o token devolvido aqui só serve para esse pedido, nunca para mais nada.
        if ($sessao['mfa_pendente']) {
            $resposta = RespostaApi::sucesso([
                'mfa_pendente' => true,
                'token_mfa' => $sessao['token_mfa'],
                'tipo_token' => 'Bearer',
                'expira_em' => $sessao['expira_em']->toAtomString(),
            ], 'Introduza o código da aplicação de autenticação.');
            CookiesSessao::anexarDispositivo($resposta, $dispositivo['id'], $request);   // o 2.º passo tem de ser do mesmo dispositivo

            return $resposta;
        }

        // Fase D: o token nunca vai no corpo da resposta — só no cookie HttpOnly (CookiesSessao), inacessível a
        // JavaScript. O frontend já não o guarda; a sessão é confirmada por GET /autenticacao/eu.
        return CookiesSessao::anexar(RespostaApi::sucesso([
            'mfa_pendente' => false,
            'tipo_token' => 'Bearer',
            'expira_em' => $sessao['expira_em']->toAtomString(),
            'inatividade_minutos' => config('erp.sessao.inatividade_minutos'),
            'ultima_empresa_id' => $sessao['utilizador']->ultima_empresa_id,
            ...$this->perfilSessao($sessao['utilizador']),
        ], 'Sessão iniciada com sucesso.'), $sessao['token'], $sessao['expira_em'], $dispositivo['id'], $request);
    }

    /** POST /api/autenticacao/sair */
    public function sair(Request $request): JsonResponse
    {
        $this->autenticacao->sair($request->user());

        return CookiesSessao::limpar(RespostaApi::sucesso(null, 'Sessão terminada com sucesso.'), $request);
    }

    /** GET /api/autenticacao/eu */
    /** POST /api/autenticacao/sair-de-todas — logout global: termina todas as sessões do utilizador, incluindo esta. */
    public function sairDeTodas(Request $request): JsonResponse
    {
        $n = $this->autenticacao->sairDeTodas($request->user());

        return CookiesSessao::limpar(RespostaApi::sucesso(['terminadas' => $n], 'Todas as sessões foram terminadas.'), $request);
    }

    public function eu(Request $request): JsonResponse
    {
        return RespostaApi::sucesso($this->perfilSessao($request->user()) + [
            'empresa_ativa_id' => $this->empresaActivaDaSessao($request),
            'ultima_empresa_id' => $request->user()->ultima_empresa_id,   // ordena o ecrã de escolha (já não vem do navegador)
        ],
            'Dados da sessão obtidos com sucesso.');
    }

    /** PUT /api/autenticacao/empresa-activa — a empresa activa é estado da sessão no servidor (já não fica no navegador). */
    public function definirEmpresaActiva(Request $request): JsonResponse
    {
        $d = $request->validate(['empresa_id' => ['nullable', 'integer', 'min:1']]);
        $token = $request->user()->currentAccessToken();
        $empresaId = $d['empresa_id'] ?? null;

        if ($empresaId !== null) {
            $utilizador = $request->user();
            $empresa = Empresa::query()->find($empresaId);
            if (! $this->empresas->podeAceder($utilizador, (int) $empresaId)) {
                // mesma distinção de ResolverEmpresaAtiva: «inactiva» só para quem teria acesso a ela
                if ($empresa && ! $empresa->estaAtiva() && ($utilizador->eSuperAdministrador() || $utilizador->acesso_todas_empresas
                    || $utilizador->empresas()->where('empresas.id', $empresaId)->exists())) {
                    throw ErroContextoEmpresa::inativa();
                }
                throw ErroContextoEmpresa::semAcesso();
            }
            if (! $empresa || ! $empresa->estaAtiva()) {
                throw ErroContextoEmpresa::inativa();
            }
        }
        if ($token instanceof TokenAcesso) {
            DB::table('tokens_acesso')->where('id', $token->getKey())->update(['empresa_ativa_id' => $empresaId]);
        }
        if ($empresaId !== null) {
            DB::table('utilizadores')->where('id', $request->user()->getKey())->update(['ultima_empresa_id' => $empresaId]);
        }

        return RespostaApi::sucesso(['empresa_ativa_id' => $empresaId], 'Empresa activa da sessão actualizada.');
    }

    private function empresaActivaDaSessao(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();
        $id = $token instanceof TokenAcesso ? $token->empresa_ativa_id : null;

        // só devolve se o utilizador ainda tiver acesso (o acesso pode ter sido retirado desde que a escolheu)
        return $id !== null && $this->empresas->podeAceder($request->user(), (int) $id) ? (int) $id : null;
    }

    /** POST /api/autenticacao/recuperar-password */
    public function recuperarPassword(Request $request): JsonResponse
    {
        $request->validate([
            'identificador' => ['required', 'string', 'max:255'],
        ]);

        // Resposta sempre igual e de tempo uniforme, exista a conta ou não (ver ServicoRecuperacaoPassword: limite por
        // conta, entrega antes da troca, aviso ao titular e auditoria).
        $this->recuperacao->processar(trim($request->string('identificador')->toString()), $request->ip(), $request->userAgent());

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
