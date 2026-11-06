<?php

namespace App\Http\Resources\Estrategia;

use App\Models\PlanoEstrategico;
use App\Services\Estrategia\Vocabulario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Um plano estratégico. Construtor `mixed` (ver EstrategiaResource). */
final class PlanoEstrategicoResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PlanoEstrategico $p */
        $p = $this->resource;

        return [
            'id' => $p->id, 'nome' => $p->nome, 'ano_inicio' => $p->ano_inicio, 'ano_fim' => $p->ano_fim,
            'missao' => $p->missao, 'visao' => $p->visao, 'valores' => $p->valores,
            'estado' => $p->estado, 'estado_rotulo' => Vocabulario::ESTADOS_PLANO[$p->estado] ?? $p->estado, 'versao' => $p->versao,
            'congelado' => $p->estado !== 'RASCUNHO', 'em_vigor' => in_array($p->estado, ['APROVADO', 'EM_EXECUCAO'], true),
            'aprovado_em' => $p->aprovado_em?->toIso8601String(), 'aprovado_por' => $p->aprovado_por, 'aprovacao_excepcao' => $p->aprovacao_excepcao,
            'execucao_iniciada_em' => $p->execucao_iniciada_em?->toIso8601String(), 'encerrado_em' => $p->encerrado_em?->toIso8601String(), 'encerrado_por' => $p->encerrado_por,
            'motivo_reabertura' => $p->motivo_reabertura, 'criado_por' => $p->criado_por,
        ];
    }
}
