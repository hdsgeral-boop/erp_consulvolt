<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela tokens_bi (módulo Sistema). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\TokenBI.
 */
abstract class TokenBIBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'tokens_bi';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'nome', 'prefixo', 'hash_token', 'conjuntos', 'criado_por_id', 'criado_por', 'expira_em', 'ultimo_uso_em', 'utilizacoes', 'revogado_em', 'revogado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'conjuntos' => 'array',
            'criado_por_id' => 'integer',
            'expira_em' => 'datetime',
            'ultimo_uso_em' => 'datetime',
            'utilizacoes' => 'integer',
            'revogado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
