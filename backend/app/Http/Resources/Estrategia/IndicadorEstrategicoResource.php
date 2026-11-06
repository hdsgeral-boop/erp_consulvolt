<?php

namespace App\Http\Resources\Estrategia;

use App\Models\IndicadorEstrategico;
use App\Services\Estrategia\PoliticaFinanceira;
use App\Services\Estrategia\Vocabulario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Um indicador. A `fonte` devolve só ids do catálogo (nunca nomes de tabela ou de coluna). A linha de base de um indicador
 * financeiro só se mostra com est_ver_financeiros (`oculto: true` caso contrário). Construtor `mixed` (ver EstrategiaResource).
 */
final class IndicadorEstrategicoResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var IndicadorEstrategico $i */
        $i = $this->resource;
        $oculto = PoliticaFinanceira::oculta($i->formato, $i->origem);

        return [
            'id' => $i->id, 'plano_estrategico_id' => $i->plano_estrategico_id, 'objectivo_id' => $i->objectivo_id,
            'objectivo' => $i->relationLoaded('objectivo') && $i->objectivo ? ['id' => $i->objectivo->id, 'nome' => $i->objectivo->nome, 'perspectiva_id' => $i->objectivo->perspectiva_id, 'horizonte' => $i->objectivo->horizonte] : null,
            'nome' => $i->nome, 'descricao' => $i->descricao, 'origem' => $i->origem, 'origem_rotulo' => Vocabulario::ORIGENS[$i->origem] ?? $i->origem, 'fonte' => $i->fonte,
            'polaridade' => $i->polaridade, 'tipo' => $i->tipo, 'formato' => $i->formato, 'frequencia' => $i->frequencia, 'agregacao' => $i->agregacao, 'unidade_medida' => $i->unidade_medida,
            'linha_base' => $oculto ? null : $i->linha_base, 'linha_base_periodo' => $i->linha_base_periodo, 'oculto' => $oculto,
            'dono_colaborador_id' => $i->dono_colaborador_id, 'dono_externo' => $i->dono_externo, 'prazo_dias' => $i->prazo_dias,
            'tolerancia_verde' => $i->tolerancia_verde, 'tolerancia_amarela' => $i->tolerancia_amarela, 'ativo' => (bool) $i->ativo, 'ordem' => $i->ordem,
            'metas_count' => $i->metas_count ?? null, 'medicoes_count' => $i->medicoes_count ?? null,
        ];
    }
}
