<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** metas_indicadores — meta da empresa para um indicador num período. */
class MetaIndicador extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'metas_indicadores';

    protected string $moduloAuditoria = 'Estratégia';

    protected $fillable = ['empresa_id', 'indicador_id', 'periodo', 'valor', 'valor_minimo', 'nota', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'indicador_id' => 'integer', 'valor' => 'string', 'valor_minimo' => 'string'];
    }
}
