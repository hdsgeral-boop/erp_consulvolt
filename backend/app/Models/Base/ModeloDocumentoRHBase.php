<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela modelos_documentos_rh (módulo RH). Legado: rh_doc_templates · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ModeloDocumentoRH.
 */
abstract class ModeloDocumentoRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'modelos_documentos_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'codigo', 'nome', 'titulo', 'texto', 'ativo', 'auto_emitir', 'assinante', 'cargo_assinante', 'local', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo' => 'boolean',
            'auto_emitir' => 'boolean',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
