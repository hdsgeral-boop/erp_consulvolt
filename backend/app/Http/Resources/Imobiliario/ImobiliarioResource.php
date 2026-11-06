<?php

namespace App\Http\Resources\Imobiliario;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dados do módulo imobiliário já calculados pelos serviços (imóvel, fracção, taxas, modelo, resumo, listas). O contrato JSON é o
 * dos arrays dos serviços; `empresa_id` nunca sai. O construtor é `mixed` porque `JsonResource::collection()` faz `new static([])`.
 */
final class ImobiliarioResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $dados = $this->resource instanceof Model ? $this->resource->attributesToArray() : (array) $this->resource;
        unset($dados['empresa_id']);

        return $dados;
    }
}
