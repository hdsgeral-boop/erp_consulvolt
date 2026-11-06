<?php

namespace App\Http\Requests\POS\Impressao;

use App\Http\Requests\POS\PedidoPOS;

/** Gravação da configuração de impressão do POS (empresa ou sobreposição de um terminal). */
final class GuardarConfigImpressaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_print_config'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'terminal_pos_id' => ['nullable', 'integer', 'min:1'],
            'formato' => ['nullable', 'string', 'in:TERMICO,A4'],
            'largura' => ['nullable', 'integer', 'in:58,80'],
            'automatico' => ['nullable', 'boolean'],
            'consulta' => ['nullable', 'string', 'in:PREVISUALIZAR,DIRECTO'],
            'rodape' => ['nullable', 'string', 'max:200'],
        ];
    }
}
