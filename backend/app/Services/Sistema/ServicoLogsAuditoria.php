<?php

namespace App\Services\Sistema;

use App\Models\LogAuditoria;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Consulta de auditoria da empresa activa (o isolamento vem do EscopoEmpresa).
 * Os índices (empresa_id, ocorrido_em) e (empresa_id, modulo, ocorrido_em) cobrem os filtros principais;
 * o filtro por datas permite ao PostgreSQL ler apenas as partições anuais necessárias.
 */
final class ServicoLogsAuditoria
{
    /** @param  array<string, mixed>  $filtros */
    public function listar(array $filtros): LengthAwarePaginator
    {
        $consulta = LogAuditoria::query()
            ->when($filtros['modulo'] ?? null, fn ($q, $v) => $q->where('modulo', $v))
            ->when($filtros['acao'] ?? null, fn ($q, $v) => $q->where('acao', $v))
            ->when($filtros['nome_utilizador'] ?? null, fn ($q, $v) => $q->where('nome_utilizador', $v))
            ->when($filtros['tabela'] ?? null, fn ($q, $v) => $q->where('tabela', $v))
            ->when($filtros['registo_id'] ?? null, fn ($q, $v) => $q->where('registo_id', $v))
            ->when($filtros['data_inicio'] ?? null, fn ($q, $v) => $q->where('ocorrido_em', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filtros['data_fim'] ?? null, fn ($q, $v) => $q->where('ocorrido_em', '<=', Carbon::parse($v)->endOfDay()))
            ->when($filtros['pesquisa'] ?? null, function ($q, $v) {
                $termo = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v).'%';
                $q->where(fn ($s) => $s->where('detalhes', 'ilike', $termo)->orWhere('nome_utilizador', 'ilike', $termo));
            })
            ->orderByDesc('ocorrido_em')
            ->orderByDesc('id');

        return $consulta->paginate(perPage: (int) ($filtros['por_pagina'] ?? 50), page: (int) ($filtros['pagina'] ?? 1));
    }
}
