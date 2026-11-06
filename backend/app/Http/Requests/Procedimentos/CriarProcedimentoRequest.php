<?php

namespace App\Http\Requests\Procedimentos;

use App\Http\Requests\POS\PedidoPOS;

/** Novo processo próprio do Manual de Procedimentos: só o título (a chave sai dele). Permissão proc_editar. */
final class CriarProcedimentoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['proc_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['titulo' => ['required', 'string', 'max:200']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['titulo.required' => 'Dê um título ao processo.'];
    }
}
