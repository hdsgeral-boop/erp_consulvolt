<?php

namespace App\Http\Resources\Estrategia;

use App\Models\PerspectivaEstrategica;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Uma perspectiva do plano. Construtor `mixed` (ver EstrategiaResource). */
final class PerspectivaEstrategicaResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PerspectivaEstrategica $p */
        $p = $this->resource;

        return ['id' => $p->id, 'plano_estrategico_id' => $p->plano_estrategico_id, 'ordem' => $p->ordem, 'nome' => $p->nome, 'descricao' => $p->descricao, 'cor' => $p->cor, 'icone' => $p->icone];
    }
}
