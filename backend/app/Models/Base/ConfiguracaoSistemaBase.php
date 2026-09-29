<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_sistema (módulo Sistema). Legado: system_config · 4 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfiguracaoSistema.
 */
abstract class ConfiguracaoSistemaBase extends ModeloBase
{
    use Auditavel;

    protected $table = 'configuracoes_sistema';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'chave', 'valor',
    ];

    protected function casts(): array
    {
        return [
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
