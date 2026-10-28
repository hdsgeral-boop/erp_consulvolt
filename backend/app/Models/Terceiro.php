<?php

namespace App\Models;

use App\Models\Base\TerceiroBase;
use App\Services\Terceiros\ContaTerceiro;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * terceiros — clientes, fornecedores e colaboradores (entidades com conta corrente). /api/terceiros.
 * Um terceiro pode ser cliente e fornecedor ao mesmo tempo (tipo CLIENTE_FORNECEDOR; legado "FORNECEDOR, CLIENTE").
 * Cada papel tem a sua conta (Fase A, Versão Actual — js/shared/contas_terceiro.js): conta_cliente (classe 31) e
 * conta_fornecedor (classe 32 ou 37.0); o codigo_conta antigo só serve de recurso quando a classe corresponde.
 */
class Terceiro extends TerceiroBase
{
    public const CLIENTE = 'CLIENTE';

    public const FORNECEDOR = 'FORNECEDOR';

    public const COLABORADOR = 'COLABORADOR';

    public const CLIENTE_FORNECEDOR = 'CLIENTE_FORNECEDOR';

    /** Papel autónomo (não combinável com cliente/fornecedor/colaborador): conta corrente genérica, classe 37. */
    public const CREDOR_DEVEDOR = 'CREDOR_DEVEDOR';

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

    public function eCredorDevedor(): bool
    {
        return $this->tipo === self::CREDOR_DEVEDOR;
    }

    /** Conta de cliente (classe 31, 37.91 ou 37.2): a própria ou o codigo_conta antigo dessas classes (ContaTerceiro.cliente do legado). */
    public function contaCliente(): string
    {
        return ContaTerceiro::cliente($this);
    }

    /** Conta de fornecedor (classe 32, 37.0, 37.1 ou 37.92): a própria ou o codigo_conta antigo dessas classes. */
    public function contaFornecedor(): string
    {
        return ContaTerceiro::fornecedor($this);
    }

    /** Conta de credor/devedor (classe 37): a própria — papel novo, sem codigo_conta antigo. */
    public function contaCredorDevedor(): string
    {
        return ContaTerceiro::credorDevedor($this);
    }

    /** Conta de cliente ou pára a integração com a mensagem do que falta (sem contas por defeito). */
    public function exigirContaCliente(string $contexto = ''): string
    {
        return ContaTerceiro::exigir($this, ContaTerceiro::CLIENTE, $contexto);
    }

    /** Conta de fornecedor ou pára a integração com a mensagem do que falta (sem contas por defeito). */
    public function exigirContaFornecedor(string $contexto = ''): string
    {
        return ContaTerceiro::exigir($this, ContaTerceiro::FORNECEDOR, $contexto);
    }

    /** Conta de credor/devedor ou pára a integração com a mensagem do que falta (sem contas por defeito). */
    public function exigirContaCredorDevedor(string $contexto = ''): string
    {
        return ContaTerceiro::exigir($this, ContaTerceiro::CREDOR_DEVEDOR, $contexto);
    }

    public function contasBancarias(): HasMany
    {
        return $this->hasMany(ContaBancariaTerceiro::class, 'terceiro_id');
    }
}
