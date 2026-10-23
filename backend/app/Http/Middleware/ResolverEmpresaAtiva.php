<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroContextoEmpresa;
use App\Models\Empresa;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoEmpresa;
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
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $cabecalho = config('erp.tenancy.cabecalho');
        $valor = $request->header($cabecalho);

        if ($valor === null || $valor === '') {
            throw ErroContextoEmpresa::naoIndicado($cabecalho);
        }
        if (! ctype_digit((string) $valor) || (int) $valor <= 0) {
            throw ErroContextoEmpresa::invalido($cabecalho);
        }

        $empresaId = (int) $valor;
        $utilizador = $request->user();

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
     * Licença por empresa (ADR-074): empresa com licença expirada fica só de leitura, sem tolerância. As outras empresas do
     * utilizador não são afectadas. Super-administradores têm acesso vitalício (isentos). Sem efeito com erp.licencas.ativo=false.
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
     * segurança): tecnicamente são leitura, mas contam como "exportar", que a licença expirada também proíbe.
     */
    public function licencaBloqueada(Request $request, $utilizador, int $empresaId): bool
    {
        if (! DefinicoesPlataforma::licencasAtivas() || $utilizador->eSuperAdministrador()) {
            return false;
        }

        $empresa = Empresa::query()->whereKey($empresaId)->first(['id', 'licenca_fim']);
        if ($empresa === null || ! $empresa->licencaExpirada()) {
            return false;
        }

        // As rotas da própria licença (pedir renovação, confirmar pagamento) ficam sempre disponíveis: é por elas que se renova.
        return ! str_starts_with((string) $request->route()?->getName(), 'licenca.');
    }
}
