<?php

namespace App\Http\Resources\Estrategia;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dados do Planeamento Estratégico já calculados pelos serviços (listas, série, metas, catálogos, estrutura).
 * O construtor é `mixed` porque `JsonResource::collection()` faz `new static([])`.
 */
final class EstrategiaResource extends JsonResource
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
