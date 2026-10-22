<?php

namespace App\Support\Tenancy;

use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use App\Support\Cache\InvalidacaoCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Módulos incluídos no plano em vigor de cada cliente (planos_licenca_versoes.modulos da versão PUBLICADA mais recente),
 * em cache global (da v6, adaptado). Usado por ServicoPermissoes para capar os módulos de negócio ao que o plano do
 * cliente inclui — mesmo para um perfil de acesso total: o plano é uma regra de facturação, como ServicoLimitesPlano.
 *
 * Só se restringem os módulos que fazem parte do catálogo dos planos (erp.planos_catalogo.modulos): os restantes do
 * catálogo de permissões (Início, Configurações, Método, Acréscimos, Estrutura…) ficam sempre disponíveis. Cliente sem
 * plano (ex.: o operador da plataforma) ou plano sem versão publicada = sem restrição.
 *
 * Dois mapas em cache (código → módulos; tenant → código), para o Gate/menu nunca consultarem `tenants`/`planos_*` por
 * pedido. invalidar(): depois de publicar uma versão ou de atribuir/mudar o plano de um cliente.
 */
final class ModulosPlano
{
    private const CACHE_CHAVE = 'plataforma:planos:modulos_em_vigor';

    private const CACHE_CHAVE_TENANTS = 'plataforma:planos:tenant_para_codigo';

    /** @return ?list<string> módulos do plano em vigor do cliente; null = sem restrição */
    public static function doTenantId(?int $tenantId): ?array
    {
        if ($tenantId === null) {
            return null;
        }
        $codigo = self::tenantParaCodigo()[$tenantId] ?? null;

        return $codigo === null ? null : (self::todas()[$codigo] ?? null);
    }

    /** O módulo está disponível com estes módulos do plano (null = sem restrição)? */
    public static function permitido(?array $modulosDoPlano, string $modulo): bool
    {
        return $modulosDoPlano === null
            || ! array_key_exists($modulo, (array) config('erp.planos_catalogo.modulos'))
            || in_array($modulo, $modulosDoPlano, true);
    }

    public static function invalidar(): void
    {
        InvalidacaoCache::esquecer(self::CACHE_CHAVE);
        InvalidacaoCache::esquecer(self::CACHE_CHAVE_TENANTS);
    }

    /** @return array<string, list<string>> código do plano => módulos da versão em vigor */
    private static function todas(): array
    {
        return rescue(fn () => Cache::remember(self::CACHE_CHAVE, 3600, function () {
            $mapa = [];
            foreach (PlanoLicenca::query()->get(['id', 'codigo']) as $plano) {
                $versao = PlanoLicencaVersao::query()->where('plano_id', $plano->id)->where('estado', PlanoLicencaVersao::PUBLICADA)
                    ->orderByDesc('numero')->first();
                if ($versao !== null) {
                    $mapa[$plano->codigo] = array_values((array) ($versao->modulos ?? []));
                }
            }

            return $mapa;
        }), [], report: false) ?? [];
    }

    /** @return array<int, string> tenant_id => código do plano (só clientes com plano) */
    private static function tenantParaCodigo(): array
    {
        return rescue(fn () => Cache::remember(self::CACHE_CHAVE_TENANTS, 3600,
            fn () => DB::table('tenants')->whereNotNull('plano')->pluck('plano', 'id')->all()), [], report: false) ?? [];
    }
}
