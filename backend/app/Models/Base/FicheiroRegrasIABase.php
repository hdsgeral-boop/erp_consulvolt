<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela ficheiros_regras_ia (módulo Sistema). Legado: ai_rule_files · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\FicheiroRegrasIA.
 */
abstract class FicheiroRegrasIABase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'ficheiros_regras_ia';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'nome_regra', 'nome', 'ficheiro', 'conteudo', 'ativo', 'motor', 'importado_em', 'importado_por', 'versao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo' => 'boolean',
            'importado_em' => 'datetime',
            'versao' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
