<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Autenticavel;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

/**
 * Utilizador do sistema. Legado: users.
 */
class Utilizador extends Autenticavel
{
    use Auditavel;
    use HasApiTokens;
    use SoftDeletes;

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = 'atualizado_em';

    public const DELETED_AT = 'eliminado_em';

    public const PAPEL_SUPER_ADMINISTRADOR = 'SUPER_ADMINISTRADOR';

    public const PAPEL_ADMINISTRADOR = 'ADMINISTRADOR';

    public const PAPEL_UTILIZADOR = 'UTILIZADOR';

    /** Agente do backoffice de suporte (ADR-076, fase 4 fatia 3): tenant da plataforma, só tickets (ExigirSuporte). */
    public const PAPEL_AGENTE_SUPORTE = 'AGENTE_SUPORTE';

    /**
     * Operador do back-office da plataforma (Fase 4 da extensão SaaS, secção 12): tenant da plataforma, sem ser
     * super-administrador — entra na área da plataforma (ExigirPlataforma), mas cada operação sensível exige a
     * chave `plataforma_*_gerir` correspondente no seu perfil (ServicoPermissoes::elegivelPlataforma).
     */
    public const PAPEL_OPERADOR_PLATAFORMA = 'OPERADOR_PLATAFORMA';

    protected $table = 'utilizadores';

    protected string $moduloAuditoria = 'Sistema/Utilizadores';

    /** Coluna da palavra-passe usada pelo guard/provider do Laravel. */
    protected $authPasswordName = 'palavra_passe';

    /** A API é por token: sem "remember me". */
    protected $rememberTokenName = '';

    protected $fillable = [
        'nome_utilizador', 'nome_completo', 'email', 'telefone', 'palavra_passe', 'papel', 'perfil_utilizador_id',
        'acesso_todas_empresas', 'ativo', 'deve_alterar_password', 'google_id',
        'mfa_segredo', 'mfa_ativo', 'mfa_codigos_recuperacao', 'mfa_ativado_em',
    ];

    protected $hidden = [
        'palavra_passe', 'hash_password_legado', 'salt_password_legado', 'algoritmo_password_legado',
        'mfa_segredo', 'mfa_codigos_recuperacao',
    ];

    protected function casts(): array
    {
        return [
            'palavra_passe' => 'hashed',
            'palavra_passe_alterada_em' => 'datetime',
            'ultimo_acesso_em' => 'datetime',
            'acesso_todas_empresas' => 'boolean',
            'ativo' => 'boolean',
            'deve_alterar_password' => 'boolean',
            'modulos_permitidos' => 'array',
            'mfa_segredo' => 'encrypted',
            'mfa_ativo' => 'boolean',
            // já são hashes (Hash::make), não os códigos em texto simples: encriptação adicional é redundante.
            'mfa_codigos_recuperacao' => 'array',
            'mfa_ativado_em' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // SaaS (ADR-073): um utilizador novo pertence ao tenant do pedido/trabalho que o cria.
        static::creating(function (Utilizador $u) {
            if (Schema::hasColumn('utilizadores', 'tenant_id')) {
                $u->tenant_id ??= app(ContextoTenant::class)->paraNovo();
            }
        });

        static::saved(function (Utilizador $u) {
            if ($u->wasChanged(['papel', 'acesso_todas_empresas', 'ativo'])) {
                app(ServicoEmpresas::class)->invalidarUtilizador((int) $u->getKey());
            }
        });
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilUtilizador::class, 'perfil_utilizador_id');
    }

    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'utilizador_empresa', 'utilizador_id', 'empresa_id')
            ->using(UtilizadorEmpresa::class)->withPivot('colaborador_id');
    }

    /** Tokens Sanctum na tabela tokens_acesso (colunas portador_tipo/portador_id). */
    public function tokens(): MorphMany
    {
        return $this->morphMany(TokenAcesso::class, 'portador', 'portador_tipo', 'portador_id');
    }

    /** Nome para documentos e sessões (operador POS, caixa): o nome completo, ou o nome de utilizador (legado: pos_gestao.js:20). */
    public function nomeApresentacao(): string
    {
        return trim((string) $this->nome_completo) !== '' ? trim((string) $this->nome_completo) : (string) $this->nome_utilizador;
    }

    public function eSuperAdministrador(): bool
    {
        return $this->papel === self::PAPEL_SUPER_ADMINISTRADOR;
    }

    public function eAgenteSuporte(): bool
    {
        return $this->papel === self::PAPEL_AGENTE_SUPORTE;
    }

    public function eOperadorPlataforma(): bool
    {
        return $this->papel === self::PAPEL_OPERADOR_PLATAFORMA;
    }

    /**
     * Conta privilegiada (Fase 4 da extensão SaaS, secção 12, ponto 222: "MFA obrigatório para contas
     * privilegiadas") — qualquer papel com poder administrativo acima de um utilizador comum de empresa.
     */
    public function ePrivilegiado(): bool
    {
        return in_array($this->papel, [self::PAPEL_SUPER_ADMINISTRADOR, self::PAPEL_OPERADOR_PLATAFORMA, self::PAPEL_AGENTE_SUPORTE], true);
    }

    public function temCredencialLegada(): bool
    {
        return $this->palavra_passe === null && $this->hash_password_legado !== null;
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format(DateTimeInterface::ATOM);
    }
}
