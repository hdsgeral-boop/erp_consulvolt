<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serviços de lavandaria por terminal: cada POS de Lavandaria/Alfaiataria passa a escolher, do catálogo de serviços
 * da empresa, quais usa no seu dia-a-dia — em vez de todos os terminais de Lavandaria verem sempre o catálogo inteiro.
 * Aditiva e com guarda: `lavandaria_servicos_ids` NULL (omissão) = sem restrição (todos os serviços, comportamento
 * actual preservado nos terminais já criados); lista de ids de `produtos` = só esses aparecem na Recepção.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('terminais_pos', 'lavandaria_servicos_ids')) {
            DB::statement('ALTER TABLE terminais_pos ADD COLUMN lavandaria_servicos_ids jsonb NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('terminais_pos', 'lavandaria_servicos_ids')) {
            DB::statement('ALTER TABLE terminais_pos DROP COLUMN lavandaria_servicos_ids');
        }
    }
};
