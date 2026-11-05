<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroContextoEmpresa;
use App\Models\Empresa;
use App\Models\TokenAcesso;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DefinicoesPlataforma;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve a empresa activa a partir do cabeçalho X-Empresa-Id (alias de rota: `empresa`):
 *   1. o cabeçalho é obrigatório e numérico;
 *   2. a empresa existe e está activa;
 *   3. o utilizador autenticado tem acesso a ela (ServicoEmpresas);
 * e define-a no ContextoEmpresa, que alimenta o Global Scope multi-empresa.
 */
final class ResolverEmpresaAtiva
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoEmpresas $empresas,
        private readonly ContextoTenant $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $cabecalho = config('erp.tenancy.cabecalho');
        $valor = $request->header($cabecalho);

        // Sem cabeçalho: a empresa activa da sessão (guardada no servidor, PUT /autenticacao/empresa-activa — ADR-082).
        // Com cabeçalho, manda o cabeçalho; em ambos os casos o acesso do utilizador à empresa é validado abaixo.
        if (($valor === null || $valor === '') && ($token = $request->user()?->currentAccessToken()) instanceof TokenAcesso
            && $token->empresa_ativa_id !== null) {
            $valor = (string) $token->empresa_ativa_id;
        }

        if ($valor === null || $valor === '') {
            throw ErroContextoEmpresa::naoIndicado($cabecalho);
        }
        if (! ctype_digit((string) $valor) || (int) $valor <= 0) {
            throw ErroContextoEmpresa::invalido($cabecalho);
        }

        $empresaId = (int) $valor;
        $utilizador = $request->user();

        if ($utilizador?->eRoot()) {
            return $this->comoRoot($request, $utilizador, $empresaId, $next);
        }

        if (! $utilizador || ! $this->empresas->podeAceder($utilizador, $empresaId)) {
            // Distinguir "inactiva" de "sem acesso" só para quem teria acesso a ela.
            $empresa = Empresa::query()->find($empresaId);
            if ($empresa && ! $empresa->estaAtiva() && $utilizador && ($utilizador->eSuperAdministrador() || $utilizador->acesso_todas_empresas
                || $utilizador->empresas()->where('empresas.id', $empresaId)->exists())) {
                throw ErroContextoEmpresa::inativa();
            }
            throw ErroContextoEmpresa::semAcesso();
        }

        $this->licenca($request, $utilizador, $empresaId);

        $this->contexto->definir($empresaId);

        try {
            return $next($request);
        } finally {
            $this->contexto->limpar();
        }
    }

    /**
     * Conta ROOT global (ADR-084): entra em qualquer empresa activa de qualquer cliente, sem lhe pertencer. O servidor
     * deduz o tenant da empresa escolhida (nunca de um cabeçalho de tenant) e muda o contexto da RLS para ele durante
     * o pedido — a RLS fica activa e só mostra o que é dessa empresa/cliente. O alvo fica no pedido para a auditoria.
     */
    private function comoRoot(Request $request, $utilizador, int $empresaId, Closure $next): Response
    {
        $alvo = $this->empresas->empresaDoRoot($empresaId);
        if ($alvo === null) {
            throw ErroContextoEmpresa::semAcesso();
        }
        if ($alvo->estado !== Empresa::ESTADO_ATIVO) {
            throw ErroContextoEmpresa::inativa();
        }

        $request->attributes->set('root_empresa_alvo', $empresaId);
        $request->attributes->set('root_tenant_alvo', $alvo->tenant_id);

        $tenantAnterior = $this->tenant->id();
        $this->tenant->definir($alvo->tenant_id);
        $this->contexto->definir($empresaId);

        try {
            return $next($request);
        } finally {
            $this->contexto->limpar();
            $this->tenant->definir($tenantAnterior);
        }
    }

    /**
     * Licença por empresa (ADR-074; Fase 2 da extensão SaaS — secção 10, "suspensão com regularização"): uma empresa
     * suspensa, cancelada ou em encerramento fica só de leitura — nunca bloqueio total. Em TOLERANCIA (Fase 1) o
     * acesso mantém-se completo, como se estivesse activa: a suspensão por incumprimento nunca é imediata. As outras
     * empresas do utilizador não são afectadas. Super-administradores têm acesso vitalício. Sem efeito com
     * erp.licencas.ativo=false.
     */
    private function licenca(Request $request, $utilizador, int $empresaId): void
    {
        if (! $this->licencaBloqueada($request, $utilizador, $empresaId)) {
            return;
        }

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            throw ErroContextoEmpresa::licencaExpirada();
        }
    }

    /**
     * Mesma regra de negócio acima, mas devolvida como booleano (sem olhar ao método HTTP) — usada pelo middleware
     * `licenca_exportacao` para bloquear também pedidos GET que geram documentos (SAF-T, recibos em PDF, cópias de
     * segurança): tecnicamente são leitura, mas contam como "exportar", que a licença bloqueada também proíbe.
     */
    public function licencaBloqueada(Request $request, $utilizador, int $empresaId): bool
    {
        if (! DefinicoesPlataforma::licencasAtivas() || $utilizador->eSuperAdministrador() || $utilizador->eRoot()) {
            return false;
        }

        // Lê sempre o estado persistido, nunca recalculado ao vivo: uma suspensão manual (ex. por incumprimento,
        // decidida antes de `licenca_fim` ter sequer passado) tem de se manter até uma acção explícita a desfazer
        // (pagamento confirmado, reactivação administrativa) — nunca pode ser "desfeita" silenciosamente só porque
        // a data ainda não reflecte expiração. Por isso qualquer escrita em licenca_fim/licenca_origem tem sempre de
        // chamar MaquinaEstadosLicenca::sincronizar() a seguir (ver Empresa::booted, ServicoLicenca::confirmarPagamento,
        // PlataformaSaasController::atualizarEmpresa).
        $empresa = Empresa::query()->whereKey($empresaId)->first(['id', 'estado_licenca']);
        $bloqueantes = [Empresa::LICENCA_SUSPENSA, Empresa::LICENCA_CANCELADA, Empresa::LICENCA_EM_ENCERRAMENTO, Empresa::LICENCA_ENCERRADA];
        if ($empresa === null || ! in_array($empresa->estado_licenca, $bloqueantes, true)) {
            return false;
        }

        // As rotas da própria licença (pedir renovação, confirmar pagamento, suporte técnico) ficam sempre
        // disponíveis: é por elas que se regulariza e, na suspensão comercial, o suporte nunca fica inacessível.
        return ! str_starts_with((string) $request->route()?->getName(), 'licenca.');
    }
}
