<?php

namespace App\Http\Resources\POS;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Configuração de impressão do POS: valores da empresa, sobreposição do terminal (ou null) e valores efectivos para o posto. */
final class ConfigImpressaoPOSResource extends JsonResource
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
