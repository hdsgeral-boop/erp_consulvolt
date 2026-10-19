<?php

namespace App\Models;

/**
 * Tenant: conta do cliente SaaS. Agrupa uma ou mais empresas e os seus utilizadores.
 * A tabela `tenants` não tem RLS: é lida antes de existir contexto (ver ContextoTenant::paraCadaTenant).
 */
class Tenant extends ModeloBase
{
    public const ESTADO_ATIVO = 'ATIVO';

    public const ESTADO_SUSPENSO = 'SUSPENSO';

    /** Arquivado: bloqueado como suspenso, mas os dados ficam guardados (não é apagado). */
    public const ESTADO_ARQUIVADO = 'ARQUIVADO';

    protected $table = 'tenants';

    protected $fillable = ['nome', 'slug', 'estado', 'plano', 'subscricao_inicio', 'subscricao_fim', 'nome_sistema'];

    protected function casts(): array
    {
        return ['subscricao_inicio' => 'date', 'subscricao_fim' => 'date'];
    }

    public function estaAtivo(): bool
    {
        return $this->estado === self::ESTADO_ATIVO;
    }
}
