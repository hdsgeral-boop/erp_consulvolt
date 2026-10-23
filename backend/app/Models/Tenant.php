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

    /** Licença do cliente (revê o ADR-074): sem limite (omissão), teste ou paga por plano e tempo. */
    public const LICENCA_SEM_LIMITE = 'SEM_LIMITE';

    public const LICENCA_TESTE = 'TESTE';

    public const LICENCA_PLANO = 'PLANO';

    protected $fillable = ['nome', 'slug', 'estado', 'plano', 'subscricao_inicio', 'subscricao_fim', 'nome_sistema', 'licenca_origem', 'licenca_inicio', 'licenca_fim'];

    protected function casts(): array
    {
        return ['subscricao_inicio' => 'date', 'subscricao_fim' => 'date', 'licenca_inicio' => 'date', 'licenca_fim' => 'date'];
    }

    protected static function booted(): void
    {
        // Um cliente novo começa com o período de teste (com as licenças activas), excepto os do plano interno, sem limites.
        static::creating(function (Tenant $t) {
            if (config('erp.licencas.ativo') && $t->licenca_fim === null && ($t->licenca_origem ?? self::LICENCA_SEM_LIMITE) === self::LICENCA_SEM_LIMITE
                && $t->plano !== Empresa::PLANO_INTERNO) {
                $t->licenca_origem = self::LICENCA_TESTE;
                $t->licenca_inicio = now()->toDateString();
                $t->licenca_fim = now()->addDays((int) config('erp.licencas.dias_teste', 7))->toDateString();
            }
        });
    }

    /** Licença expirada: tem data de fim e já passou (o último dia é inclusivo). Sem data ou sem limite = não expira. */
    public function licencaExpirada(): bool
    {
        return ($this->licenca_origem ?? self::LICENCA_SEM_LIMITE) !== self::LICENCA_SEM_LIMITE
            && $this->licenca_fim !== null && $this->licenca_fim->lt(now()->startOfDay());
    }

    public function estaAtivo(): bool
    {
        return $this->estado === self::ESTADO_ATIVO;
    }
}
