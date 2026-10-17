<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activos (ronda 3, agente r3_activos):
 *   1) Propriedade do bem/edifício — ativos_imobilizados.propriedade PROPRIO | ALHEIO (arrendado, cedido, de terceiros).
 *      Só os próprios entram em amortizações, mapas fiscais e contabilização da aquisição. Os existentes ficam PROPRIO
 *      (valor por omissão: nenhuma linha muda de comportamento). Dados opcionais do proprietário/contrato dos alheios.
 *   2) Ordens de manutenção — histórico das tarefas e da ordem (quem/quando concluiu ou reabriu cada tarefa, reaberturas)
 *      e o estado do plano antes da conclusão (para o repor se a ordem for reaberta). As tarefas continuam no JSON
 *      `tarefas` (agora com id, obrigatoria, feito_por e feito_em; as antigas são lidas como obrigatórias).
 * Migração aditiva, com guardas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ativos_imobilizados')) {
            Schema::table('ativos_imobilizados', function (Blueprint $table) {
                $colunas = [
                    'propriedade' => fn () => $table->string('propriedade', 10)->default('PROPRIO')->comment('PROPRIO | ALHEIO — só os próprios amortizam'),
                    'regime_alheio' => fn () => $table->string('regime_alheio', 12)->nullable()->comment('ALHEIO: ARRENDADO | CEDIDO | TERCEIROS | OUTRO'),
                    'proprietario_terceiro_id' => fn () => $table->bigInteger('proprietario_terceiro_id')->nullable()->comment('ALHEIO: proprietário (terceiro registado)'),
                    'proprietario_nome' => fn () => $table->string('proprietario_nome', 255)->nullable()->comment('ALHEIO: proprietário (texto livre)'),
                    'contrato_referencia' => fn () => $table->string('contrato_referencia', 100)->nullable()->comment('ALHEIO: n.º/referência do contrato'),
                    'contrato_inicio' => fn () => $table->date('contrato_inicio')->nullable(),
                    'contrato_fim' => fn () => $table->date('contrato_fim')->nullable(),
                    'renda_mensal' => fn () => $table->decimal('renda_mensal', 15, 2)->nullable()->comment('ALHEIO: renda/encargo mensal (informativo)'),
                    'propriedade_notas' => fn () => $table->text('propriedade_notas')->nullable(),
                ];
                foreach ($colunas as $nome => $criar) {
                    if (! Schema::hasColumn('ativos_imobilizados', $nome)) {
                        $criar();
                    }
                }
            });
            // linhas antigas sem valor (o default só preenche as novas em alguns motores): ficam «Próprio»
            DB::table('ativos_imobilizados')->whereNull('propriedade')->update(['propriedade' => 'PROPRIO']);
            $this->check('ativos_imobilizados', 'ck_ativos_imobilizados_propriedade', "propriedade IN ('PROPRIO','ALHEIO')");
            $this->check('ativos_imobilizados', 'ck_ativos_imobilizados_regime_alheio', "regime_alheio IS NULL OR regime_alheio IN ('ARRENDADO','CEDIDO','TERCEIROS','OUTRO')");
            $this->check('ativos_imobilizados', 'ck_ativos_imobilizados_contrato_datas', 'contrato_fim IS NULL OR contrato_inicio IS NULL OR contrato_fim >= contrato_inicio');
            if (Schema::hasTable('terceiros') && ! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', ['fk_ativos_imobilizados_proprietario_terceiro_id'])) {
                DB::statement('ALTER TABLE ativos_imobilizados ADD CONSTRAINT fk_ativos_imobilizados_proprietario_terceiro_id FOREIGN KEY (proprietario_terceiro_id) '
                    .'REFERENCES terceiros (id) ON DELETE SET NULL DEFERRABLE INITIALLY IMMEDIATE');
            }
            DB::statement('CREATE INDEX IF NOT EXISTS ix_ativos_imobilizados_propriedade ON ativos_imobilizados (empresa_id, propriedade)');
        }

        if (Schema::hasTable('ordens_manutencao_ativos')) {
            Schema::table('ordens_manutencao_ativos', function (Blueprint $table) {
                $colunas = [
                    'historico' => fn () => $table->jsonb('historico')->nullable()->comment('eventos da ordem e das tarefas: [{em, por, accao, tarefa?, texto?, motivo?}]'),
                    'plano_antes' => fn () => $table->jsonb('plano_antes')->nullable()->comment('estado do plano antes da conclusão (repõe-se ao reabrir)'),
                    'reaberta_em' => fn () => $table->timestampTz('reaberta_em')->nullable(),
                    'reaberta_por' => fn () => $table->string('reaberta_por', 100)->nullable(),
                ];
                foreach ($colunas as $nome => $criar) {
                    if (! Schema::hasColumn('ordens_manutencao_ativos', $nome)) {
                        $criar();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ordens_manutencao_ativos')) {
            Schema::table('ordens_manutencao_ativos', function (Blueprint $table) {
                foreach (['historico', 'plano_antes', 'reaberta_em', 'reaberta_por'] as $c) {
                    if (Schema::hasColumn('ordens_manutencao_ativos', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('ativos_imobilizados')) {
            DB::statement('ALTER TABLE ativos_imobilizados DROP CONSTRAINT IF EXISTS fk_ativos_imobilizados_proprietario_terceiro_id');
            foreach (['ck_ativos_imobilizados_propriedade', 'ck_ativos_imobilizados_regime_alheio', 'ck_ativos_imobilizados_contrato_datas'] as $ck) {
                DB::statement("ALTER TABLE ativos_imobilizados DROP CONSTRAINT IF EXISTS {$ck}");
            }
            DB::statement('DROP INDEX IF EXISTS ix_ativos_imobilizados_propriedade');
            Schema::table('ativos_imobilizados', function (Blueprint $table) {
                foreach (['propriedade', 'regime_alheio', 'proprietario_terceiro_id', 'proprietario_nome', 'contrato_referencia', 'contrato_inicio', 'contrato_fim',
                    'renda_mensal', 'propriedade_notas'] as $c) {
                    if (Schema::hasColumn('ativos_imobilizados', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }

    private function check(string $tabela, string $nome, string $expressao): void
    {
        if (! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', [$nome])) {
            DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }
    }
};
