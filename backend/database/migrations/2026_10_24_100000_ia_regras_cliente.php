<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_regras_cliente', function (Blueprint $t) {
            $t->foreignId('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $t->text('regras')->default('');
            $t->timestampTz('atualizado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_regras_cliente');
    }
};
