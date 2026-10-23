<?php

namespace App\Support\Tenancy;

use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use App\Support\Cache\InvalidacaoCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Módulos incluídos no plano em vigor de cada plano comercial (planos_licenca_versoes.modulos da versão PUBLICADA
 * mais recente), em cache global — toda a plataforma partilha a mesma meia-dúzia de planos (CACHE.md §4.1).
 *
 * Usado por ServicoPermissoes para capar o acesso aos módulos de negócio ao que o plano do cliente inclui — mesmo
 * para um perfil de acesso total («all»): os limites do plano são uma regra de negócio/facturação e aplicam-se
 * independentemente do perfil do utilizador, tal como ServicoLimitesPlano já faz aos limites de empresas/utilizadores.
 * Módulos 'geral' (Início) e 'config' (definições da própria empresa) ficam sempre disponíveis: nunca fazem parte do
 * catálogo de um plano (config/erp.php erp.planos_catalogo.modulos).
 *
 * Invalidação: invalidar(), chamado por ServicoVersoesPlano::publicar() (muda o que está em vigor num plano) e por
 * quem atribui/muda o plano de um tenant (PlanosPlataformaController, RegistoEmpresaController) — a 2.ª cache
 * (tenant → código do plano) também fica desactualizada nesse caso.
 *
 * doTenantId() existe para ServicoPermissoes nunca pagar uma consulta a `tenants` por pedido (era a única leitura
 * sem cache no caminho do Gate/menu — ParidadeLancamentosTest, que conta consultas, apanhou a regressão): os dois
 * mapas (código → módulos; tenant → código) ficam ambos em cache, só se lê `tenants`/`planos_licenca*` quando o TTL
 * expira ou depois de uma escrita relevante.
 */
final class ModulosPlano
{
    private const CACHE_CHAVE = 'plataforma:planos:modulos_em_vigor';

    private const CACHE_CHAVE_TENANTS = 'plataforma:planos:tenant_para_codigo';

    /** Módulos sempre disponíveis, independentemente do plano. */
    public const SEMPRE_DISPONIVEIS = ['geral', 'config'];

    /** @return ?list<string> módulos do plano em vigor; null = sem restrição (sem plano, ou plano sem versão publicada). */
    public static function doTenant(?string $codigoPlano): ?array
    {
        if ($codigoPlano === null) {
            return null;
        }

        return self::todas()[$codigoPlano] ?? null;
    }

    /** Mesma coisa, a partir do tenant_id directamente (ServicoPermissoes) — sem nenhuma consulta fora de cache. */
    public static function doTenantId(?int $tenantId): ?array
    {
        if ($tenantId === null) {
            return null;
        }

        return self::doTenant(self::tenantParaCodigo()[$tenantId] ?? null);
    }

    public static function permitido(?string $codigoPlano, string $modulo): bool
    {
        if (in_array($modulo, self::SEMPRE_DISPONIVEIS, true)) {
            return true;
        }
        $permitidos = self::doTenant($codigoPlano);

        return $permitidos === null || in_array($modulo, $permitidos, true);
    }

    /** Esquecer depois de publicar uma versão de plano, ou de atribuir/mudar o plano de um tenant (agora e depois do commit). */
    public static function invalidar(): void
    {
        InvalidacaoCache::esquecer(self::CACHE_CHAVE);
        InvalidacaoCache::esquecer(self::CACHE_CHAVE_TENANTS);
    }

    /** @return array<string, list<string>> código do plano => módulos da versão em vigor */
    private static function todas(): array
    {
        return rescue(
            fn () => Cache::remember(self::CACHE_CHAVE, (int) config('erp.cache.ttl.planos_modulos', 3600), function () {
                $mapa = [];
                foreach (PlanoLicenca::query()->get(['id', 'codigo']) as $plano) {
                    $versao = PlanoLicencaVersao::query()->where('plano_id', $plano->id)->where('estado', PlanoLicencaVersao::PUBLICADA)->orderByDesc('numero')->first();
                    if ($versao !== null) {
                        $mapa[$plano->codigo] = $versao->modulos ?? [];
                    }
                }

                return $mapa;
            }),
            [],
            report: false,
        ) ?? [];
    }

    /** @return array<int, string> tenant_id => código do plano (só tenants com plano atribuído) */
    private static function tenantParaCodigo(): array
    {
        return rescue(
            fn () => Cache::remember(self::CACHE_CHAVE_TENANTS, (int) config('erp.cache.ttl.planos_modulos', 3600),
                fn () => DB::table('tenants')->whereNotNull('plano')->pluck('plano', 'id')->all()),
            [],
            report: false,
        ) ?? [];
    }
}
