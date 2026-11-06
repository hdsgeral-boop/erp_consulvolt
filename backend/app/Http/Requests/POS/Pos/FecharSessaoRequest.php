<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de POSController::fecharSessao (regras e mensagens tal como estavam no controlador). */
final class FecharSessaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_fecho'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['contagens' => ['nullable', 'array'], 'contagens.*' => ['integer', 'min:0'], 'numerario_contado' => ['nullable', 'numeric', 'min:0'],
            'fechos_tpa' => ['nullable', 'array'], 'fechos_tpa.*.meio_id' => ['required', 'string'], 'fechos_tpa.*.valor_talao' => ['required', 'numeric', 'min:0'],
            'fechos_tpa.*.operacoes_talao' => ['nullable', 'integer', 'min:0'], 'fechos_tpa.*.referencia_lote' => ['nullable', 'string', 'max:60'],
            'justificacao' => ['nullable', 'string', 'max:2000']];
    }
}
