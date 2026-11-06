<?php

namespace App\Http\Resources\Estrategia;

use App\Models\ObjectivoEstrategico;
use App\Services\Estrategia\Periodos;
use App\Services\Estrategia\Vocabulario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Um objectivo (ou OKR) do plano. Construtor `mixed` (ver EstrategiaResource). */
final class ObjectivoEstrategicoResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ObjectivoEstrategico $o */
        $o = $this->resource;

        return [
            'id' => $o->id, 'plano_estrategico_id' => $o->plano_estrategico_id, 'perspectiva_id' => $o->perspectiva_id, 'objectivo_pai_id' => $o->objectivo_pai_id,
            'unidade_organica_id' => $o->unidade_organica_id, 'responsavel_colaborador_id' => $o->responsavel_colaborador_id, 'responsavel_externo' => $o->responsavel_externo,
            'nome' => $o->nome, 'descricao' => $o->descricao, 'horizonte' => $o->horizonte, 'horizonte_rotulo' => Vocabulario::HORIZONTES[$o->horizonte] ?? $o->horizonte,
            'periodo' => $o->periodo, 'periodo_rotulo' => $o->periodo ? Periodos::rotulo($o->periodo) : null,
            'estado' => $o->estado, 'estado_rotulo' => Vocabulario::ESTADOS_OBJECTIVO[$o->estado] ?? $o->estado, 'ordem' => $o->ordem, 'posicao_mapa' => $o->posicao_mapa,
        ];
    }
}
