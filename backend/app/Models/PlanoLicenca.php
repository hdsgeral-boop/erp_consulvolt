<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** planos_licenca — catálogo de planos da plataforma (ADR-074). Global (sem empresa_id). */
class PlanoLicenca extends ModeloBase
{
    protected $table = 'planos_licenca';

    protected $fillable = ['codigo', 'nome', 'descricao', 'preco_mensal', 'moeda', 'max_utilizadores', 'max_empresas', 'ativo', 'ordem', 'dias_teste'];

    protected function casts(): array
    {
        return ['preco_mensal' => 'decimal:2', 'ativo' => 'boolean', 'max_utilizadores' => 'integer', 'max_empresas' => 'integer', 'ordem' => 'integer', 'dias_teste' => 'integer'];
    }

    public function versoes(): HasMany
    {
        return $this->hasMany(PlanoLicencaVersao::class, 'plano_id');
    }

    /** Dias de período experimental do plano do tenant, ou null se o tenant não tiver plano ou o plano não tiver um valor próprio (usa-se o global). */
    public static function diasTestePara(int $tenantId): ?int
    {
        $codigo = Tenant::query()->whereKey($tenantId)->value('plano');

        return $codigo ? self::query()->where('codigo', $codigo)->value('dias_teste') : null;
    }
}
