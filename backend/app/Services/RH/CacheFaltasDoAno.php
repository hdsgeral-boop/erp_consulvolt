<?php

namespace App\Services\RH;

use App\Support\Cache\CacheComprimida;
use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;
use Illuminate\Support\Facades\Cache;

/**
 * Cache Redis do acumulado anual de faltas injustificadas (art. 230.º da Lei 12/23), por empresa e por ano
 * (ServicoAssiduidade::faltasPorMes). Valor: ['primeiro' => dia do primeiro registo do ano ('' = nenhum),
 * 'meses' => [AAAA-MM => ['ate' => dia até onde o mês foi apurado, 'faltas' => [colaborador_id => dias]]]].
 *
 * Chaves (ChaveCache, isolamento por empresa):
 *   {empresa}:rh:faltas_ano:versao             contador da empresa (sem TTL)
 *   {empresa}:rh:faltas_ano:{ano}:v{versao}    apuramento dos meses do ano
 * Invalidação por versão: qualquer alteração que mude o apuramento (registos de efectividade, fecho/reabertura, ausências, férias,
 * escala, contrato, colaborador, configuração — modelos com InvalidaFaltasDoAno, e as escritas em massa que não passam pelos
 * eventos) sobe a versão agora e outra vez depois do commit (R2, InvalidacaoCache). Um pedido concorrente que leu a versão antiga
 * só consegue gravar na chave antiga, que ninguém volta a ler. O TTL limita o que escapasse aos eventos (ex.: SQL directo).
 */
final class CacheFaltasDoAno
{
    /** 6 h: os meses passados raramente mudam e toda a alteração conhecida invalida; o mês corrente é revalidado a cada dia. */
    public const TTL = 6 * 3600;

    public static function chaveVersao(int $empresaId): string
    {
        return ChaveCache::empresa($empresaId, 'rh', 'faltas_ano:versao');
    }

    public static function chave(int $empresaId, int $ano): string
    {
        return ChaveCache::empresa($empresaId, 'rh', "faltas_ano:{$ano}:v".self::versao($empresaId));
    }

    public static function versao(int $empresaId): int
    {
        return (int) Cache::get(self::chaveVersao($empresaId), 1);
    }

    /** @return array{primeiro?: string, meses?: array<string, array{ate: string, faltas: array<int, int>}>} */
    public static function ler(string $chave): array
    {
        $v = CacheComprimida::abrir(Cache::get($chave));

        return is_array($v) ? $v : [];
    }

    /** @param  array{primeiro: string, meses: array<string, array{ate: string, faltas: array<int, int>}>}  $valor */
    public static function gravar(string $chave, array $valor): void
    {
        Cache::put($chave, CacheComprimida::embrulhar($valor), self::TTL);
    }

    /** Invalida todos os anos da empresa: agora e depois do commit (incremento atómico, como ServicoEmpresas::invalidarTodos). */
    public static function invalidar(int $empresaId): void
    {
        if ($empresaId <= 0) {
            return;
        }
        $chave = self::chaveVersao($empresaId);
        InvalidacaoCache::agoraEDepoisDoCommit(function () use ($chave) {
            Cache::add($chave, 1);
            Cache::increment($chave);
        });
    }
}
