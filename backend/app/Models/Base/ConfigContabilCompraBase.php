<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_contabeis_compras (módulo Compras). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfigContabilCompra.
 */
abstract class ConfigContabilCompraBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_contabeis_compras';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'chave', 'codigo_conta',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
