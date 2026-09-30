<?php

namespace App\Models;

use App\Models\Base\ProjetoBase;

/**
 * projetos — /api/projetos.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ProjetoBase (gerado).
 */
class Projeto extends ProjetoBase
{
    /** Estados do legado (ui_projects.js:272-277). EM_CURSO é sinónimo antigo de ACTIVO (ui_projects.js:403). */
    public const ESTADOS = ['PREPARACAO', 'ACTIVO', 'ENCERRADO', 'CANCELADO'];

    /** Estados que não aceitam imputações (ProjectAPI.postLedgerEntry, js/nucleo_partilhado.js:20). */
    public const FECHADOS = ['ENCERRADO', 'CANCELADO'];

    public const TIPOS = ['INTERNO', 'EXTERNO'];

    public function ativo(): bool
    {
        return in_array($this->estado, ['ACTIVO', 'EM_CURSO'], true);
    }

    public function externo(): bool
    {
        return $this->tipo === 'EXTERNO';
    }
}
