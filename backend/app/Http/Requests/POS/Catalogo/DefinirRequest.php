<?php

namespace App\Http\Requests\POS\Catalogo;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\POS\ServicoCatalogoPOS;
use Illuminate\Validation\Rule;

/** Validação de CatalogoServicosPOSController::definir (regras e mensagens tal como estavam no controlador). */
final class DefinirRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['vendas_produtos_gerir'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'servicos_pos' => ['present', 'nullable', 'array', 'max:'.count(ServicoCatalogoPOS::SERVICOS)],
            'servicos_pos.*' => ['string', Rule::in(ServicoCatalogoPOS::SERVICOS)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['servicos_pos' => 'serviços POS'];
    }
}
