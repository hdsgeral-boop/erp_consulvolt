<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** perspectivas_estrategicas — perspectivas do Balanced Scorecard de um plano. */
class PerspectivaEstrategica extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'perspectivas_estrategicas';

    protected string $moduloAuditoria = 'Estratégia';

    protected $fillable = ['empresa_id', 'plano_estrategico_id', 'ordem', 'nome', 'descricao', 'cor', 'icone', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'plano_estrategico_id' => 'integer', 'ordem' => 'integer'];
    }
}
