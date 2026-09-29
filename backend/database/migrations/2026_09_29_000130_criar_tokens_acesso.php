<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de acesso à API (Laravel Sanctum) com colunas em português.
 * O model App\Models\TokenAcesso faz a correspondência com os atributos que o Sanctum espera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens_acesso', function (Blueprint $table) {
            $table->id();
            $table->string('portador_tipo');
            $table->unsignedBigInteger('portador_id');
            $table->string('nome', 255);
            $table->string('token', 64)->unique();       // SHA-256 do segredo (o segredo nunca é guardado)
            $table->jsonb('permissoes')->nullable();      // abilities do Sanctum
            $table->string('endereco_ip', 45)->nullable();
            $table->text('agente_utilizador')->nullable();
            $table->timestampTz('ultimo_uso_em')->nullable();
            $table->timestampTz('expira_em')->nullable();
            $table->carimbosTemporais();

            $table->index(['portador_tipo', 'portador_id']);
            $table->index('expira_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens_acesso');
    }
};
