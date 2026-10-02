<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Services\Sistema\ServicoEmpresas;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Autenticavel;
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

    protected $table = 'utilizadores';

    protected string $moduloAuditoria = 'Sistema/Utilizadores';

    /** Coluna da palavra-passe usada pelo guard/provider do Laravel. */
    protected $authPasswordName = 'palavra_passe';

    /** A API é por token: sem "remember me". */
    protected $rememberTokenName = '';

    protected $fillable = [
        'nome_utilizador', 'nome_completo', 'email', 'palavra_passe', 'papel', 'perfil_utilizador_id',
        'acesso_todas_empresas', 'ativo',
    ];

    protected $hidden = ['palavra_passe', 'hash_password_legado', 'salt_password_legado', 'algoritmo_password_legado'];

    protected function casts(): array
    {
        return [
            'palavra_passe' => 'hashed',
            'palavra_passe_alterada_em' => 'datetime',
            'ultimo_acesso_em' => 'datetime',
            'acesso_todas_empresas' => 'boolean',
            'ativo' => 'boolean',
            'modulos_permitidos' => 'array',
        ];
    }

    protected static function booted(): void
    {
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

    public function temCredencialLegada(): bool
    {
        return $this->palavra_passe === null && $this->hash_password_legado !== null;
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format(DateTimeInterface::ATOM);
    }
}
