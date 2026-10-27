<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reordenar marcos (milestones) do WBS: hoje ordenados pela criação (id); passam a ter uma ordem própria, no mesmo
 * padrão de `tarefas_projeto.ordem` (incrementos de 10, renumerados a cada movimento). Marcos antigos sem valor
 * continuam a ordenar-se por id (fallback `ordem ?? 1_000_000 + id`, tanto no backend como no frontend).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marcos_projeto')) {
            return;
        }
        Schema::table('marcos_projeto', function (Blueprint $t) {
            if (! Schema::hasColumn('marcos_projeto', 'ordem')) {
                $t->integer('ordem')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('marcos_projeto', function (Blueprint $t) {
            if (Schema::hasColumn('marcos_projeto', 'ordem')) {
                $t->dropColumn('ordem');
            }
        });
    }
};
