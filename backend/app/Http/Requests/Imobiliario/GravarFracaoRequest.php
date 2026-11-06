<?php

namespace App\Http\Requests\Imobiliario;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\Imobiliario\CatalogoImobiliario;
use Illuminate\Validation\Rule;

/**
 * Criar (POST) e editar (PUT) uma fracção. Permissão imo_fraccao_editar, verificada antes da validação. O estado só se escolhe na
 * criação; depois muda por POST /fracoes/{id}/estado (com trilho). Unicidade e áreas são regras do serviço.
 */
final class GravarFracaoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['imo_fraccao_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'codigo' => ['nullable', 'string', 'max:40'], 'designacao' => ['nullable', 'string', 'max:120'],
            'natureza' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::NATUREZAS))],
            'bloco' => ['nullable', 'string', 'max:40'], 'andar' => ['nullable', 'string', 'max:60'], 'numero' => ['nullable', 'string', 'max:30'],
            'piso' => ['nullable', 'integer', 'min:-20', 'max:300'],
            'tipologia' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::TIPOLOGIAS))], 'finalidade' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::FINALIDADES))],
            'area_bruta' => ['nullable', 'numeric', 'min:0', 'max:999999999999'], 'area_util' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'quartos' => ['nullable', 'integer', 'min:0', 'max:1000'], 'casas_banho' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'mobilada' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::MOBILIA))],
            'estado' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::ESTADOS_FRACAO))],
            'matriz_predial' => ['nullable', 'string', 'max:60'], 'valor_patrimonial' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'renda_referencia' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'venda_referencia' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'ordem' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'documentos' => ['nullable', 'array', 'max:50'], 'documentos.*.tipo' => ['nullable', 'string', 'max:20'], 'documentos.*.nome' => ['nullable', 'string', 'max:200'],
            'documentos.*.url' => ['nullable', 'string', 'max:1000'], 'documentos.*.data' => ['nullable', 'string', 'max:10'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
