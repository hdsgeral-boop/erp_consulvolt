<?php

namespace App\Models\Concerns;

use App\Services\Logistica\ServicoNiveisStock;
use App\Support\Cache\InvalidacaoCache;

/**
 * Documentos de compra que entram na posição de stock dos níveis de stock (ServicoNiveisStock: «em encomenda» = pedidos
 * PENDENTE/APROVADO + o que falta receber das encomendas EM_PROCESSAMENTO/PARCIAL). Criar, anular, rejeitar, mudar de
 * estado ou de quantidades invalida a avaliação em cache (ServicoNiveisStock::chaveCache), agora e depois do commit.
 * O modelo indica as colunas que contam em camposNiveisStock().
 */
trait InvalidaNiveisStock
{
    public static function bootInvalidaNiveisStock(): void
    {
        $invalidar = function ($m) {
            if ($m->empresa_id !== null) {
                InvalidacaoCache::esquecer(ServicoNiveisStock::chaveCache((int) $m->empresa_id));
            }
        };
        static::saved(function ($m) use ($invalidar) {
            if ($m->wasRecentlyCreated || $m->wasChanged($m->camposNiveisStock())) {
                $invalidar($m);
            }
        });
        static::deleted($invalidar);
        if (method_exists(static::class, 'restored')) {
            static::restored($invalidar);
        }
    }

    /** @return list<string> colunas que mudam a posição de stock */
    abstract public function camposNiveisStock(): array;
}
