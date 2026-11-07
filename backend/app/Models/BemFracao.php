<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** bens_fracao — o que está dentro da fracção (inventário comercial); a planta e o inventário físico continuam a ser do Activos. */
class BemFracao extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const ESTADOS = ['NOVO', 'BOM', 'USADO', 'DANIFICADO', 'EM_FALTA'];

    public const ORIGENS = ['MANUAL', 'INVENTARIO', 'COMPRA', 'ARMAZEM'];

    protected $table = 'bens_fracao';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'fracao_id', 'produto_id', 'descricao', 'quantidade', 'ativo_id', 'estado', 'origem', 'data', 'notas', 'necessidade_id', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'fracao_id' => 'integer', 'produto_id' => 'integer', 'ativo_id' => 'integer', 'necessidade_id' => 'integer', 'quantidade' => 'float', 'data' => 'date:Y-m-d'];
    }
}
