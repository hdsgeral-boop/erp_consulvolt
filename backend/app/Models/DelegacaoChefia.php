<?php

namespace App\Models;

use App\Models\Base\DelegacaoChefiaBase;

/**
 * delegacoes_chefia — delegação da chefia por período e âmbito (Fase K, ADR-079).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em DelegacaoChefiaBase.
 */
class DelegacaoChefia extends DelegacaoChefiaBase
{
    public const AMBITOS = ['PORTAL', 'METODO', 'ENTREGAVEIS'];

    public const ESTADOS = ['ATIVA', 'REVOGADA'];

    /** Dias máximos de uma delegação (inclusive). */
    public const MAX_DIAS = 90;
}
