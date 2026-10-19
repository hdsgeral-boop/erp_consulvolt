<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Models\Tenant;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\IsolamentoRls;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gestão de activação/desligamento do SaaS e Licenciamento (Geral e por Empresa).
 * Protegido com RBAC estrito: apenas Superadministrador da Plataforma (middleware `plataforma`).
 */
final class PlataformaSaasController extends Controller
{
    /** GET /api/plataforma/saas/estado */
    public function estadoGeral(Request $request): JsonResponse
    {
        $definicoes = DB::table('definicoes_plataforma')->pluck('valor', 'chave');
        $saasConfig = (bool) config('erp.saas.ativo');
        $licencasConfig = (bool) config('erp.licencas.ativo');

        // Valores dinâmicos das definições se foram alterados na base de dados
        $saasAtivo = isset($definicoes['saas_ativo']) ? $definicoes['saas_ativo'] === 'true' : $saasConfig;
        $licencasAtivas = isset($definicoes['licencas_ativas']) ? $definicoes['licencas_ativas'] === 'true' : $licencasConfig;
        $diasTeste = isset($definicoes['dias_teste_padrao']) ? (int) $definicoes['dias_teste_padrao'] : (int) config('erp.licencas.dias_teste', 7);

        $totalTenants = Tenant::query()->count();
        $totalEmpresas = Empresa::query()->count();

        $hoje = now()->startOfDay();

        $empresasAtivas = Empresa::query()
            ->where(function ($q) use ($hoje) {
                $q->where('licenca_origem', Empresa::LICENCA_SEM_LIMITE)
                    ->orWhere(function ($q2) use ($hoje) {
                        $q2->whereNotNull('licenca_fim')->where('licenca_fim', '>=', $hoje->toDateString());
                    });
            })
            ->count();

        $empresasEmTeste = Empresa::query()
            ->where('licenca_origem', Empresa::LICENCA_TESTE)
            ->where('licenca_fim', '>=', $hoje->toDateString())
            ->count();

        $empresasExpiradas = Empresa::query()
            ->where('licenca_origem', '!=', Empresa::LICENCA_SEM_LIMITE)
            ->whereNotNull('licenca_fim')
            ->where('licenca_fim', '<', $hoje->toDateString())
            ->count();

        $empresasSemLimite = Empresa::query()
            ->where('licenca_origem', Empresa::LICENCA_SEM_LIMITE)
            ->count();

        $rls = new IsolamentoRls(DB::connection());
        $tabelasSemRls = $rls->tabelasSemPolitica();

        return RespostaApi::sucesso([
            'saas_ativo' => $saasAtivo,
            'licencas_ativas' => $licencasAtivas,
            'dias_teste_padrao' => $diasTeste,
            'rls_cobertura_completa' => count($tabelasSemRls) === 0,
            'tabelas_sem_rls' => $tabelasSemRls,
            'estatisticas' => [
                'tenants' => $totalTenants,
                'empresas_total' => $totalEmpresas,
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

        $empresas = DB::table('empresas as e')
            ->leftJoin('tenants as t', 't.id', '=', 'e.tenant_id')
            ->select([
                'e.id', 'e.nome', 'e.nif', 'e.tenant_id', 'e.licenca_origem', 'e.licenca_inicio', 'e.licenca_fim',
                't.nome as tenant_nome', 't.slug as tenant_slug', 't.plano as tenant_plano',
            ])
            ->orderBy('e.id')
            ->get()
            ->map(function ($emp) use ($hoje) {
                $fim = $emp->licenca_fim ? Carbon::parse($emp->licenca_fim)->startOfDay() : null;
                $expirada = $emp->licenca_origem !== Empresa::LICENCA_SEM_LIMITE && $fim !== null && $fim->lt($hoje);
                $diasRestantes = ($emp->licenca_origem === Empresa::LICENCA_SEM_LIMITE || $fim === null)
                    ? null
                    : ($expirada ? 0 : (int) $hoje->diffInDays($fim));

                return [
                    'id' => (int) $emp->id,
                    'nome' => (string) $emp->nome,
                    'nif' => $emp->nif,
                    'tenant_id' => $emp->tenant_id,
                    'tenant_nome' => $emp->tenant_nome,
                    'tenant_slug' => $emp->tenant_slug,
                    'tenant_codigo' => $emp->tenant_slug,
                    'tenant_plano' => $emp->tenant_plano,
                    'origem' => $emp->licenca_origem ?: Empresa::LICENCA_SEM_LIMITE,
                    'inicio' => $emp->licenca_inicio,
                    'fim' => $emp->licenca_fim,
                    'dias_restantes' => $diasRestantes,
                    'expirada' => $expirada,
                    'somente_leitura' => $expirada,
                ];
            })
            ->all();

        return RespostaApi::sucesso($empresas, 'Empresas obtidas para gestão de SaaS.');
    }

    /** PUT /api/plataforma/saas/empresas/{empresa} */
    public function atualizarEmpresa(Request $request, int $empresaId): JsonResponse
    {
        $empresa = Empresa::query()->findOrFail($empresaId);

        $d = $request->validate([
            'origem' => ['required', 'string', 'in:SEM_LIMITE,TESTE,PLANO'],
            'inicio' => ['nullable', 'date'],
            'fim' => ['nullable', 'date'],
            'dias_adicionar' => ['nullable', 'integer'],
            'revogar' => ['nullable', 'boolean'],
        ]);

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
}
