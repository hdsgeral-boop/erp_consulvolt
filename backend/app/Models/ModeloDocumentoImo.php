<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** modelos_documento_imo — modelos de documentos do módulo imobiliário (contratos, recibos, cartas); `sistema` marca os de fábrica. */
class ModeloDocumentoImo extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'modelos_documento_imo';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'tipo', 'nome', 'assunto', 'corpo', 'activo', 'sistema', 'versao', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'activo' => 'boolean', 'sistema' => 'boolean', 'versao' => 'integer'];
    }
}
