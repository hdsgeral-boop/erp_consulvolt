<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de permissões. Legado: user_profiles (formatos v2 e antigo — ver ServicoPermissoes).
 */
class PerfilUtilizador extends ModeloBase
{
    use Auditavel;

    protected $table = 'perfis_utilizador';

    protected string $moduloAuditoria = 'Sistema/Perfis';

    protected $fillable = ['nome', 'descricao', 'permissoes'];

    protected function casts(): array
    {
        return ['permissoes' => 'array'];
    }

    public function utilizadores(): HasMany
    {
        return $this->hasMany(Utilizador::class, 'perfil_utilizador_id');
    }

    /**
     * SaaS: cada cliente tem os seus perfis (tenant_id). Com o tenant de um cliente activo só se vêem os desse cliente;
     * sem tenant (login, comandos) e no tenant da plataforma não há filtro. Um perfil novo fica no tenant do pedido.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            $tenant = app(ContextoTenant::class)->id();
            if ($tenant !== null && $tenant !== (int) config('erp.dominio.plataforma_tenant_id', 1) && self::temTenant()) {
                $q->where($q->qualifyColumn('tenant_id'), $tenant);
            }
        });
        static::creating(function (PerfilUtilizador $p) {
            if (self::temTenant()) {
                $p->tenant_id ??= app(ContextoTenant::class)->paraNovo();
            }
        });
    }

    private static ?bool $temTenant = null;

    /** A coluna tenant_id existe (migração 2026_10_20_130000); lido uma vez por processo. */
    private static function temTenant(): bool
    {
        return self::$temTenant ??= Schema::hasColumn('perfis_utilizador', 'tenant_id');
    }

    /** Copia para um cliente novo os perfis-modelo (os do tenant da plataforma, ou do tenant inicial); devolve quantos. */
    public static function copiarModelosPara(int $tenantDestino): int
    {
        $origem = (int) config('erp.dominio.plataforma_tenant_id', 1);
        $modelos = DB::table('perfis_utilizador')->where('tenant_id', $origem)->orderBy('id')->get();
        if ($modelos->isEmpty()) {
            $modelos = DB::table('perfis_utilizador')->where('tenant_id', ContextoTenant::TENANT_INICIAL)->orderBy('id')->get();
        }
        foreach ($modelos as $p) {
            DB::table('perfis_utilizador')->insert(['nome' => $p->nome, 'descricao' => $p->descricao, 'permissoes' => $p->permissoes,
                'permissoes_originais' => $p->permissoes_originais, 'tenant_id' => $tenantDestino, 'criado_em' => now(), 'atualizado_em' => now()]);
        }

        return $modelos->count();
    }
}
