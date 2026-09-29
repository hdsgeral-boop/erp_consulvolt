<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DocumentoAnexo;
use App\Models\DocumentoProjeto;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela tipos_documento (módulo Sistema). Legado: document_types · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\TipoDocumento.
 */
abstract class TipoDocumentoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'tipos_documento';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'nome', 'contexto_modulo', 'descricao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function documentosAnexos(): HasMany
    {
        return $this->hasMany(DocumentoAnexo::class, 'tipo_documento_id');
    }

    public function documentosProjeto(): HasMany
    {
        return $this->hasMany(DocumentoProjeto::class, 'tipo_documento_id');
    }
}
