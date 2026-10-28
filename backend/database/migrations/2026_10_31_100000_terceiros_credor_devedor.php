<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Novo papel CREDOR_DEVEDOR (autónomo, não combinável com cliente/fornecedor/colaborador): entidades de conta
 * corrente genérica, classe 37, sem papel comercial. `conta_credor_devedor` é a conta própria do papel
 * (App\Services\Terceiros\ContaTerceiro), paralela a `conta_cliente`/`conta_fornecedor`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terceiros', function (Blueprint $table) {
            $table->string('conta_credor_devedor', 20)->nullable()->after('conta_fornecedor');
        });
        DB::statement('ALTER TABLE terceiros DROP CONSTRAINT IF EXISTS ck_terceiros_tipo');
        DB::statement("ALTER TABLE terceiros ADD CONSTRAINT ck_terceiros_tipo CHECK (tipo IS NULL OR tipo IN ('CLIENTE','FORNECEDOR','COLABORADOR','CLIENTE_FORNECEDOR','CREDOR_DEVEDOR'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE terceiros SET tipo = NULL WHERE tipo = 'CREDOR_DEVEDOR'");
        DB::statement('ALTER TABLE terceiros DROP CONSTRAINT IF EXISTS ck_terceiros_tipo');
        DB::statement("ALTER TABLE terceiros ADD CONSTRAINT ck_terceiros_tipo CHECK (tipo IS NULL OR tipo IN ('CLIENTE','FORNECEDOR','COLABORADOR','CLIENTE_FORNECEDOR'))");
        Schema::table('terceiros', function (Blueprint $table) {
            $table->dropColumn('conta_credor_devedor');
        });
    }
};
