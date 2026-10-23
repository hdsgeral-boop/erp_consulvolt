<?php

namespace App\Support\Tenancy;

use App\Support\Cache\InvalidacaoCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Definições globais da plataforma SaaS (`definicoes_plataforma`), com fallback para o `config/erp.php` /
 * `.env` quando a chave ainda não foi gravada na base de dados. É a única fonte de verdade a consultar: antes
 * desta classe, `ServicoLicenca`, o `Empresa::booted()`, o `ResolverEmpresaAtiva` e o registo de empresas liam
 * `config('erp.licencas...')` directamente, pelo que activar/desligar licenças ou mudar os dias de teste no ecrã
 * de administração da plataforma (PlataformaSaasGestao) não tinha qualquer efeito real — só actualizava o ecrã.
 *
 * Em cache (CACHE_CHAVE, TTL CACHE_TTL): `ResolverEmpresaAtiva` lê `licencasAtivas()` em todos os pedidos com
 * empresa activa, por isso uma consulta à BD por pedido tinha um custo real (e empurrava testes de desempenho
 * que contam consultas, ex. DesempenhoTest, para lá do limite). As três definições cabem sempre numa só leitura
 * (a tabela tem meia dúzia de linhas) — chave global de metadados do sistema (docs/arquitetura/CACHE.md §2/§4.1).
 * Invalidação: `invalidar()`, chamado depois de qualquer escrita em `definicoes_plataforma`
 * (PlataformaSaasController::atualizarGeral, DefinicoesController::gravar) — nunca um TTL sozinho (regra 3).
 */
final class DefinicoesPlataforma
{
    /** Chave global (metadados do sistema, não de uma empresa/utilizador — docs/arquitetura/CACHE.md §4.1). */
    private const CACHE_CHAVE = 'plataforma:definicoes';

    public static function saasAtivo(): bool
    {
        return self::booleano('saas_ativo', (bool) config('erp.saas.ativo'));
    }

    public static function licencasAtivas(): bool
    {
        return self::booleano('licencas_ativas', (bool) config('erp.licencas.ativo'));
    }

    public static function diasTestePadrao(): int
    {
        $valor = self::valor('dias_teste_padrao');

        return $valor !== null ? (int) $valor : (int) config('erp.licencas.dias_teste', 30);
    }

    /** Email de contacto do suporte (definicoes_plataforma.email_suporte), mostrado no rodapé dos emails e usado para notificar a equipa de um ticket novo. */
    public static function emailSuporte(): ?string
    {
        return self::valor('email_suporte') ?: config('mail.from.address');
    }

    /** Esquecer depois de qualquer escrita em `definicoes_plataforma` (agora e depois do commit — InvalidacaoCache). */
    public static function invalidar(): void
    {
        InvalidacaoCache::esquecer(self::CACHE_CHAVE);
    }

    private static function booleano(string $chave, bool $omissao): bool
    {
        $valor = self::valor($chave);

        return $valor !== null ? ($valor === 'true' || $valor === '1') : $omissao;
    }

    private static function valor(string $chave): ?string
    {
        return self::todas()[$chave] ?? null;
    }

    /** @return array<string, string> todas as definições guardadas (chave => valor); leitura opcional — nunca bloqueia o pedido (regra 9). */
    private static function todas(): array
    {
        return rescue(
            fn () => Cache::remember(self::CACHE_CHAVE, (int) config('erp.cache.ttl.definicoes_plataforma', 3600), fn () => DB::table('definicoes_plataforma')->pluck('valor', 'chave')->all()),
            [],
            report: false,
        ) ?? [];
    }
}
