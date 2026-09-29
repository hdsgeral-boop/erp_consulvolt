<?php

namespace App\Support\Tenancy;

use App\Exceptions\ErroContextoEmpresa;

/**
 * Empresa activa no pedido/trabalho corrente (registado como `scoped` no contentor: um por pedido HTTP,
 * por trabalho de fila e por comando). Todo o isolamento multi-empresa parte daqui.
 *
 * Política "fail-closed": consultas a models com PertenceEmpresa sem empresa definida lançam erro,
 * a menos que o código peça explicitamente `semIsolamento()` (ETL, relatórios globais de administração).
 */
final class ContextoEmpresa
{
    private ?int $empresaId = null;

    private int $semIsolamento = 0;

    public function definir(int $empresaId): void
    {
        $this->empresaId = $empresaId;
    }

    public function limpar(): void
    {
        $this->empresaId = null;
    }

    public function id(): ?int
    {
        return $this->empresaId;
    }

    public function definida(): bool
    {
        return $this->empresaId !== null;
    }

    /** Empresa activa ou erro — para escrita de dados de uma empresa. */
    public function obrigatorio(): int
    {
        return $this->empresaId ?? throw ErroContextoEmpresa::naoDefinido();
    }

    public function isolamentoDesligado(): bool
    {
        return $this->semIsolamento > 0;
    }

    /**
     * Executa $operacao com a empresa $empresaId activa, repondo o contexto anterior no fim.
     *
     * @template T
     *
     * @param  callable(): T  $operacao
     * @return T
     */
    public function executarComo(int $empresaId, callable $operacao): mixed
    {
        $anterior = $this->empresaId;
        $this->empresaId = $empresaId;
        try {
            return $operacao();
        } finally {
            $this->empresaId = $anterior;
        }
    }

    /**
     * Executa $operacao sem filtro de empresa. Uso restrito: ETL, manutenção e consolidação.
     *
     * @template T
     *
     * @param  callable(): T  $operacao
     * @return T
     */
    public function semIsolamento(callable $operacao): mixed
    {
        $this->semIsolamento++;
        try {
            return $operacao();
        } finally {
            $this->semIsolamento--;
        }
    }
}
