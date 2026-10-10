<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telefone do utilizador («Gerir Dados Pessoais» no menu do utilizador, 2026-10-10). Aditiva e opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            if (! Schema::hasColumn('utilizadores', 'telefone')) {
                $table->string('telefone', 30)->nullable()->comment('Telefone de contacto do utilizador (gerido pelo próprio)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->dropColumn('telefone');
        });
    }
};
