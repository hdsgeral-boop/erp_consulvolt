<?php

namespace App\Services\Sistema;

use App\Models\Empresa;
use App\Models\Utilizador;
use App\Support\Cache\ChaveCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Empresas a que cada utilizador tem acesso (paridade: js/data/servicos.js:56-64).
 *  - SUPER_ADMINISTRADOR ou `acesso_todas_empresas` => todas as empresas activas;
 *  - restantes => empresas ligadas em utilizador_empresa.
 * Os ids ficam em cache no Redis (erp:utilizador:{id}:empresas) e são invalidados em qualquer alteração.
 */
final class ServicoEmpresas
{
    /** @return list<int> */
    public function idsAcessiveis(Utilizador $utilizador): array
    {
        return Cache::remember(
            ChaveCache::utilizador($utilizador->getKey(), 'empresas:v'.$this->versao()),
            config('erp.cache.ttl.empresas_utilizador'),
            function () use ($utilizador): array {
                $consulta = Empresa::query()->where('estado', Empresa::ESTADO_ATIVO);

                if (! $utilizador->eSuperAdministrador() && ! $utilizador->acesso_todas_empresas) {
                    $consulta->whereIn('id', $utilizador->empresas()->select('empresas.id'));
                }

                return $consulta->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            },
        );
    }

    /** @return Collection<int, Empresa> */
    public function acessiveis(Utilizador $utilizador): Collection
    {
        return Empresa::query()->whereIn('id', $this->idsAcessiveis($utilizador))->orderBy('nome')->get();
    }

    public function podeAceder(Utilizador $utilizador, int $empresaId): bool
    {
        return in_array($empresaId, $this->idsAcessiveis($utilizador), true);
    }

    public function invalidarUtilizador(int $utilizadorId): void
    {
        Cache::forget(ChaveCache::utilizador($utilizadorId, 'empresas:v'.$this->versao()));
    }

    /** Uma empresa mudou de estado: todas as listas em cache ficam inválidas (nova versão da chave). */
    public function invalidarTodos(): void
    {
        Cache::forever('empresas:versao', $this->versao() + 1);
    }

    private function versao(): int
    {
        return (int) Cache::get('empresas:versao', 1);
    }
}
