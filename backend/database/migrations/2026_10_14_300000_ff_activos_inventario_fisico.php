<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase F (Versão Actual, ADR-071) — edifícios e folhas de inventário físico (asset_inventories / asset_inventory_lines,
 * db_v2.js v1268; js/activos_edificios.js:594-610, 784-915). As duas tabelas foram geradas do backup novo só com
 * empresa_id (0 linhas reais): acrescentam-se as colunas que o legado grava. Migração aditiva, com guardas.
 * Melhoria: a folha não se apaga — anula-se com motivo (anulado_em/anulado_por/motivo_anulacao).
 * As colunas «porta_id»/«andar_id» das linhas são a fotografia do local esperado no momento da geração: ficam sem FK
 * para que eliminar uma porta não apague nem bloqueie o histórico do inventário.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventarios_fisicos_ativos')) {
            Schema::table('inventarios_fisicos_ativos', function (Blueprint $table) {
                $colunas = [
                    'edificio_id' => fn () => $table->bigInteger('edificio_id')->nullable()->comment('legado: building_id'),
                    'ambito' => fn () => $table->string('ambito', 10)->nullable()->comment('legado: ambito (EDIFICIO|ANDAR|PORTA)'),
                    'andar_id' => fn () => $table->bigInteger('andar_id')->nullable()->comment('legado: floor_id'),
                    'porta_id' => fn () => $table->bigInteger('porta_id')->nullable()->comment('legado: room_id'),
                    'data' => fn () => $table->date('data')->nullable()->comment('legado: data'),
                    'responsavel' => fn () => $table->string('responsavel', 150)->nullable()->comment('legado: responsavel'),
                    'notas' => fn () => $table->text('notas')->nullable()->comment('legado: notas'),
                    'estado' => fn () => $table->string('estado', 10)->nullable()->comment('legado: estado (ABERTO|FECHADO; novo: ANULADO)'),
                    'esperados' => fn () => $table->integer('esperados')->nullable()->comment('legado: esperados'),
                    'valor_esperado' => fn () => $table->decimal('valor_esperado', 15, 2)->nullable()->comment('legado: valor_esperado'),
                    'contado_em' => fn () => $table->timestampTz('contado_em')->nullable()->comment('legado: contado_em'),
                    'aplicado_em' => fn () => $table->timestampTz('aplicado_em')->nullable()->comment('legado: aplicado_em'),
                    'fechado_em' => fn () => $table->timestampTz('fechado_em')->nullable()->comment('legado: fechado_em'),
                    'fechado_por' => fn () => $table->string('fechado_por', 150)->nullable()->comment('legado: fechado_por'),
                    'criado_por' => fn () => $table->string('criado_por', 100)->nullable()->comment('novo: utilizador que gerou a folha'),
                    'anulado_em' => fn () => $table->timestampTz('anulado_em')->nullable()->comment('novo: anulação em vez de eliminação'),
                    'anulado_por' => fn () => $table->string('anulado_por', 100)->nullable()->comment('novo'),
                    'motivo_anulacao' => fn () => $table->text('motivo_anulacao')->nullable()->comment('novo'),
                ];
                foreach ($colunas as $nome => $criar) {
                    if (! Schema::hasColumn('inventarios_fisicos_ativos', $nome)) {
                        $criar();
                    }
                }
            });
            DB::statement('CREATE INDEX IF NOT EXISTS ix_inventarios_fisicos_ativos_edificio_id ON inventarios_fisicos_ativos (edificio_id)');
            $this->fk('inventarios_fisicos_ativos', 'edificio_id', 'ativos_imobilizados');
            $this->check('inventarios_fisicos_ativos', 'ck_inventarios_fisicos_ativos_estado', "estado IS NULL OR estado IN ('ABERTO','FECHADO','ANULADO')");
            $this->check('inventarios_fisicos_ativos', 'ck_inventarios_fisicos_ativos_ambito', "ambito IS NULL OR ambito IN ('EDIFICIO','ANDAR','PORTA')");
        }

        if (Schema::hasTable('linhas_inventario_fisico_ativos')) {
            Schema::table('linhas_inventario_fisico_ativos', function (Blueprint $table) {
                $colunas = [
                    'inventario_fisico_id' => fn () => $table->bigInteger('inventario_fisico_id')->nullable()->comment('legado: inventory_id'),
                    'ativo_imobilizado_id' => fn () => $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id'),
                    'codigo' => fn () => $table->string('codigo', 50)->nullable()->comment('legado: code (fotografia)'),
                    'descricao' => fn () => $table->text('descricao')->nullable()->comment('legado: description (fotografia)'),
                    'porta_id' => fn () => $table->bigInteger('porta_id')->nullable()->comment('legado: room_id (local esperado, fotografia)'),
                    'andar_id' => fn () => $table->bigInteger('andar_id')->nullable()->comment('legado: floor_id (fotografia)'),
                    'valor' => fn () => $table->decimal('valor', 15, 2)->nullable()->comment('legado: valor'),
                    'resultado' => fn () => $table->string('resultado', 12)->nullable()->comment('legado: resultado (ENCONTRADO|EM_FALTA|DESLOCADO|EXTRA; vazio = por contar)'),
                    'porta_encontrada_id' => fn () => $table->bigInteger('porta_encontrada_id')->nullable()->comment('legado: room_encontrada_id'),
                    'notas' => fn () => $table->text('notas')->nullable()->comment('legado: notas'),
                ];
                foreach ($colunas as $nome => $criar) {
                    if (! Schema::hasColumn('linhas_inventario_fisico_ativos', $nome)) {
                        $criar();
                    }
                }
            });
            DB::statement('CREATE INDEX IF NOT EXISTS ix_linhas_inventario_fisico_ativos_inventario ON linhas_inventario_fisico_ativos (inventario_fisico_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS ix_linhas_inventario_fisico_ativos_ativo ON linhas_inventario_fisico_ativos (ativo_imobilizado_id)');
            $this->fk('linhas_inventario_fisico_ativos', 'inventario_fisico_id', 'inventarios_fisicos_ativos');
            $this->fk('linhas_inventario_fisico_ativos', 'ativo_imobilizado_id', 'ativos_imobilizados');
            $this->check('linhas_inventario_fisico_ativos', 'ck_linhas_inventario_fisico_ativos_resultado',
                "resultado IS NULL OR resultado IN ('ENCONTRADO','EM_FALTA','DESLOCADO','EXTRA')");
        }

        // tipo de activo (BEM | EDIFICIO): NOT VALID para não recusar dados migrados com outro texto (validam-se só as linhas novas)
        if (Schema::hasColumn('ativos_imobilizados', 'tipo_ativo')) {
            $this->check('ativos_imobilizados', 'ck_ativos_imobilizados_tipo_ativo', "tipo_ativo IS NULL OR tipo_ativo IN ('BEM','EDIFICIO')", true);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ativos_imobilizados DROP CONSTRAINT IF EXISTS ck_ativos_imobilizados_tipo_ativo');
        foreach (['linhas_inventario_fisico_ativos' => ['inventario_fisico_id', 'ativo_imobilizado_id', 'codigo', 'descricao', 'porta_id', 'andar_id', 'valor', 'resultado', 'porta_encontrada_id', 'notas'],
            'inventarios_fisicos_ativos' => ['edificio_id', 'ambito', 'andar_id', 'porta_id', 'data', 'responsavel', 'notas', 'estado', 'esperados', 'valor_esperado', 'contado_em',
                'aplicado_em', 'fechado_em', 'fechado_por', 'criado_por', 'anulado_em', 'anulado_por', 'motivo_anulacao']] as $tabela => $colunas) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }
            Schema::table($tabela, function (Blueprint $table) use ($tabela, $colunas) {
                foreach ($colunas as $c) {
                    if (Schema::hasColumn($tabela, $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }

    private function fk(string $tabela, string $coluna, string $alvo): void
    {
        $nome = "fk_{$tabela}_{$coluna}";
        if (! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', [$nome])) {
            DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT {$nome} FOREIGN KEY ({$coluna}) REFERENCES {$alvo} (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE");
        }
    }

    private function check(string $tabela, string $nome, string $condicao, bool $naoValidar = false): void
    {
        if (! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', [$nome])) {
            DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT {$nome} CHECK ({$condicao})".($naoValidar ? ' NOT VALID' : ''));
        }
    }
};
