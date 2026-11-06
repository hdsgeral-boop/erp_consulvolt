<?php

namespace App\Http\Requests\Estrategia;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\Estrategia\Vocabulario;
use Illuminate\Validation\Rule;

/** Criar ou editar um indicador estratégico. Permissão est_ind_editar. */
final class GravarIndicadorRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['est_ind_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $obrigatorio = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'nome' => [$obrigatorio, 'string', 'max:200'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'objectivo_id' => [$obrigatorio, 'integer'],
            'origem' => [$obrigatorio, Rule::in(array_keys(Vocabulario::ORIGENS))],
            'fonte' => ['nullable', 'array'],
            'fonte.modulo' => ['nullable', 'string', 'max:40'],
            'fonte.operacao' => ['nullable', Rule::in(array_keys(Vocabulario::OPERACOES))],
            'fonte.campo' => ['nullable', 'string', 'max:40'],
            'fonte.filtros' => ['nullable', 'array', 'max:10'],
            'fonte.filtros.*.campo' => ['required_with:fonte.filtros', 'string', 'max:40'],
            'fonte.filtros.*.operador' => ['required_with:fonte.filtros', Rule::in(array_keys(Vocabulario::OPERADORES))],
            'fonte.filtros.*.valor' => ['nullable', 'string', 'max:100'],
            'polaridade' => ['sometimes', Rule::in(array_keys(Vocabulario::POLARIDADES))],
            'tipo' => ['sometimes', Rule::in(array_keys(Vocabulario::TIPOS_INDICADOR))],
            'formato' => ['sometimes', Rule::in(array_keys(Vocabulario::FORMATOS))],
            'frequencia' => ['sometimes', Rule::in(array_keys(Vocabulario::FREQUENCIAS))],
            'agregacao' => ['nullable', Rule::in(array_keys(Vocabulario::AGREGACOES))],
            'unidade_medida' => ['nullable', 'string', 'max:30'],
            'linha_base' => ['nullable', 'numeric', 'between:-9999999999999999,9999999999999999'],
            'linha_base_periodo' => ['nullable', 'string', 'max:8'],
            'dono_colaborador_id' => ['nullable', 'integer'],
            'dono_externo' => ['nullable', 'string', 'max:150'],
            'prazo_dias' => ['nullable', 'integer', 'between:0,120'],
            'tolerancia_verde' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'tolerancia_amarela' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'ativo' => ['sometimes', 'boolean'],
            'motivo_excepcao' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nome.required' => 'Dê um nome ao indicador.',
            'objectivo_id.required' => 'Um indicador tem de servir um objectivo.',
            'origem.required' => 'Escolha de onde vem o valor do indicador.',
            'tolerancia_verde.gt' => 'As tolerâncias têm de ser positivas e a amarela menor ou igual à verde.',
            'tolerancia_amarela.gt' => 'As tolerâncias têm de ser positivas e a amarela menor ou igual à verde.',
        ];
    }
}
