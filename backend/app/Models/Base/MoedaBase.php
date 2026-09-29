<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela moedas (módulo Sistema). Legado: currencies · 5 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\Moeda.
 */
abstract class MoedaBase extends ModeloBase
{
    use Auditavel, SoftDeletes;

    protected $table = 'moedas';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'codigo', 'nome', 'simbolo', 'casas_decimais', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'casas_decimais' => 'integer',
            'ativo' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }
}
