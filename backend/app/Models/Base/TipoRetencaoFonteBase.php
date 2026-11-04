<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela tipos_retencao_fonte (módulo Vendas): catálogo, por empresa, dos tipos de retenção na fonte dos recibos de clientes.
 */
abstract class TipoRetencaoFonteBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'tipos_retencao_fonte';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = ['empresa_id', 'codigo', 'descricao', 'percentagem', 'conta_imposto', 'ativo'];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'percentagem' => 'decimal:4',
            'ativo' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
