<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria de webhooks de pagamento: regista TODAS as tentativas (aceites e rejeitadas).
 * `evento_unico` só é preenchido nos eventos aceites (gateway:evento_id) e é único => idempotência.
 * Migração aditiva e idempotente: não altera nem apaga nada existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webhooks_pagamento')) {
            Schema::create('webhooks_pagamento', function (Blueprint $t) {
                $t->id();
                $t->string('gateway', 30);
                $t->string('evento_id', 120)->nullable();
                $t->string('evento_unico', 160)->nullable()->unique();
                $t->unsignedBigInteger('subscricao_id')->nullable();
                $t->string('resultado', 12);   // ACEITE, REJEITADO
                $t->string('motivo', 255)->nullable();
                $t->unsignedSmallInteger('estado_http');
                $t->boolean('assinatura_valida')->default(false);
                $t->unsignedBigInteger('timestamp_evento')->nullable();
                $t->string('ip', 64)->nullable();
                $t->jsonb('payload')->nullable();
                $t->timestampTz('criado_em')->useCurrent();

                $t->index(['gateway', 'evento_id'], 'idx_webhooks_pagamento_evento');
                $t->index(['subscricao_id'], 'idx_webhooks_pagamento_subscricao');
                $t->index(['criado_em'], 'idx_webhooks_pagamento_data');
            });
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE ON webhooks_pagamento TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE webhooks_pagamento_id_seq TO erp_app; END IF; END $$');
    }

    public function down(): void
    {
        // Intencionalmente vazio: a auditoria nunca é apagada.
    }
};
