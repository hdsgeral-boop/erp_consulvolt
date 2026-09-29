<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

/**
 * Convenções de esquema partilhadas por todas as migrations do ERP (ver docs/dicionario/DICIONARIO_DADOS.md).
 */
class BaseDadosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // criado_em / atualizado_em (TIMESTAMP WITH TIME ZONE)
        Blueprint::macro('carimbosTemporais', function () {
            /** @var Blueprint $this */
            $this->timestampTz('criado_em')->nullable()->useCurrent();
            $this->timestampTz('atualizado_em')->nullable()->useCurrent();
        });

        // eliminado_em — eliminação lógica (SoftDeletes com DELETED_AT = 'eliminado_em')
        Blueprint::macro('eliminacaoLogica', function () {
            /** @var Blueprint $this */
            $this->timestampTz('eliminado_em')->nullable();
        });

        // NUMERIC(15,2) — valores monetários
        Blueprint::macro('monetario', function (string $coluna) {
            /** @var Blueprint $this */
            return $this->decimal($coluna, 15, 2);
        });

        // NUMERIC(12,3) — quantidades de stock
        Blueprint::macro('quantidade', function (string $coluna) {
            /** @var Blueprint $this */
            return $this->decimal($coluna, 12, 3);
        });

        // NUMERIC(9,4) — taxas e percentagens
        Blueprint::macro('taxa', function (string $coluna) {
            /** @var Blueprint $this */
            return $this->decimal($coluna, 9, 4);
        });

        // Chave de tenant obrigatória com FK para empresas (RESTRICT: nunca apagar em cascata dados de uma empresa).
        Blueprint::macro('empresa', function () {
            /** @var Blueprint $this */
            return $this->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
        });
    }
}
