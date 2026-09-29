<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users (legado) -> utilizadores + utilizador_empresa.
 *
 * Palavras-passe: `palavra_passe` guarda o hash moderno (Argon2id). Os campos *_legado guardam o
 * PBKDF2-SHA256 do legado (js/data/senhas.js) até ao primeiro login, em que é feito o re-hash e são limpos.
 *
 * Acesso a empresas: no legado `allowed_companies` vazio significava "todas" (js/data/servicos.js:56-64).
 * Esse comportamento implícito passa a ser explícito em `acesso_todas_empresas`; as restantes ligações,
 * incluindo o colaborador associado em cada empresa (users.colaboradores), vão para `utilizador_empresa`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilizadores', function (Blueprint $table) {
            $table->id();
            $table->string('nome_utilizador', 100)->unique();
            $table->string('nome_completo', 200)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('palavra_passe', 255)->nullable();
            $table->string('hash_password_legado', 255)->nullable();
            $table->string('salt_password_legado', 100)->nullable();
            $table->string('algoritmo_password_legado', 50)->nullable();
            $table->timestampTz('palavra_passe_alterada_em')->nullable();
            $table->string('papel', 30)->default('UTILIZADOR');
            $table->string('papel_original', 30)->nullable();
            $table->foreignId('perfil_utilizador_id')->nullable()->constrained('perfis_utilizador')->restrictOnDelete();
            $table->boolean('acesso_todas_empresas')->default(false);
            $table->jsonb('modulos_permitidos')->nullable();   // legado; não é verificado pelo legado (preservado)
            $table->boolean('ativo')->default(true);
            $table->timestampTz('ultimo_acesso_em')->nullable();
            $table->carimbosTemporais();
            $table->eliminacaoLogica();
        });

        DB::statement("ALTER TABLE utilizadores ADD CONSTRAINT ck_utilizadores_papel CHECK (papel IN ('SUPER_ADMINISTRADOR','ADMINISTRADOR','UTILIZADOR'))");
        DB::statement('ALTER TABLE utilizadores ADD CONSTRAINT ck_utilizadores_credencial CHECK (palavra_passe IS NOT NULL OR (hash_password_legado IS NOT NULL AND salt_password_legado IS NOT NULL))');

        Schema::create('utilizador_empresa', function (Blueprint $table) {
            $table->foreignId('utilizador_id')->constrained('utilizadores')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('colaborador_id')->nullable(); // FK criada com a tabela colaboradores (Fase 2)
            $table->timestampTz('criado_em')->nullable()->useCurrent();
            $table->primary(['utilizador_id', 'empresa_id']);
            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utilizador_empresa');
        Schema::dropIfExists('utilizadores');
    }
};
