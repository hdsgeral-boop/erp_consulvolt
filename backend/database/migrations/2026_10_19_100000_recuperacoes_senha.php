<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tokens de recuperação de palavra-passe: guarda só o hash (sha256), com expiração curta e uso único. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recuperacoes_senha')) {
            return;
        }

        Schema::create('recuperacoes_senha', function (Blueprint $t) {
            $t->id();
            $t->foreignId('utilizador_id')->constrained('utilizadores')->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestampTz('expira_em');
            $t->timestampTz('usado_em')->nullable();
            $t->string('endereco_ip', 45)->nullable();
            $t->timestampTz('criado_em')->useCurrent();

            $t->index(['utilizador_id', 'usado_em'], 'idx_recuperacoes_senha_utilizador');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recuperacoes_senha');
    }
};
