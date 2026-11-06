<?php

namespace App\Http\Requests\Estrategia;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\Estrategia\Vocabulario;
use Illuminate\Validation\Rule;

/** Criar ou editar um objectivo (ou OKR) do plano. Permissão est_plano_editar. */
final class GravarObjectivoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['est_plano_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $obrigatorio = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'nome' => [$obrigatorio, 'string', 'max:300'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'perspectiva_id' => [$obrigatorio, 'integer'],
            'objectivo_pai_id' => ['nullable', 'integer'],
            'horizonte' => ['sometimes', Rule::in(array_keys(Vocabulario::HORIZONTES))],
            'periodo' => ['nullable', 'string', 'max:8'],
            'unidade_organica_id' => ['nullable', 'integer'],
            'responsavel_colaborador_id' => ['nullable', 'integer'],
            'responsavel_externo' => ['nullable', 'string', 'max:150'],
            'estado' => ['sometimes', Rule::in(array_keys(Vocabulario::ESTADOS_OBJECTIVO))],
            'posicao_mapa' => ['nullable', 'numeric', 'between:0,100'],
            'motivo_excepcao' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nome.required' => 'Dê um nome ao objectivo.',
            'perspectiva_id.required' => 'Escolha uma perspectiva do plano.',
        ];
    }
}
