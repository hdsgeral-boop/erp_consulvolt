<?php

namespace App\Http\Requests\POS\Lavandaria;

use App\Http\Requests\POS\PedidoPOS;

/** Validação de LavandariaController::guardarDefinicoes (regras e mensagens tal como estavam no controlador). */
final class GuardarDefinicoesRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['lav_tabelas'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['taxa_armazenagem_ativa' => ['sometimes', 'boolean'], 'dias_armazenagem_gratis' => ['sometimes', 'integer', 'min:0'],
            'percentagem_armazenagem_dia' => ['sometimes', 'numeric', 'min:0'], 'percentagem_adiantamento' => ['sometimes', 'numeric', 'between:0,100'],
            'percentagem_urgencia' => ['sometimes', 'numeric', 'min:0'], 'fator_prazo_urgencia' => ['sometimes', 'numeric', 'between:0.1,1'],
            'valor_taxa_recolha' => ['sometimes', 'numeric', 'min:0'], 'valor_taxa_entrega' => ['sometimes', 'numeric', 'min:0'], 'dias_reclamacao' => ['sometimes', 'integer', 'min:0'],
            'conta_extras' => ['sometimes', 'nullable', 'string', 'max:20'], 'taxa_extras' => ['sometimes', 'numeric', 'min:0'],
            'conta_compensacao' => ['sometimes', 'nullable', 'string', 'max:20'], 'faturar_no_adiantamento' => ['sometimes', 'boolean']];
    }
}
