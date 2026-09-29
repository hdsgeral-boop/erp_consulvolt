<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela regras_internas_ia (módulo Sistema). Legado: internal_rules · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RegraInternaIA.
 */
abstract class RegraInternaIABase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'regras_internas_ia';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'nome', 'palavras_chave', 'modelo',
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
