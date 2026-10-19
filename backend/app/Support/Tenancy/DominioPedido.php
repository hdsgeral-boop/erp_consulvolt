<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Models\Utilizador;
use Illuminate\Http\Request;

/**
 * Área a que o pedido pertence, segundo o domínio pelo qual foi feito (SaaS: um endereço por cliente).
 *
 *  - `livre`        — host que não é do domínio base (ex.: localhost, IP). Sem restrição por domínio (desenvolvimento).
 *  - `site`         — o próprio domínio base (ex.: meuerp.online). Sem restrição por cliente.
 *  - `plataforma`   — admin.erp.<base>: só o tenant que opera a plataforma, com super-administrador.
 *  - `cliente`      — <slug>.<base>: só utilizadores do tenant com esse slug.
 *  - `desconhecida` — subdomínio que não corresponde a nenhuma das regras acima (ex.: x.y.<base>).
 *
 * Quem decide se um utilizador pode usar a área é `permite()`. O tenant nunca vem de um cabeçalho: o host é o
 * endereço que o cliente escolheu para entrar, e o utilizador tem de pertencer a esse cliente.
 */
final class DominioPedido
{
    public const LIVRE = 'livre';

    public const SITE = 'site';

    public const PLATAFORMA = 'plataforma';

    public const CLIENTE = 'cliente';

    public const DESCONHECIDA = 'desconhecida';

    private const PREFIXO_PLATAFORMA = 'admin.erp';

    private function __construct(
        public readonly string $area,
        public readonly ?string $slug = null,
    ) {}

    public static function de(Request $pedido): self
    {
        $base = strtolower(trim((string) config('erp.dominio.base')));
        $host = strtolower($pedido->getHost());

        if ($base === '' || (! str_ends_with($host, '.'.$base) && $host !== $base)) {
            return new self(self::LIVRE);
        }
        if ($host === $base || $host === 'www.'.$base) {
            return new self(self::SITE);
        }

        $prefixo = substr($host, 0, -strlen('.'.$base));

        if ($prefixo === self::PREFIXO_PLATAFORMA) {
            return new self(self::PLATAFORMA);
        }
        if ($prefixo === '' || str_contains($prefixo, '.') || in_array($prefixo, (array) config('erp.dominio.slugs_reservados'), true)) {
            return new self(self::DESCONHECIDA);
        }

        return new self(self::CLIENTE, $prefixo);
    }

    /** Cliente indicado pelo endereço (null se o pedido não for de um cliente ou se o cliente não existir). */
    public function cliente(): ?Tenant
    {
        if ($this->area !== self::CLIENTE) {
            return null;
        }

        return Tenant::query()->where('slug', $this->slug)->first();
    }

    /** Verdadeiro quando o utilizador pode entrar/usar esta área. */
    public function permite(Utilizador $utilizador): bool
    {
        return match ($this->area) {
            self::LIVRE, self::SITE => true,
            self::PLATAFORMA => (int) $utilizador->tenant_id === (int) config('erp.dominio.plataforma_tenant_id')
                && $utilizador->eSuperAdministrador(),
            self::CLIENTE => ($cliente = $this->cliente()) !== null && (int) $utilizador->tenant_id === (int) $cliente->id,
            default => false,
        };
    }
}
