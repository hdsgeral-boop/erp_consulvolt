<?php

namespace App\Http\Resources\Procedimentos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dados do Manual de Procedimentos já calculados pelos serviços (processo, lista, cobertura, definições ou recolha).
 * O construtor é `mixed` porque `JsonResource::collection()` faz `new static([])`.
 */
final class ProcedimentosResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }
}
