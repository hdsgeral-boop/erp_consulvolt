<?php

namespace App\Models\Scopes;

use App\Exceptions\ErroContextoEmpresa;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope multi-empresa: injecta `WHERE <tabela>.empresa_id = :empresa_activa` em todas as consultas.
 * Fail-closed: sem empresa activa (e sem `semIsolamento()` explícito) a consulta é recusada.
 */
final class EscopoEmpresa implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $contexto = app(ContextoEmpresa::class);

        if ($contexto->isolamentoDesligado()) {
            return;
        }

        if (! $contexto->definida()) {
            throw ErroContextoEmpresa::naoDefinido();
        }

        $builder->where($model->qualifyColumn('empresa_id'), $contexto->id());
    }
}
