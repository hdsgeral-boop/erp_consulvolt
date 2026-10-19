<?php

namespace App\Support\Tenancy;

use App\Exceptions\ErroContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Tenant (conta do cliente SaaS) da sessão de base de dados. Portado da v5 (ADR-073).
 *
 * O tenant é gravado em `app.tenant_id` na ligação PostgreSQL (session-level). A política RLS (fase 2) usa esse
 * valor para esconder as linhas de outros tenants. Com `erp.saas.ativo=false` o contexto é só memória: não toca
 * na base de dados e o sistema comporta-se como antes do SaaS.
 *
 * Ao contrário de ContextoEmpresa::semIsolamento(), nada aqui desliga a RLS.
 */
final class ContextoTenant
{
    public const TENANT_INICIAL = 1;

    private ?int $tenantId = null;

    public static function ativo(): bool
    {
        return (bool) config('erp.saas.ativo', false);
    }

    public function definir(?int $tenantId): void
    {
        if (self::ativo()) {
            DB::selectOne("SELECT set_config('app.tenant_id', ?, false)", [$tenantId === null ? '' : (string) $tenantId]);
        }
        $this->tenantId = $tenantId;
    }

    public function limpar(): void
    {
        $this->definir(null);
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function definido(): bool
    {
        return $this->tenantId !== null;
    }

    /** Tenant activo ou erro — para criar dados que pertencem a um tenant. */
    public function obrigatorio(): int
    {
        return $this->tenantId ?? throw ErroContextoEmpresa::tenantNaoDefinido();
    }

    /**
     * Tenant de um registo novo (empresa, utilizador). Com SaaS activo exige contexto; com SaaS desligado usa o do
     * contexto ou o tenant inicial (CONSULVOLT), para o sistema actual criar dados como sempre.
     */
    public function paraNovo(): int
    {
        return self::ativo() ? $this->obrigatorio() : ($this->tenantId ?? self::TENANT_INICIAL);
    }

    /**
     * Executa $operacao com o tenant $tenantId, repondo o anterior no fim.
     *
     * @template T
     *
     * @param  callable(): T  $operacao
     * @return T
     */
    public function executarComo(int $tenantId, callable $operacao): mixed
    {
        $anterior = $this->tenantId;
        $this->definir($tenantId);
        try {
            return $operacao();
        } finally {
            $this->definir($anterior);
        }
    }

    /**
     * Como paraCadaTenant, mas com SaaS desligado executa uma vez, sem tenant (comportamento anterior). Usar em rotinas
     * agendadas e comandos que percorrem dados de negocio sem utilizador.
     *
     * @param  callable(?int): void  $operacao
     */
    public function porTenant(callable $operacao): void
    {
        if (! self::ativo()) {
            $operacao(null);

            return;
        }

        $this->paraCadaTenant($operacao);
    }

    /**
     * Executa $operacao uma vez por cada tenant activo (rotinas agendadas e comandos). A tabela `tenants` não tem RLS.
     *
     * @param  callable(int): void  $operacao
     */
    public function paraCadaTenant(callable $operacao): void
    {
        $ids = DB::table('tenants')->where('estado', 'ATIVO')->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $this->executarComo((int) $id, fn () => $operacao((int) $id));
        }
    }
}
