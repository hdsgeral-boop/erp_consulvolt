<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/plataforma/root/empresas — selector de empresas de TODOS os clientes, só para a conta ROOT global (ADR-084).
 * Paginado, com pesquisa por nome da empresa ou do cliente; devolve só metadados de empresa/cliente (id, nome, estado),
 * nunca dados pessoais. A leitura usa a função SECURITY DEFINER `plataforma_empresas()` (a RLS esconde outros clientes).
 * Escolher uma empresa continua a passar por PUT /autenticacao/empresa-activa ou X-Empresa-Id, validados no servidor.
 */
final class RootEmpresasController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $d = $request->validate([
            'pesquisa' => ['nullable', 'string', 'max:100'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'pagina' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $porPagina = (int) ($d['por_pagina'] ?? 25);
        $pagina = (int) ($d['pagina'] ?? 1);

        $consulta = DB::query()->fromSub(DB::query()->fromRaw('plataforma_empresas() e')
            ->leftJoin('tenants as t', 't.id', '=', 'e.tenant_id')
            ->select('e.id', 'e.nome', 'e.nif', 'e.estado', 'e.tenant_id', 't.nome as cliente_nome', 't.slug as cliente_slug'), 'x')
            ->when(! empty($d['pesquisa']), function ($q) use ($d) {
                $termo = '%'.mb_strtolower((string) $d['pesquisa']).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(x.nome) LIKE ?', [$termo])->orWhereRaw('lower(x.cliente_nome) LIKE ?', [$termo]));
            })
            ->when(! empty($d['cliente_id']), fn ($q) => $q->where('x.tenant_id', (int) $d['cliente_id']));

        $total = (clone $consulta)->count();
        $linhas = $consulta->orderByRaw('lower(x.nome)')->orderBy('x.nome')->orderBy('x.id')
            ->forPage($pagina, $porPagina)->get(['x.id', 'x.nome', 'x.nif', 'x.estado', 'x.tenant_id', 'x.cliente_nome', 'x.cliente_slug']);
        $logotipos = $this->logotipos($linhas);

        return RespostaApi::sucesso($linhas->map(fn ($l) => [
            'id' => (int) $l->id,
            'nome' => $l->nome,
            'nif' => $l->nif,
            'logotipo' => $logotipos[(int) $l->id] ?? null,
            'estado' => $l->estado,
            'cliente_id' => (int) $l->tenant_id,
            'cliente_nome' => $l->cliente_nome,
        ])->all(), 'Empresas de todos os clientes obtidas com sucesso.', 200, [
            'paginacao' => ['pagina_atual' => $pagina, 'por_pagina' => $porPagina, 'total' => $total, 'ultima_pagina' => max(1, (int) ceil($total / $porPagina))],
        ]);
    }

    /**
     * Logótipos (data URI) só das empresas da página: a RLS da tabela `empresas` só deixa ler o tenant activo, por isso
     * muda-se o contexto para cada cliente da página, lê-se e repõe-se o tenant anterior (como em ResolverEmpresaAtiva).
     *
     * @param  \Illuminate\Support\Collection<int, object>  $linhas
     * @return array<int, string>
     */
    private function logotipos($linhas): array
    {
        $tenant = app(ContextoTenant::class);
        $anterior = $tenant->id();
        $saida = [];
        try {
            foreach ($linhas->groupBy('tenant_id') as $tenantId => $grupo) {
                $tenant->definir((int) $tenantId);
                $lidos = DB::table('empresas')->whereIn('id', $grupo->pluck('id')->all())->whereNotNull('logotipo')->where('logotipo', '<>', '')->pluck('logotipo', 'id');
                foreach ($lidos as $id => $logo) {
                    if (is_string($logo) && str_starts_with($logo, 'data:image/')) {
                        $saida[(int) $id] = $logo;
                    }
                }
            }
        } finally {
            $tenant->definir($anterior);
        }

        return $saida;
    }
}
