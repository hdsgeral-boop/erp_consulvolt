<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** objectivos_estrategicos — objectivo do plano ou OKR do trimestre (horizonte TRIMESTRE, pendurado num objectivo do plano). */
class ObjectivoEstrategico extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'objectivos_estrategicos';

    protected string $moduloAuditoria = 'Estratégia';

    protected $fillable = ['empresa_id', 'plano_estrategico_id', 'perspectiva_id', 'objectivo_pai_id', 'unidade_organica_id', 'responsavel_colaborador_id', 'responsavel_externo', 'nome', 'descricao', 'horizonte', 'periodo', 'estado', 'ordem', 'posicao_mapa', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'plano_estrategico_id' => 'integer', 'perspectiva_id' => 'integer', 'objectivo_pai_id' => 'integer', 'unidade_organica_id' => 'integer', 'responsavel_colaborador_id' => 'integer', 'ordem' => 'integer', 'posicao_mapa' => 'decimal:2'];
    }

    public function indicadores(): HasMany
    {
        return $this->hasMany(IndicadorEstrategico::class, 'objectivo_id');
    }
}
