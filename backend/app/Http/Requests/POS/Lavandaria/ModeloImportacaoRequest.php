<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de ExportacaoLavandariaController::modelo (o tipo vai na rota e é validado pelo serviço). */
final class ModeloImportacaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_tabelas'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
