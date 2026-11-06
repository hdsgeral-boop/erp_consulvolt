<?php

namespace App\Http\Requests\Imobiliario;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\Imobiliario\CatalogoImobiliario;
use Illuminate\Validation\Rule;

/** Gerar fracções por piso, bloco e posição (POST /imoveis/{id}/fracoes/gerar). Permissão imo_fraccao_editar. */
final class GerarFracoesRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['imo_fraccao_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pisos' => ['nullable', 'integer', 'min:0', 'max:500'], 'por_piso' => ['nullable', 'integer', 'min:1', 'max:500'], 'piso_inicial' => ['nullable', 'integer', 'min:-20', 'max:300'],
            'blocos' => ['nullable', 'array', 'max:50'], 'blocos.*' => ['nullable', 'string', 'max:40'],
            'esquema' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::ESQUEMAS))],
            'tipologia' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::TIPOLOGIAS))], 'finalidade' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::FINALIDADES))],
            'area_bruta' => ['nullable', 'numeric', 'min:0', 'max:999999999999'], 'area_util' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'quartos' => ['nullable', 'integer', 'min:0', 'max:1000'], 'casas_banho' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'mobilada' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::MOBILIA))], 'renda_referencia' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
        ];
    }
}
