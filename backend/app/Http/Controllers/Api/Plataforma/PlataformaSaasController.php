<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Tenant;
use App\Services\Plataforma\ServicoClientes;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\IsolamentoRls;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gestão de activação/desligamento do SaaS e Licenciamento (Geral e por Empresa).
 * Protegido com RBAC estrito: apenas Superadministrador da Plataforma (middleware `plataforma`).
 *
 * Funciona com RLS real (ligação `erp_app`): o que lê de TODOS os clientes passa pelas funções SECURITY DEFINER
 * (`plataforma_empresas_licencas()`), e as escritas numa empresa correm no contexto do tenant dela.
 */
final class PlataformaSaasController extends Controller
{
    /** GET /api/plataforma/saas/estado */
    public function estadoGeral(Request $request): JsonResponse
    {
        $definicoes = DB::table('definicoes_plataforma')->pluck('valor', 'chave');
        $saasConfig = (bool) config('erp.saas.ativo');
        $licencasConfig = (bool) config('erp.licencas.ativo');

        // Valores gravados na BD (definições). NÃO controlam o ambiente: o efectivo vem da configuração (.env).
        $saasAtivo = isset($definicoes['saas_ativo']) ? $definicoes['saas_ativo'] === 'true' : $saasConfig;
        $licencasAtivas = isset($definicoes['licencas_ativas']) ? $definicoes['licencas_ativas'] === 'true' : $licencasConfig;
        $diasTeste = isset($definicoes['dias_teste_padrao']) ? (int) $definicoes['dias_teste_padrao'] : (int) config('erp.licencas.dias_teste', 7);

        $totalTenants = Tenant::query()->count();

        // Licenças de TODOS os clientes: com RLS real, Empresa::query() só veria as do tenant da plataforma.
        $empresas = $this->licencasDeTodas();
        $hoje = now()->startOfDay()->toDateString();

        $empresasAtivas = $empresas->filter(fn ($e) => $e->licenca_origem === Empresa::LICENCA_SEM_LIMITE
            || ($e->licenca_fim !== null && $e->licenca_fim >= $hoje))->count();
        $empresasEmTeste = $empresas->filter(fn ($e) => $e->licenca_origem === Empresa::LICENCA_TESTE
            && $e->licenca_fim !== null && $e->licenca_fim >= $hoje)->count();
        $empresasExpiradas = $empresas->filter(fn ($e) => $e->licenca_origem !== null
            && $e->licenca_origem !== Empresa::LICENCA_SEM_LIMITE
            && $e->licenca_fim !== null && $e->licenca_fim < $hoje)->count();
        $empresasSemLimite = $empresas->filter(fn ($e) => $e->licenca_origem === Empresa::LICENCA_SEM_LIMITE)->count();

        $isolamento = (new IsolamentoRls(DB::connection()))->estado();

        return RespostaApi::sucesso([
            'saas_ativo' => $saasAtivo,
            'licencas_ativas' => $licencasAtivas,
            'dias_teste_padrao' => $diasTeste,
            // Efectivo = o que a aplicação está mesmo a usar (config/ambiente); o gravado é só a definição na BD.
            'efetivo' => [
                'saas_ativo' => $saasConfig,
                'licencas_ativas' => $licencasConfig,
            ],
            'divergencia' => [
                'saas_ativo' => $saasAtivo !== $saasConfig,
                'licencas_ativas' => $licencasAtivas !== $licencasConfig,
            ],
            // Só «cobertura das políticas»; o estado real do isolamento (todas as verificações) está em `isolamento`.
            'rls_cobertura_completa' => $isolamento['verificacoes']['politicas']['ok'],
            'tabelas_sem_rls' => $isolamento['tabelas_sem_rls'],
            'isolamento' => $isolamento,
            'estatisticas' => [
                'tenants' => $totalTenants,
                'empresas_total' => $empresas->count(),
                'empresas_ativas' => $empresasAtivas,
                'empresas_em_teste' => $empresasEmTeste,
                'empresas_expiradas' => $empresasExpiradas,
                'empresas_sem_limite' => $empresasSemLimite,
            ],
        ], 'Estado geral do SaaS obtido com sucesso.');
    }

    /** PUT /api/plataforma/saas/geral */
    public function atualizarGeral(Request $request): JsonResponse
    {
        $d = $request->validate([
            'saas_ativo' => ['nullable', 'boolean'],
            'licencas_ativas' => ['nullable', 'boolean'],
            'dias_teste_padrao' => ['nullable', 'integer', 'between:1,90'],
        ]);

        if (isset($d['saas_ativo'])) {
            DB::table('definicoes_plataforma')->updateOrInsert(['chave' => 'saas_ativo'], ['valor' => $d['saas_ativo'] ? 'true' : 'false', 'atualizado_em' => now()]);
        }

        if (isset($d['licencas_ativas'])) {
            DB::table('definicoes_plataforma')->updateOrInsert(['chave' => 'licencas_ativas'], ['valor' => $d['licencas_ativas'] ? 'true' : 'false', 'atualizado_em' => now()]);
        }

        if (isset($d['dias_teste_padrao'])) {
            DB::table('definicoes_plataforma')->updateOrInsert(['chave' => 'dias_teste_padrao'], ['valor' => (string) $d['dias_teste_padrao'], 'atualizado_em' => now()]);
        }

        return $this->estadoGeral($request);
    }

    /** GET /api/plataforma/saas/empresas */
    public function listarEmpresas(Request $request): JsonResponse
    {
        $hoje = now()->startOfDay();
        $tenants = Tenant::query()->get(['id', 'nome', 'slug', 'plano'])->keyBy('id');

        $empresas = $this->licencasDeTodas()
            ->map(function ($emp) use ($hoje, $tenants) {
                $fim = $emp->licenca_fim ? Carbon::parse($emp->licenca_fim)->startOfDay() : null;
                $expirada = $emp->licenca_origem !== Empresa::LICENCA_SEM_LIMITE && $fim !== null && $fim->lt($hoje);
                $diasRestantes = ($emp->licenca_origem === Empresa::LICENCA_SEM_LIMITE || $fim === null)
                    ? null
                    : ($expirada ? 0 : (int) $hoje->diffInDays($fim));
                $tenant = $tenants[$emp->tenant_id] ?? null;

                return [
                    'id' => (int) $emp->id,
                    'nome' => (string) $emp->nome,
                    'nif' => $emp->nif,
                    'tenant_id' => (int) $emp->tenant_id,
                    'tenant_nome' => $tenant?->nome,
                    'tenant_slug' => $tenant?->slug,
                    'tenant_codigo' => $tenant?->slug,
                    'tenant_plano' => $tenant?->plano,
                    'origem' => $emp->licenca_origem ?: Empresa::LICENCA_SEM_LIMITE,
                    'inicio' => $emp->licenca_inicio,
                    'fim' => $emp->licenca_fim,
                    'dias_restantes' => $diasRestantes,
                    'expirada' => $expirada,
                    'somente_leitura' => $expirada,
                ];
            })
            ->values()
            ->all();

        return RespostaApi::sucesso($empresas, 'Empresas obtidas para gestão de SaaS.');
    }

    /** PUT /api/plataforma/saas/empresas/{empresa} */
    public function atualizarEmpresa(Request $request, int $empresaId): JsonResponse
    {
        $d = $request->validate([
            'origem' => ['required', 'string', 'in:SEM_LIMITE,TESTE,PLANO'],
            'inicio' => ['nullable', 'date'],
            'fim' => ['nullable', 'date'],
            'dias_adicionar' => ['nullable', 'integer'],
            'revogar' => ['nullable', 'boolean'],
        ]);

        // A empresa é de outro cliente: resolve o tenant (função SECURITY DEFINER) e actua no contexto dele (RLS).
        $tenantId = DB::selectOne('SELECT app_tenant_da_empresa(?) AS t', [$empresaId])->t ?? null;
        if ($tenantId === null) {
            throw new ErroNegocio('Empresa não encontrada.', 'NAO_ENCONTRADO', 404);
        }

        return app(ContextoTenant::class)->executarComo((int) $tenantId, fn () => $this->aplicarLicenca($empresaId, $d));
    }

    /**
     * Grava a licença da empresa. Tem de correr no contexto do tenant dela.
     *
     * @param  array<string, mixed>  $d
     */
    private function aplicarLicenca(int $empresaId, array $d): JsonResponse
    {
        $empresa = Empresa::query()->findOrFail($empresaId);
        $hoje = now()->startOfDay();

        if (! empty($d['revogar'])) {
            // Força expiração imediata
            $empresa->update([
                'licenca_origem' => Empresa::LICENCA_PLANO,
                'licenca_fim' => $hoje->copy()->subDay()->toDateString(),
            ]);
        } elseif ($d['origem'] === Empresa::LICENCA_SEM_LIMITE) {
            $empresa->update([
                'licenca_origem' => Empresa::LICENCA_SEM_LIMITE,
                'licenca_fim' => null,
            ]);
        } else {
            $inicio = ! empty($d['inicio']) ? Carbon::parse($d['inicio']) : ($empresa->licenca_inicio ?: $hoje);
            $fim = ! empty($d['fim']) ? Carbon::parse($d['fim']) : null;

            if (! empty($d['dias_adicionar'])) {
                $base = ($empresa->licenca_fim && Carbon::parse($empresa->licenca_fim)->gte($hoje))
                    ? Carbon::parse($empresa->licenca_fim)
                    : $hoje;
                $fim = $base->copy()->addDays((int) $d['dias_adicionar']);
            }

            if (! $fim && $d['origem'] === Empresa::LICENCA_TESTE) {
                $fim = $hoje->copy()->addDays(7);
            }

            $empresa->update([
                'licenca_origem' => $d['origem'],
                'licenca_inicio' => $inicio->toDateString(),
                'licenca_fim' => $fim ? $fim->toDateString() : null,
            ]);
        }

        return RespostaApi::sucesso([
            'id' => $empresa->id,
            'nome' => $empresa->nome,
            'origem' => $empresa->licenca_origem,
            'inicio' => $empresa->licenca_inicio?->toDateString(),
            'fim' => $empresa->licenca_fim?->toDateString(),
        ], 'Licença da empresa atualizada com sucesso.');
    }

    /**
     * Licença de todas as empresas de todos os clientes (função SECURITY DEFINER; a RLS esconderia as dos outros).
     *
     * @return Collection<int, object>
     */
    private function licencasDeTodas(): Collection
    {
        // as empresas da própria plataforma não são de clientes: não têm licença nem entram nas contagens
        return collect(DB::select('SELECT id, tenant_id, nome, nif, estado, licenca_origem, licenca_inicio, licenca_fim FROM plataforma_empresas_licencas()'))
            ->reject(fn ($e) => ServicoClientes::eDaPlataforma((int) $e->tenant_id))->values();
    }
}
