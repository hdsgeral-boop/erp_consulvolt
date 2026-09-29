<?php

namespace App\Http\Requests\Contabilidade;

use Illuminate\Foundation\Http\FormRequest;

/** Filtros comuns dos mapas contabilísticos (balancete, razão, desequilíbrios). */
final class RelatorioPeriodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $obrigatorio = $this->routeIs('contabilidade.relatorios.desequilibrios') ? 'nullable' : 'required';

        return [
            'data_inicio' => [$obrigatorio, 'date_format:Y-m-d'],
            'data_fim' => [$obrigatorio, 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'prefixo' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.]+$/'],
            'nivel' => ['nullable', 'integer', 'min:1', 'max:20'],
            'excluir_estornos' => ['nullable', 'boolean'],
            'so_com_saldo' => ['nullable', 'boolean'],
            'codigo_conta' => [$this->routeIs('contabilidade.relatorios.razao') ? 'required' : 'nullable', 'string', 'max:20'],
            'terceiro_id' => ['nullable', 'integer'],
        ];
    }
}
