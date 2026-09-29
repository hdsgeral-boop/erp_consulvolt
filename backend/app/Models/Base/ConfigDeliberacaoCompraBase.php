<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_deliberacao_compras (módulo Compras). Legado: pa_settings · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfigDeliberacaoCompra.
 */
abstract class ConfigDeliberacaoCompraBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_deliberacao_compras';

    protected string $moduloAuditoria = 'Compras';

    protected $fillable = [
        'empresa_id', 'niveis', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'niveis' => 'array',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
