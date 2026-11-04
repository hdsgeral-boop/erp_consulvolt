<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sessões por dispositivo com rotação de token (ADR-082). Só acrescenta:
 *  - tokens_acesso.dispositivo_id: identificador do dispositivo emitido pelo SERVIDOR (cookie HttpOnly), a «família» da sessão;
 *  - tokens_acesso.rotulo / agente_assinatura: rótulo legível («Chrome em Windows») e hash do navegador+SO (vinculação);
 *  - tokens_acesso.tokens_anteriores / rotacao_em: hashes dos tokens já rodados (detecção de reutilização) e quando se rodou;
 *  - tokens_acesso.empresa_ativa_id: a empresa activa passa a ser estado da sessão no servidor (já não vive no navegador);
 *  - dispositivos_conhecidos: dispositivos já vistos por utilizador (aviso de «novo dispositivo»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tokens_acesso', function (Blueprint $table) {
            if (! Schema::hasColumn('tokens_acesso', 'dispositivo_id')) {
                $table->string('dispositivo_id', 64)->nullable();
            }
            if (! Schema::hasColumn('tokens_acesso', 'rotulo')) {
                $table->string('rotulo', 120)->nullable();
            }
            if (! Schema::hasColumn('tokens_acesso', 'agente_assinatura')) {
                $table->string('agente_assinatura', 64)->nullable();
            }
            if (! Schema::hasColumn('tokens_acesso', 'tokens_anteriores')) {
                $table->jsonb('tokens_anteriores')->nullable();
            }
            if (! Schema::hasColumn('tokens_acesso', 'rotacao_em')) {
                $table->timestampTz('rotacao_em')->nullable();
            }
            if (! Schema::hasColumn('tokens_acesso', 'empresa_ativa_id')) {
                $table->unsignedBigInteger('empresa_ativa_id')->nullable();
            }
        });

        DB::statement('CREATE INDEX IF NOT EXISTS tokens_acesso_dispositivo_idx ON tokens_acesso (portador_tipo, portador_id, dispositivo_id)');

        if (! Schema::hasTable('dispositivos_conhecidos')) {
            Schema::create('dispositivos_conhecidos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('utilizador_id');
                $table->string('dispositivo_id', 64);
                $table->timestampTz('primeiro_em');
                $table->timestampTz('ultimo_em');
                $table->unique(['utilizador_id', 'dispositivo_id']);
            });
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON dispositivos_conhecidos TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE dispositivos_conhecidos_id_seq TO erp_app; '
            .'END IF; END $$');
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_conhecidos');
        DB::statement('DROP INDEX IF EXISTS tokens_acesso_dispositivo_idx');
        Schema::table('tokens_acesso', function (Blueprint $table) {
            foreach (['dispositivo_id', 'rotulo', 'agente_assinatura', 'tokens_anteriores', 'rotacao_em', 'empresa_ativa_id'] as $c) {
                if (Schema::hasColumn('tokens_acesso', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
