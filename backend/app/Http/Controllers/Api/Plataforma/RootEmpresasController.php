<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Api\RespostaApi;
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
            ->select('e.id', 'e.nome', 'e.estado', 'e.tenant_id', 't.nome as cliente_nome', 't.slug as cliente_slug'), 'x')
            ->when(! empty($d['pesquisa']), function ($q) use ($d) {
                $termo = '%'.mb_strtolower((string) $d['pesquisa']).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(x.nome) LIKE ?', [$termo])->orWhereRaw('lower(x.cliente_nome) LIKE ?', [$termo]));
            })
            ->when(! empty($d['cliente_id']), fn ($q) => $q->where('x.tenant_id', (int) $d['cliente_id']));

        $total = (clone $consulta)->count();
        $linhas = $consulta->orderBy('x.cliente_nome')->orderBy('x.nome')->orderBy('x.id')
            ->forPage($pagina, $porPagina)->get(['x.id', 'x.nome', 'x.estado', 'x.tenant_id', 'x.cliente_nome', 'x.cliente_slug']);

        return RespostaApi::sucesso($linhas->map(fn ($l) => [
            'id' => (int) $l->id,
            'nome' => $l->nome,
            'estado' => $l->estado,
            'cliente_id' => (int) $l->tenant_id,
            'cliente_nome' => $l->cliente_nome,
        ])->all(), 'Empresas de todos os clientes obtidas com sucesso.', 200, [
            'paginacao' => ['pagina_atual' => $pagina, 'por_pagina' => $porPagina, 'total' => $total, 'ultima_pagina' => max(1, (int) ceil($total / $porPagina))],
        ]);
    }
}
