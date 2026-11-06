<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** indicadores_estrategicos — indicador que mede um objectivo. Valores em numeric(20,4): lidos como texto para o cálculo ser decimal. */
class IndicadorEstrategico extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'indicadores_estrategicos';

    protected string $moduloAuditoria = 'Estratégia';

    protected $fillable = ['empresa_id', 'plano_estrategico_id', 'objectivo_id', 'nome', 'descricao', 'origem', 'rubrica_orcamental_id', 'fonte', 'polaridade', 'tipo', 'formato', 'frequencia', 'agregacao', 'unidade_medida', 'linha_base', 'linha_base_periodo', 'dono_colaborador_id', 'dono_externo', 'prazo_dias', 'tolerancia_verde', 'tolerancia_amarela', 'ativo', 'ordem', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'plano_estrategico_id' => 'integer', 'objectivo_id' => 'integer', 'rubrica_orcamental_id' => 'integer', 'dono_colaborador_id' => 'integer', 'linha_base' => 'string', 'tolerancia_verde' => 'string', 'tolerancia_amarela' => 'string', 'prazo_dias' => 'integer', 'ativo' => 'boolean', 'ordem' => 'integer'];
    }

    /** jsonb `fonte`: um objecto JSON (a BD recusa um array); vazio grava-se `{}`. */
    protected function fonte(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => $v === null ? [] : (json_decode((string) $v, true) ?: []),
            set: fn ($v) => json_encode($v === null || $v === [] ? new \stdClass : $v),
        );
    }

    public function objectivo(): BelongsTo
    {
        return $this->belongsTo(ObjectivoEstrategico::class, 'objectivo_id');
    }

    public function metas(): HasMany
    {
        return $this->hasMany(MetaIndicador::class, 'indicador_id')->orderBy('periodo');
    }

    public function medicoes(): HasMany
    {
        return $this->hasMany(MedicaoIndicador::class, 'indicador_id')->orderBy('periodo');
    }
}
