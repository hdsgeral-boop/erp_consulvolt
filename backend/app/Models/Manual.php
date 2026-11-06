<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * manuais — definições do documento de cada manual por empresa e tipo (Onda 1: só PROCEDIMENTOS). Os textos livres e as
 * opções do documento ficam em jsonb, validados por App\Services\Procedimentos\ServicoManualProcedimentos.
 */
class Manual extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const TIPO_PROCEDIMENTOS = 'PROCEDIMENTOS';

    protected $table = 'manuais';

    protected string $moduloAuditoria = 'Manuais';

    protected $fillable = ['empresa_id', 'tipo', 'titulo', 'versao_rascunho', 'data_documento', 'elaborado_por', 'aprovado_por', 'aprovado_em', 'textos', 'opcoes', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'data_documento' => 'date:Y-m-d', 'aprovado_em' => 'date:Y-m-d', 'textos' => 'array', 'opcoes' => 'array'];
    }
}
