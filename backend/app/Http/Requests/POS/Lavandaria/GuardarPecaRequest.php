<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\POS\Lavandaria\RegrasLavandaria;
use Illuminate\Validation\Rule;

/** Validação de LavandariaController::guardarPeca (regras e mensagens tal como estavam no controlador). */
final class GuardarPecaRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_tabelas'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['nome' => [$this->route('peca') ? 'sometimes' : 'required', 'string', 'max:255'], 'tecido' => ['nullable', 'string', 'max:255'], 'cor' => ['nullable', 'string', 'max:255'],
            'unidade' => ['sometimes', Rule::in(RegrasLavandaria::UNIDADES)], 'preco' => ['sometimes', 'numeric', 'min:0'], 'ativo' => ['sometimes', 'boolean'],
            'precos_servico' => ['sometimes', 'array'], 'precos_servico.*.produto_id' => ['required', 'integer'], 'precos_servico.*.preco' => ['nullable', 'numeric', 'min:0'],
            'precos_servico.*.modo' => ['nullable', Rule::in(RegrasLavandaria::MODOS)], 'precos_servico.*.tamanho' => ['nullable', 'string', 'max:60'],
            'opcoes_preco' => ['sometimes', 'array', 'min:1', 'max:30'], 'opcoes_preco.*.modo' => ['required', Rule::in(RegrasLavandaria::MODOS)],
            'opcoes_preco.*.tamanho' => ['nullable', 'string', 'max:60'], 'opcoes_preco.*.preco' => ['required', 'numeric', 'min:0']];
    }
}
