<?php

namespace App\Models;

use App\Exceptions\ErroNegocio;
use App\Models\Concerns\Auditavel;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Autenticavel;
use Illuminate\Support\Facades\Auth;
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
            'conta_root' => 'boolean',
            'deve_alterar_password' => 'boolean',
            'modulos_permitidos' => 'array',
            'mfa_segredo' => 'encrypted',
            'mfa_ativo' => 'boolean',
            // já são hashes (Hash::make), não os códigos em texto simples: encriptação adicional é redundante.
            'mfa_codigos_recuperacao' => 'array',
            'mfa_ativado_em' => 'datetime',
        ];
    }

    /**
     * Interruptor ligado SÓ pelo comando `erp:root:definir` (ADR-084): sem ele, nada no processo web ou em código
     * aplicacional marca, desmarca, desactiva, despromove ou elimina uma conta root.
     */
    public static bool $autorizarAlteracaoRoot = false;

    protected static function booted(): void
    {
        static::saving(fn (Utilizador $u) => self::protegerContaRoot($u));
        static::deleting(fn (Utilizador $u) => self::protegerContaRoot($u, true));

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

    /** Esconde as contas root (ADR-084) de quem não é root: não se listam nem se pesquisam por outros utilizadores. */
    public function scopeVisivelPara($q, ?self $actor)
    {
        return $actor?->eRoot() ? $q : $q->where('utilizadores.conta_root', false);
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
    /**
     * Segredo do token de sessão: 256 bits do gerador criptográfico em base64url (43 caracteres), em vez dos 40
     * caracteres alfanuméricos (~238 bits) do Sanctum. Só o SHA-256 vai para a base.
     */
    public function generateTokenString()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

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

    /**
     * Conta ROOT global (ADR-084): a coluna `conta_root`, nunca o nome. Só conta enquanto a conta está activa e não
     * eliminada — uma conta root desactivada não pode entrar (e o login recusa-a antes de qualquer poder).
     */
    public function eRoot(): bool
    {
        $a = $this->attributes;

        return ($a['conta_root'] ?? false) && ($a['ativo'] ?? false) && ($a['eliminado_em'] ?? null) === null;
    }

    /**
     * Salvaguardas do modelo para a conta root (aplicam-se a QUALQUER caminho Eloquent, não só às rotas):
     *  - `conta_root` não muda fora do comando artisan (nem por mass assignment, nem por forceFill de código aplicacional);
     *  - ninguém, além do próprio titular, altera uma conta root (nem super-administradores de clientes ou da plataforma);
     *  - nem o titular a desactiva, despromove ou elimina (a revogação é só pelo comando, que protege a última root).
     */
    private static function protegerContaRoot(Utilizador $u, bool $eliminar = false): void
    {
        if (self::$autorizarAlteracaoRoot) {
            return;
        }
        $erro = fn (string $m) => new ErroNegocio($m, 'CONTA_ROOT_PROTEGIDA', 403);

        if (! $u->exists && ! empty($u->attributes['conta_root'])) {
            throw $erro('A conta root só se define pelo comando erp:root:definir.');
        }
        if ($u->exists && $u->isDirty('conta_root')) {
            throw $erro('A conta root só se define ou revoga pelo comando erp:root:definir.');
        }
        if (! $u->exists || ! ($u->original['conta_root'] ?? false)) {
            return;
        }
        if ($eliminar) {
            throw $erro('Uma conta root não se elimina; revogue-a primeiro pelo comando erp:root:definir.');
        }
        $actor = Auth::user();
        if ($actor instanceof self && (int) $actor->getKey() !== (int) $u->getKey()) {
            throw $erro('Uma conta root só pode ser alterada pelo seu titular.');
        }
        if ($u->isDirty('ativo') && ! $u->ativo || $u->isDirty('eliminado_em') && $u->eliminado_em !== null
            || $u->isDirty('papel') && $u->papel !== self::PAPEL_SUPER_ADMINISTRADOR) {
            throw $erro('Uma conta root não se desactiva, despromove nem elimina por esta via.');
        }
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
     * Pertence à plataforma SaaS (tenant da plataforma) com poder de gestão: super-administrador ou operador do back-office.
     * Usado para decidir quem altera o que é partilhado por todos os clientes (catálogo de moedas, câmbios «todas»).
     */
    public function ePlataforma(): bool
    {
        return $this->eRoot() || ($this->eSuperAdministrador() || $this->eOperadorPlataforma())
            && (int) $this->tenant_id === (int) config('erp.dominio.plataforma_tenant_id', 1);
    }

    /**
     * Pode usar o backoffice de suporte (ExigirSuporte): tenant da plataforma, super-administrador ou agente de suporte.
     */
    public function eSuportePlataforma(): bool
    {
        return $this->eRoot() || ($this->eSuperAdministrador() || $this->eAgenteSuporte())
            && (int) $this->tenant_id === (int) config('erp.dominio.plataforma_tenant_id', 1);
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
