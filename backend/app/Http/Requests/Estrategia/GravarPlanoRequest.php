<?php

namespace App\Http\Requests\Estrategia;

use App\Http\Requests\POS\PedidoPOS;

/** Criar (POST) ou editar (PUT) um plano estratégico. Permissão est_plano_editar. */
final class GravarPlanoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['est_plano_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $novo = $this->isMethod('post');
        $obrigatorio = $novo ? 'required' : 'sometimes';

        return [
            'nome' => [$obrigatorio, 'string', 'max:200'],
            'ano_inicio' => [$obrigatorio, 'integer', 'min:1901', 'max:2200'],
            'ano_fim' => [$obrigatorio, 'integer', 'min:1901', 'max:2200'],
            'missao' => ['nullable', 'string', 'max:5000'],
            'visao' => ['nullable', 'string', 'max:5000'],
            'valores' => ['nullable', 'string', 'max:5000'],
            'motivo_excepcao' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nome.required' => 'Dê um nome ao plano (ex.: «Plano Estratégico 2027-2031»).',
            'ano_inicio.required' => 'Indique o ano de início e um ano de fim igual ou posterior.',
            'ano_fim.required' => 'Indique o ano de início e um ano de fim igual ou posterior.',
            'ano_inicio.integer' => 'Indique o ano de início e um ano de fim igual ou posterior.',
            'ano_fim.integer' => 'Indique o ano de início e um ano de fim igual ou posterior.',
        ];
    }
}
