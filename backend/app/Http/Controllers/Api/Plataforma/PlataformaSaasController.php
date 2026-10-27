<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Tenant;
use App\Services\Plataforma\ServicoClientes;
use App\Services\Plataforma\ServicoLicencaCliente;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\IsolamentoRls;
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

        // A licença é do cliente: as contagens são de clientes (a própria plataforma não é um cliente).
        $clientes = $this->clientesComLicenca();
        $empresas = $this->licencasDeTodas();
        $hoje = now()->startOfDay()->toDateString();

        $ativos = $clientes->filter(fn ($c) => $c['origem'] === Tenant::LICENCA_SEM_LIMITE || ($c['fim'] !== null && $c['fim'] >= $hoje))->count();
        $emTeste = $clientes->filter(fn ($c) => $c['origem'] === Tenant::LICENCA_TESTE && $c['fim'] !== null && $c['fim'] >= $hoje)->count();
        $expirados = $clientes->filter(fn ($c) => $c['expirada'])->count();
        $semLimite = $clientes->filter(fn ($c) => $c['origem'] === Tenant::LICENCA_SEM_LIMITE)->count();

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
                'clientes_total' => $clientes->count(),
                'clientes_ativos' => $ativos,
                'clientes_em_teste' => $emTeste,
                'clientes_expirados' => $expirados,
                'clientes_sem_limite' => $semLimite,
                'empresas_total' => $empresas->count(),
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

    /** GET /api/plataforma/saas/clientes — uma linha por cliente, com a sua licença. */
    public function listarClientes(): JsonResponse
    {
        return RespostaApi::sucesso($this->clientesComLicenca()->values()->all(), 'Licenças dos clientes obtidas.');
    }

    /** PUT /api/plataforma/saas/clientes/{cliente} — define a licença do cliente (vale para todas as suas empresas). */
    public function atualizarCliente(Request $request, int $clienteId, ServicoLicencaCliente $licencas): JsonResponse
    {
        $d = $request->validate([
            'origem' => ['required', 'string', 'in:SEM_LIMITE,TESTE,PLANO'],
            'inicio' => ['nullable', 'date'],
            'fim' => ['nullable', 'date'],
            'dias_adicionar' => ['nullable', 'integer'],
            'revogar' => ['nullable', 'boolean'],
        ]);
        if (ServicoClientes::eDaPlataforma($clienteId)) {
            throw new ErroNegocio('A plataforma não é um cliente e não tem licença.', 'PLATAFORMA_SEM_LICENCA', 422);
        }
        $c = $licencas->aplicar($clienteId, $d);

        return RespostaApi::sucesso(['id' => $c->id, 'nome' => $c->nome] + $licencas->estado($c), 'Licença do cliente atualizada com sucesso.');
    }

    /** GET /api/plataforma/saas/empresas — empresas de todos os clientes (cada uma com a licença do seu cliente). */
    public function listarEmpresas(Request $request): JsonResponse
    {
        $tenants = Tenant::query()->get()->keyBy('id');
        $hoje = now()->startOfDay();

        $empresas = $this->licencasDeTodas()->map(function ($emp) use ($tenants) {
            $t = $tenants[$emp->tenant_id] ?? null;
            $estado = $t ? app(ServicoLicencaCliente::class)->estado($t) : ['origem' => Empresa::LICENCA_SEM_LIMITE, 'inicio' => null, 'fim' => null, 'dias_restantes' => null, 'expirada' => false, 'somente_leitura' => false];

            return [
                'id' => (int) $emp->id, 'nome' => (string) $emp->nome, 'nif' => $emp->nif,
                'tenant_id' => (int) $emp->tenant_id, 'tenant_nome' => $t?->nome, 'tenant_slug' => $t?->slug, 'tenant_codigo' => $t?->slug, 'tenant_plano' => $t?->plano,
            ] + $estado;
        })->values()->all();

        return RespostaApi::sucesso($empresas, 'Empresas obtidas para gestão de SaaS.');
    }

    /** PUT /api/plataforma/saas/empresas/{empresa} — mantido: a licença é do cliente, por isso altera a do cliente da empresa. */
    public function atualizarEmpresa(Request $request, int $empresaId, ServicoLicencaCliente $licencas): JsonResponse
    {
        $tenantId = DB::selectOne('SELECT app_tenant_da_empresa(?) AS t', [$empresaId])->t ?? null;
        if ($tenantId === null) {
            throw new ErroNegocio('Empresa não encontrada.', 'NAO_ENCONTRADO', 404);
        }

        return $this->atualizarCliente($request, (int) $tenantId, $licencas);
    }

    /**
     * Licença de cada cliente (a própria plataforma não é um cliente), com as suas empresas e utilizadores.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function clientesComLicenca(): Collection
    {
        $licencas = app(ServicoLicencaCliente::class);
        $contagens = app(ServicoClientes::class)->listar(false)->keyBy(fn (array $i) => $i['tenant']->id);

        return $contagens->map(fn (array $i) => [
            'id' => (int) $i['tenant']->id, 'nome' => $i['tenant']->nome, 'slug' => $i['tenant']->slug, 'estado' => $i['tenant']->estado,
            'plano' => $i['tenant']->plano, 'empresas' => $i['empresas'], 'utilizadores' => $i['utilizadores'],
        ] + $licencas->estado($i['tenant']))->values();
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
