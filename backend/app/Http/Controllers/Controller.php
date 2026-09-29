<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class Controller
{
    /**
     * Exige pelo menos uma das permissões do catálogo do legado (ecrã "<id>_view" ou tarefa).
     * Recusa com 403 (envelope SEM_PERMISSAO).
     */
    protected function exigir(string ...$permissoes): void
    {
        if (! Gate::any($permissoes)) {
            throw new AccessDeniedHttpException;
        }
    }
}
