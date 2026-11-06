<?php

namespace App\Http\Requests\POS\Pos;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\POS\ServicoTerminaisPOS;
use Illuminate\Validation\Rule;

/** Validação de POSController::guardarTerminal (regras e mensagens tal como estavam no controlador). */
final class GuardarTerminalRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['pos_terminais_gerir'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'codigo' => [$this->route('terminal') ? 'nullable' : 'required', 'string', 'max:10'], 'nome' => [$this->route('terminal') ? 'nullable' : 'required', 'string', 'max:255'],
            'tipo' => ['nullable', Rule::in(ServicoTerminaisPOS::TIPOS)], 'armazem_id' => ['nullable', 'integer'], 'cliente_padrao_id' => ['nullable', 'integer'],
            'fundo_maneio_padrao' => ['nullable', 'numeric', 'min:0'], 'unidade_negocio_id' => ['nullable', 'integer'], 'centro_custo_id' => ['nullable', 'integer'],
            'meios_pagamento' => ['sometimes', 'array', 'min:1'], 'meios_pagamento.*' => ['array'],
            'hotel_hora_entrada' => ['nullable', 'date_format:H:i'], 'hotel_hora_saida' => ['nullable', 'date_format:H:i'], 'hotel_tolerancia_atraso_min' => ['nullable', 'integer', 'min:0'],
            'hotel_bloco_horas' => ['nullable', 'boolean'], 'hotel_bloco_horas_de' => ['nullable', 'date_format:H:i'], 'hotel_bloco_horas_ate' => ['nullable', 'date_format:H:i'],
        ];
    }
}
