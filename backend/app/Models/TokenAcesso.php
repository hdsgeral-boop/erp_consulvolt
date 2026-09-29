<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token de acesso Sanctum com colunas em português (tabela tokens_acesso).
 *
 * O Sanctum lê e escreve atributos com nomes fixos em inglês (name, abilities, last_used_at,
 * expires_at, created_at, tokenable). Este model traduz esses nomes para as colunas reais,
 * de forma que o Sanctum funciona sem alterações e a base de dados fica 100% em português.
 */
class TokenAcesso extends PersonalAccessToken
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = 'atualizado_em';

    /** Atributo esperado pelo Sanctum => coluna em português. */
    private const ALIASES = [
        'name' => 'nome',
        'abilities' => 'permissoes',
        'last_used_at' => 'ultimo_uso_em',
        'expires_at' => 'expira_em',
        'created_at' => 'criado_em',
        'updated_at' => 'atualizado_em',
        'tokenable_type' => 'portador_tipo',
        'tokenable_id' => 'portador_id',
    ];

    protected $table = 'tokens_acesso';

    protected $fillable = [
        'nome', 'token', 'permissoes', 'expira_em', 'endereco_ip', 'agente_utilizador',
        // nomes usados por HasApiTokens::createToken()
        'name', 'abilities', 'expires_at',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'permissoes' => 'json',
        'ultimo_uso_em' => 'datetime',
        'expira_em' => 'datetime',
    ];

    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable', 'portador_tipo', 'portador_id');
    }

    public function getAttribute($key)
    {
        return parent::getAttribute(self::ALIASES[$key] ?? $key);
    }

    public function setAttribute($key, $value)
    {
        return parent::setAttribute(self::ALIASES[$key] ?? $key, $value);
    }

    public function isFillable($key)
    {
        return parent::isFillable($key) || parent::isFillable(self::ALIASES[$key] ?? $key);
    }
}
