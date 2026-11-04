<?php

namespace App\Models;

use App\Services\Autenticacao\ServicoSessoes;
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
        'dispositivo_id', 'rotulo', 'agente_assinatura', 'tokens_anteriores', 'rotacao_em', 'empresa_ativa_id',
        // nomes usados por HasApiTokens::createToken()
        'name', 'abilities', 'expires_at',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'permissoes' => 'json',
        'ultimo_uso_em' => 'datetime',
        'expira_em' => 'datetime',
        'rotacao_em' => 'datetime',
        'tokens_anteriores' => 'array',
    ];

    /** true quando o pedido actual apresentou um token já rodado, ainda dentro da tolerância (pedido paralelo em voo). */
    public bool $viaTokenAnterior = false;

    /**
     * Procura a sessão pelo token `id|segredo` (ADR-082). Só o hash SHA-256 está na base; compara em tempo constante.
     * Para além do token actual, reconhece os já rodados: dentro da tolerância (pedidos paralelos do mesmo navegador)
     * serve a sessão; fora dela é reutilização — alguém guardou um token substituído — e termina a sessão do dispositivo.
     *
     * @param  string  $token
     */
    public static function findToken($token)
    {
        if (! is_string($token) || ! str_contains($token, '|')) {
            return null;   // formato sem id (aceite pelo Sanctum por omissão) não existe aqui
        }
        [$id, $segredo] = explode('|', $token, 2);
        if (! ctype_digit($id) || $segredo === '' || strlen($segredo) > 200) {
            return null;
        }
        $instancia = static::query()->find((int) $id);
        if (! $instancia) {
            return null;
        }

        $hash = hash('sha256', $segredo);
        if (hash_equals((string) $instancia->token, $hash)) {
            return $instancia;
        }

        $achado = null;
        foreach ($instancia->tokens_anteriores ?? [] as $anterior) {
            if (hash_equals((string) ($anterior['h'] ?? ''), $hash)) {
                $achado = $anterior;
            }
        }
        if ($achado === null) {
            return null;
        }

        if (now()->getTimestamp() - (int) ($achado['r'] ?? 0) <= (int) config('erp.sessao.rotacao_tolerancia_segundos')) {
            $instancia->viaTokenAnterior = true;

            return $instancia;
        }

        app(ServicoSessoes::class)->reutilizacaoDetectada($instancia);

        return null;
    }

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
