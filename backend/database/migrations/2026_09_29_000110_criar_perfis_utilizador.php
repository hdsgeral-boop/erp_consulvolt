<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_profiles (legado) -> perfis_utilizador.
 * `permissoes` preserva o JSON do legado nos dois formatos suportados (js/permissoes.js:10-11):
 *   - v2 (`_v2: true`): só vale o que está marcado — "<ecra>_view" e chaves de tarefa;
 *   - antigo: `{all: true}` ou "<modulo>[_view|_edit|_modificar|_delete]".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfis_utilizador', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100);
            $table->text('descricao')->nullable();
            $table->jsonb('permissoes')->default('{}');
            $table->carimbosTemporais();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfis_utilizador');
    }
};
