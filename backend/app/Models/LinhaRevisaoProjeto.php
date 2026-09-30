<?php

namespace App\Models;

use App\Models\Base\LinhaRevisaoProjetoBase;

/**
 * linhas_revisao_projeto — /api/projetos/{projeto}/revisoes.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em LinhaRevisaoProjetoBase (gerado).
 *
 * `documento_gerado_id`: numa SUBEMPREITADA é o id da factura de fornecedor gerada (faturas_compra); numa MAO_OBRA é o
 * id do movimento do razão analítico (razao_analitico_projetos), como no legado (ui_projects.js:2737, 2752).
 */
class LinhaRevisaoProjeto extends LinhaRevisaoProjetoBase
{
    public const SUBEMPREITADA = 'SUBEMPREITADA';

    public const MAO_OBRA = 'MAO_OBRA';
}
