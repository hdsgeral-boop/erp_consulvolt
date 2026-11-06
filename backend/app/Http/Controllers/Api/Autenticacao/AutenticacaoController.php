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
use App\Support\Tenancy\ContextoTenant;
use App\Support\Texto\TextoEntrada;
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

    /** POST /api/autenticacao/sair-de-todas — logout global: termina todas as sessões do utilizador, incluindo esta. */
    public function sairDeTodas(Request $request): JsonResponse
    {
        $n = $this->autenticacao->sairDeTodas($request->user());

        return CookiesSessao::limpar(RespostaApi::sucesso(['terminadas' => $n], 'Todas as sessões foram terminadas.'), $request);
    }

    /** GET /api/autenticacao/eu */
    public function eu(Request $request): JsonResponse
    {
        $activa = $this->empresaActivaDaSessao($request);

        return RespostaApi::sucesso($this->perfilSessao($request->user(), $activa) + [
            'empresa_ativa_id' => $activa,
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
            if ($utilizador->eRoot()) {
                // conta ROOT global (ADR-084): qualquer empresa activa de qualquer cliente
                $alvo = $this->empresas->empresaDoRoot((int) $empresaId);
                if ($alvo === null) {
                    throw ErroContextoEmpresa::semAcesso();
                }
                if ($alvo->estado !== Empresa::ESTADO_ATIVO) {
                    throw ErroContextoEmpresa::inativa();
                }
            } else {
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
        ], [
            'identificador.required' => 'Indique o nome de utilizador ou o e-mail.',
            'identificador.max' => 'O nome de utilizador ou e-mail não pode ter mais de 255 caracteres.',
        ], ['identificador' => 'nome de utilizador ou e-mail']);

        // Resposta sempre igual e de tempo uniforme, exista a conta ou não (ver ServicoRecuperacaoPassword: limite por
        // conta, entrega antes da troca, aviso ao titular e auditoria).
        $this->recuperacao->processar(TextoEntrada::limpar($request->input('identificador')) ?? '', $request->ip(), $request->userAgent());

        return RespostaApi::sucesso([
            'processado' => true,
            'mensagem' => 'Se os dados introduzidos corresponderem a uma conta ativa, foi enviada uma nova password para o e-mail associado.',
        ], 'Pedido de recuperação de palavra-passe processado.');
    }

    /** @return array<string, mixed> */
    private function perfilSessao($utilizador, ?int $empresaActivaId = null): array
    {
        $utilizador->loadMissing('perfil');
        $empresas = EmpresaResource::collection($this->empresas->acessiveis($utilizador))->resolve();
        // Conta ROOT (ADR-084): a empresa activa pode ser de OUTRO cliente (a lista acima só traz a do seu tenant de origem);
        // junta-a, lida no contexto do tenant dela, para o frontend a poder mostrar sem ela «pertencer» à root.
        if ($utilizador->eRoot() && $empresaActivaId !== null && ! in_array($empresaActivaId, array_column($empresas, 'id'), true)) {
            $alvo = $this->empresas->empresaDoRoot($empresaActivaId);
            $empresa = $alvo === null ? null : app(ContextoTenant::class)->executarComo($alvo->tenant_id, fn () => Empresa::query()->find($empresaActivaId));
            if ($empresa !== null) {
                $empresas[] = EmpresaResource::make($empresa)->resolve();
            }
        }

        return [
            // `conta_root` (ADR-084) só se revela ao próprio titular: no login o pedido ainda não tem utilizador, por isso força-se aqui.
            'utilizador' => array_merge(UtilizadorResource::make($utilizador)->resolve(), ['conta_root' => $utilizador->eRoot()]),
            'permissoes' => $this->permissoes->efectivas($utilizador),
            'perfil_formato_v2' => $this->permissoes->formatoV2($utilizador),
            'empresas' => $empresas,
        ];
    }
}
