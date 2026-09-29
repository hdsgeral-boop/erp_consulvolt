<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_contabeis_vendas (módulo Vendas). Legado: sales_accounting_config · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfigContabilVenda.
 */
abstract class ConfigContabilVendaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_contabeis_vendas';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = [
        'empresa_id', 'codigo_conta', 'chave',
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
