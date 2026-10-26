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
 *  - `portal`       — portal.<base>: portal autónomo do colaborador, endereço único partilhado por todos os
 *    clientes (sem segredo de subdomínio por cliente). Qualquer utilizador autenticado de qualquer tenant pode
 *    entrar; quem realmente pode usar o portal é decidido pelo servidor em `GET /portal/acesso`
 *    (`ServicoPortalColaborador::exigirAcessoPortal`), não aqui. O que esta área restringe é, em vez disso, QUAIS
 *    rotas ficam alcançáveis a partir deste endereço (ver `ExigirAreaPortal`): isolamento ao nível da rota, não do
 *    utilizador.
 *  - `cliente`      — <slug>.<base>: só utilizadores do tenant com esse slug.
 *  - `desconhecida` — subdomínio que não corresponde a nenhuma das regras acima (ex.: x.y.<base>).
 *
 * Quem decide se um utilizador pode usar a área é `permite()`. O tenant nunca vem de um cabeçalho: o host é o
 * endereço que o cliente escolheu para entrar, e o utilizador tem de pertencer a esse cliente (excepto no portal,
 * que é propositadamente de todos).
 */
final class DominioPedido
{
    public const LIVRE = 'livre';

    public const SITE = 'site';

    public const PLATAFORMA = 'plataforma';

    public const PORTAL = 'portal';

    public const CLIENTE = 'cliente';

    public const DESCONHECIDA = 'desconhecida';

    private const PREFIXO_PLATAFORMA = 'admin.erp';

    private const PREFIXO_PORTAL = 'portal';

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
        if ($prefixo === self::PREFIXO_PORTAL) {
            return new self(self::PORTAL);
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
            // Super-administrador OU agente de suporte (ADR-076): o backoffice de suporte vive na mesma área de
            // domínio que a plataforma, isolado por rota (ExigirSuporte), não por subdomínio.
            self::PLATAFORMA => (int) $utilizador->tenant_id === (int) config('erp.dominio.plataforma_tenant_id')
                && ($utilizador->eSuperAdministrador() || $utilizador->eAgenteSuporte()),
            // Portal do colaborador: endereço partilhado por todos os clientes — qualquer utilizador autenticado pode
            // entrar; quem pode usar o portal de facto é decidido no servidor por GET /portal/acesso.
            self::PORTAL => true,
            self::CLIENTE => ($cliente = $this->cliente()) !== null && (int) $utilizador->tenant_id === (int) $cliente->id,
            default => false,
        };
    }
}
