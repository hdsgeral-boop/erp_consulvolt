<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela documentos_anexos (módulo Sistema). Legado: documents · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\DocumentoAnexo.
 */
abstract class DocumentoAnexoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'documentos_anexos';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'tipo_documento_id', 'tipo_entidade', 'entidade_id', 'nome_ficheiro', 'conteudo_ficheiro', 'tipo_mime', 'data_carregamento',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'tipo_documento_id' => 'integer',
            'data_carregamento' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }
}
