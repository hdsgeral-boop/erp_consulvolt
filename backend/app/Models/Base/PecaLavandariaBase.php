<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela pecas_lavandaria (módulo POS). Legado: lav_pieces · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PecaLavandaria.
 */
abstract class PecaLavandariaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'pecas_lavandaria';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'codigo', 'criado_por', 'nome', 'tecido', 'cor', 'unidade', 'ativo', 'atualizado_por', 'preco', 'precos_servico',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo' => 'boolean',
            'preco' => 'decimal:2',
            'precos_servico' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
