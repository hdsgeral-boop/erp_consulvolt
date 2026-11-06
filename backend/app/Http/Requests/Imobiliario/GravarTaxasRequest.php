<?php

namespace App\Http\Requests\Imobiliario;

use App\Http\Requests\POS\PedidoPOS;
use App\Models\TaxaImobiliario;

/**
 * Gravar as taxas de um ano (PUT /taxas/{ano}) ou registar a sua validação (POST /taxas/{ano}/validar, campo `nota`).
 * Permissão imo_taxas_editar (sensível). Os limites (0 a 100 nas percentagens) são regra do serviço, com mensagem própria.
 */
final class GravarTaxasRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['imo_taxas_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_fill_keys(TaxaImobiliario::CAMPOS, ['nullable', 'numeric', 'min:0', 'max:9999999999999999']) + ['nota' => ['nullable', 'string', 'max:300']];
    }
}
