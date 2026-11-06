<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** medicoes_indicadores — valor medido (ou entregue) de um indicador num período; unidade nula = empresa. */
class MedicaoIndicador extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'medicoes_indicadores';

    protected string $moduloAuditoria = 'Estratégia';

    protected $fillable = ['empresa_id', 'indicador_id', 'periodo', 'unidade_organica_id', 'valor', 'origem', 'nota', 'detalhe', 'medido_em', 'medido_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'indicador_id' => 'integer', 'unidade_organica_id' => 'integer', 'valor' => 'string', 'medido_em' => 'datetime'];
    }
}
