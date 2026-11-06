<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;
use App\Rules\TaxaIvaLegal;
use App\Services\POS\Lavandaria\RegrasLavandaria;
use Illuminate\Validation\Rule;

/** Validação de LavandariaController::guardarServico (regras e mensagens tal como estavam no controlador). */
final class GuardarServicoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_tabelas'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['nome' => [$this->route('servico') ? 'sometimes' : 'required', 'string', 'max:255'], 'grupo' => [$this->route('servico') ? 'sometimes' : 'required', Rule::in(RegrasLavandaria::GRUPOS)],
            'codigo_conta' => [$this->route('servico') ? 'sometimes' : 'required', 'string', 'max:20'], 'taxa_imposto' => ['sometimes', 'numeric', new TaxaIvaLegal],
            'dias_entrega' => ['sometimes', 'integer', 'min:0'], 'requer_orcamento' => ['sometimes', 'boolean'], 'ativa' => ['sometimes', 'boolean'],
            'codigo_isencao_fe' => ['sometimes', 'nullable', 'string', 'max:10'], 'conta_iva_liquidado' => ['sometimes', 'nullable', 'string', 'max:20']];
    }
}
