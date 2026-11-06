<?php

namespace App\Http\Requests\Imobiliario;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\Imobiliario\CatalogoImobiliario;
use Illuminate\Validation\Rule;

/**
 * Criar (POST) e editar (PUT) um imóvel. Permissão imo_imovel_editar, verificada antes da validação (403 e não 422).
 * As regras de negócio com mensagem própria (designação, proprietário, áreas, códigos únicos, edifício) ficam no serviço.
 */
final class GravarImovelRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['imo_imovel_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'codigo' => ['nullable', 'string', 'max:20'], 'nome' => ['nullable', 'string', 'max:200'],
            'tipo' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::TIPOS_IMOVEL))], 'papel' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::PAPEIS))],
            'n_pisos' => ['nullable', 'integer', 'min:0', 'max:300'], 'piso_inicial' => ['nullable', 'integer', 'min:-20', 'max:300'], 'n_habitacoes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'proprietario_terceiro_id' => ['nullable', 'integer', 'min:1'], 'ativo_edificio_id' => ['nullable', 'integer', 'min:1'],
            'morada' => ['nullable', 'string', 'max:300'], 'municipio' => ['nullable', 'string', 'max:100'], 'provincia' => ['nullable', 'string', 'max:100'],
            'coordenadas' => ['nullable', 'string', 'max:60'], 'matriz_predial' => ['nullable', 'string', 'max:60'], 'registo_predial' => ['nullable', 'string', 'max:120'],
            'licenca_utilizacao' => ['nullable', 'string', 'max:120'],
            'area_terreno' => ['nullable', 'numeric', 'min:0', 'max:999999999999'], 'area_bruta' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'area_util' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'valor_patrimonial' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'valor_patrimonial_data' => ['nullable', 'date'],
            'isencao_detencao' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::ISENCOES_DETENCAO))],
            'valor_aquisicao' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'data_aquisicao' => ['nullable', 'date'],
            'estado' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::ESTADOS_IMOVEL))],
            'documentos' => ['nullable', 'array', 'max:50'], 'documentos.*.tipo' => ['nullable', 'string', 'max:20'], 'documentos.*.nome' => ['nullable', 'string', 'max:200'],
            'documentos.*.url' => ['nullable', 'string', 'max:1000'], 'documentos.*.data' => ['nullable', 'string', 'max:10'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
