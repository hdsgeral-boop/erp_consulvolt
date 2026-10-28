<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profissionalização do backoffice de suporte: SLA por prioridade (com indicador de atraso), atribuição a um agente
 * responsável, notas internas (nunca visíveis ao cliente), anexos na conversa, avaliação de satisfação do cliente
 * (CSAT) e respostas rápidas pré-definidas (catálogo partilhado pela equipa). Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tickets_suporte')) {
            Schema::table('tickets_suporte', function (Blueprint $t) {
                if (! Schema::hasColumn('tickets_suporte', 'agente_id')) {
                    $t->unsignedBigInteger('agente_id')->nullable();
                    $t->foreign('agente_id', 'fk_tickets_suporte_agente_id')->references('id')->on('utilizadores')->nullOnDelete();
                }
                if (! Schema::hasColumn('tickets_suporte', 'prazo_sla')) {
                    $t->timestampTz('prazo_sla')->nullable();
                }
                if (! Schema::hasColumn('tickets_suporte', 'primeira_resposta_em')) {
                    $t->timestampTz('primeira_resposta_em')->nullable();
                }
                if (! Schema::hasColumn('tickets_suporte', 'csat_nota')) {
                    $t->unsignedTinyInteger('csat_nota')->nullable();
                }
                if (! Schema::hasColumn('tickets_suporte', 'csat_comentario')) {
                    $t->text('csat_comentario')->nullable();
                }
            });
        }

        if (! Schema::hasTable('notas_internas_suporte')) {
            Schema::create('notas_internas_suporte', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('ticket_id');
                $t->string('autor_nome', 100)->nullable();
                $t->text('nota');
                $t->timestampTz('criado_em')->useCurrent();
                $t->foreign('ticket_id', 'fk_notas_internas_suporte_ticket_id')->references('id')->on('tickets_suporte')->cascadeOnDelete();
                $t->index(['ticket_id', 'criado_em'], 'idx_notas_internas_suporte_ticket');
            });
        }

        if (! Schema::hasTable('anexos_suporte')) {
            Schema::create('anexos_suporte', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('mensagem_id');
                $t->string('nome_original', 255);
                $t->string('caminho', 500);
                $t->unsignedBigInteger('tamanho_bytes');
                $t->string('tipo_mime', 100);
                $t->timestampTz('criado_em')->useCurrent();
                $t->foreign('mensagem_id', 'fk_anexos_suporte_mensagem_id')->references('id')->on('mensagens_suporte')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('respostas_rapidas_suporte')) {
            Schema::create('respostas_rapidas_suporte', function (Blueprint $t) {
                $t->id();
                $t->string('titulo', 150);
                $t->text('texto');
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
            });
        }

        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON notas_internas_suporte, anexos_suporte, respostas_rapidas_suporte TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE notas_internas_suporte_id_seq, anexos_suporte_id_seq, respostas_rapidas_suporte_id_seq TO erp_app; END IF; END $$');
    }

    public function down(): void
    {
        Schema::dropIfExists('respostas_rapidas_suporte');
        Schema::dropIfExists('anexos_suporte');
        Schema::dropIfExists('notas_internas_suporte');
        if (Schema::hasTable('tickets_suporte')) {
            Schema::table('tickets_suporte', function (Blueprint $t) {
                foreach (['agente_id', 'prazo_sla', 'primeira_resposta_em', 'csat_nota', 'csat_comentario'] as $coluna) {
                    if (Schema::hasColumn('tickets_suporte', $coluna)) {
                        if ($coluna === 'agente_id') {
                            $t->dropForeign('fk_tickets_suporte_agente_id');
                        }
                        $t->dropColumn($coluna);
                    }
                }
            });
        }
    }
};
