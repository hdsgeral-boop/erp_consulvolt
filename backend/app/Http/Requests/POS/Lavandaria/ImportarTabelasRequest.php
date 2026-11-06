<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\POS\Lavandaria\ServicoImportacaoTabelasLavandaria;
use Illuminate\Validation\Rule;

/** Validação de ImportacaoLavandariaController::__invoke (regras e mensagens tal como estavam no controlador). */
final class ImportarTabelasRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_tabelas'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(ServicoImportacaoTabelasLavandaria::TIPOS)],
            'ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
            'simular' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ficheiro' => 'ficheiro', 'tipo' => 'tabela a importar'];
    }
}
