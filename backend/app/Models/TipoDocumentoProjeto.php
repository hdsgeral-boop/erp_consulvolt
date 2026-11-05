<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** tipos_documento_projeto — catálogo (por empresa) dos tipos de documento do projecto: contrato, proposta, relatório… */
class TipoDocumentoProjeto extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'tipos_documento_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = ['empresa_id', 'nome', 'ordem', 'ativo'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'ordem' => 'integer', 'ativo' => 'boolean', 'criado_em' => 'datetime', 'atualizado_em' => 'datetime'];
    }
}
