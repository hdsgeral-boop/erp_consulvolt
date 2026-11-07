<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** veiculos_contrato — veículos de um contrato (ficam no contrato, não na vaga: mudam com o inquilino). Tabela nova da Onda 3. */
class VeiculoContrato extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'veiculos_contrato';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'contrato_id', 'matricula', 'marca', 'modelo', 'cor', 'porte', 'condutor', 'distico', 'vaga_id', 'ordem'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'contrato_id' => 'integer', 'vaga_id' => 'integer', 'ordem' => 'integer'];
    }
}
