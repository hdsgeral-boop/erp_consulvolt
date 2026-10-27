<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags do projecto: lista simples de texto livre (sem ecrã de gestão central de tags), guardada como array JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projetos')) {
            return;
        }
        Schema::table('projetos', function (Blueprint $t) {
            if (! Schema::hasColumn('projetos', 'tags')) {
                $t->jsonb('tags')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projetos', function (Blueprint $t) {
            if (Schema::hasColumn('projetos', 'tags')) {
                $t->dropColumn('tags');
            }
        });
    }
};
