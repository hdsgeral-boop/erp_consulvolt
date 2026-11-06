<?php

namespace App\Http\Resources\POS;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base dos recursos JSON do POS (F0): o contrato público é o do modelo (`toArray()`: colunas, casts e relações carregadas), sem
 * mudar nada em relação ao que os controladores devolviam. Quem acrescenta campos sobrepõe `extras()`.
 * Contrato verificado por tests/Support/ContratoPOS (POS_CONTRATO=verificar).
 */
abstract class RecursoPOS extends JsonResource
{
    /** Sem isto o Laravel reindexa os mapas de chaves numéricas (p.ex. `contagens_numerario`: {"5000": 2}) e a lista JSON mudava. */
    public $preserveKeys = true;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray() + $this->extras();
    }

    /** @return array<string, mixed> campos acrescentados ao modelo */
    protected function extras(): array
    {
        return [];
    }
}
