<?php

namespace App\Models;

use App\Models\Base\TerceiroBase;

/**
 * terceiros — clientes, fornecedores e colaboradores (entidades com conta corrente). /api/terceiros.
 * Um terceiro pode ser cliente e fornecedor ao mesmo tempo (tipo CLIENTE_FORNECEDOR; legado "FORNECEDOR, CLIENTE").
 */
class Terceiro extends TerceiroBase
{
    public const CLIENTE = 'CLIENTE';

    public const FORNECEDOR = 'FORNECEDOR';

    public const COLABORADOR = 'COLABORADOR';

    public const CLIENTE_FORNECEDOR = 'CLIENTE_FORNECEDOR';

    /** Tipos que contam como cliente / fornecedor nas listagens. */
    public const TIPOS_CLIENTE = [self::CLIENTE, self::CLIENTE_FORNECEDOR];

    public const TIPOS_FORNECEDOR = [self::FORNECEDOR, self::CLIENTE_FORNECEDOR];

    public function eCliente(): bool
    {
        return in_array($this->tipo, self::TIPOS_CLIENTE, true);
    }

    public function eFornecedor(): bool
    {
        return in_array($this->tipo, self::TIPOS_FORNECEDOR, true);
    }
}
