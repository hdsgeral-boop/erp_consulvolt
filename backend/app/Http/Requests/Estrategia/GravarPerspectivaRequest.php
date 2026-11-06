<?php

namespace App\Http\Requests\Estrategia;

use App\Http\Requests\POS\PedidoPOS;

/** Criar ou editar uma perspectiva do plano. Permissão est_plano_editar. */
final class GravarPerspectivaRequest extends PedidoPOS
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
            'nome' => [$obrigatorio, 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:300'],
            'ordem' => ['sometimes', 'integer', 'min:-32000', 'max:32000'],
            'cor' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icone' => ['sometimes', 'string', 'regex:/^fa-[a-z0-9-]+$/', 'max:40'],
            'motivo_excepcao' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nome.required' => 'Dê um nome à perspectiva.',
            'cor.regex' => 'A cor tem de ter o formato #RRGGBB.',
            'icone.regex' => 'O ícone tem de ser um nome «fa-…».',
        ];
    }
}
