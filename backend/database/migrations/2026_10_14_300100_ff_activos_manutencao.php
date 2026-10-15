<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase F (Versão Actual, ADR-071) — manutenção planeada (js/activos_manutencao.js, Dexie v1277).
 *   - requisicoes_manutencao / linhas_requisicao_manutencao (maint_requisitions / maint_requisition_lines) foram geradas
 *     só com empresa_id (0 linhas reais): acrescentam-se as colunas que o legado grava (:461-471, :567-595, :618).
 *   - itens_ordem_manutencao: o legado escreve também requisicao_id, purchase_request_id, qtd_armazem e qtd_compra
 *     (:505-511), que não vieram no backup (sem valores). Ficam aqui: é esta a ligação da necessidade ao pedido de
 *     compra — a tabela pedidos_compra (Compras) não é alterada.
 * Migração aditiva, com guardas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('requisicoes_manutencao')) {
            Schema::table('requisicoes_manutencao', function (Blueprint $table) {
                $colunas = [
                    'numero' => fn () => $table->string('numero', 20)->nullable()->comment('legado: numero (RQ AAAA/NNNN)'),
                    'ordem_manutencao_id' => fn () => $table->bigInteger('ordem_manutencao_id')->nullable()->comment('legado: order_id'),
                    'ativo_imobilizado_id' => fn () => $table->bigInteger('ativo_imobilizado_id')->nullable()->comment('legado: asset_id'),
                    'armazem_id' => fn () => $table->bigInteger('armazem_id')->nullable()->comment('legado: warehouse_id'),
                    'requerente' => fn () => $table->string('requerente', 150)->nullable()->comment('legado: requerente'),
                    'data' => fn () => $table->date('data')->nullable()->comment('legado: data'),
                    'estado' => fn () => $table->string('estado', 12)->nullable()->comment('legado: estado (PENDENTE|PARCIAL|SATISFEITA|CANCELADA)'),
                    'satisfeita_em' => fn () => $table->timestampTz('satisfeita_em')->nullable()->comment('legado: satisfeita_em'),
                    'motivo_cancelamento' => fn () => $table->text('motivo_cancelamento')->nullable()->comment('legado: motivo_cancelamento'),
                    'cancelado_em' => fn () => $table->timestampTz('cancelado_em')->nullable()->comment('legado: cancelado_em'),
                    'cancelado_por' => fn () => $table->string('cancelado_por', 100)->nullable()->comment('legado: cancelado_por'),
                    'criado_por' => fn () => $table->string('criado_por', 100)->nullable()->comment('legado: criado_por'),
                ];
                foreach ($colunas as $nome => $criar) {
                    if (! Schema::hasColumn('requisicoes_manutencao', $nome)) {
                        $criar();
                    }
                }
            });
            DB::statement('CREATE INDEX IF NOT EXISTS ix_requisicoes_manutencao_ordem ON requisicoes_manutencao (ordem_manutencao_id)');
            $this->fk('requisicoes_manutencao', 'ordem_manutencao_id', 'ordens_manutencao_ativos');
            $this->fk('requisicoes_manutencao', 'ativo_imobilizado_id', 'ativos_imobilizados');
            $this->fk('requisicoes_manutencao', 'armazem_id', 'armazens');
            $this->check('requisicoes_manutencao', 'ck_requisicoes_manutencao_estado', "estado IS NULL OR estado IN ('PENDENTE','PARCIAL','SATISFEITA','CANCELADA')");
        }

        if (Schema::hasTable('linhas_requisicao_manutencao')) {
            Schema::table('linhas_requisicao_manutencao', function (Blueprint $table) {
                $colunas = [
                    'requisicao_manutencao_id' => fn () => $table->bigInteger('requisicao_manutencao_id')->nullable()->comment('legado: requisition_id'),
                    'item_ordem_manutencao_id' => fn () => $table->bigInteger('item_ordem_manutencao_id')->nullable()->comment('legado: order_item_id'),
                    'produto_id' => fn () => $table->bigInteger('produto_id')->nullable()->comment('legado: product_id'),
                    'descricao' => fn () => $table->text('descricao')->nullable()->comment('legado: descricao'),
                    'quantidade' => fn () => $table->decimal('quantidade', 15, 3)->nullable()->comment('legado: quantidade'),
                    'quantidade_entregue' => fn () => $table->decimal('quantidade_entregue', 15, 3)->nullable()->comment('legado: quantidade_entregue'),
                    'estado' => fn () => $table->string('estado', 12)->nullable()->comment('legado: estado (PENDENTE|PARCIAL|ENTREGUE|CANCELADA)'),
                    'custo' => fn () => $table->decimal('custo', 15, 2)->nullable()->comment('legado: custo'),
                ];
                foreach ($colunas as $nome => $criar) {
                    if (! Schema::hasColumn('linhas_requisicao_manutencao', $nome)) {
                        $criar();
                    }
                }
            });
            DB::statement('CREATE INDEX IF NOT EXISTS ix_linhas_requisicao_manutencao_requisicao ON linhas_requisicao_manutencao (requisicao_manutencao_id)');
            $this->fk('linhas_requisicao_manutencao', 'requisicao_manutencao_id', 'requisicoes_manutencao');
            $this->fk('linhas_requisicao_manutencao', 'item_ordem_manutencao_id', 'itens_ordem_manutencao');
            $this->fk('linhas_requisicao_manutencao', 'produto_id', 'produtos');
            $this->check('linhas_requisicao_manutencao', 'ck_linhas_requisicao_manutencao_estado', "estado IS NULL OR estado IN ('PENDENTE','PARCIAL','ENTREGUE','CANCELADA')");
        }

        if (Schema::hasTable('itens_ordem_manutencao')) {
            Schema::table('itens_ordem_manutencao', function (Blueprint $table) {
                $colunas = [
                    'requisicao_manutencao_id' => fn () => $table->bigInteger('requisicao_manutencao_id')->nullable()->comment('legado: requisicao_id'),
                    'pedido_compra_id' => fn () => $table->bigInteger('pedido_compra_id')->nullable()->comment('legado: purchase_request_id'),
                    'quantidade_armazem' => fn () => $table->decimal('quantidade_armazem', 15, 3)->nullable()->comment('legado: qtd_armazem'),
                    'quantidade_compra' => fn () => $table->decimal('quantidade_compra', 15, 3)->nullable()->comment('legado: qtd_compra'),
                ];
                foreach ($colunas as $nome => $criar) {
                    if (! Schema::hasColumn('itens_ordem_manutencao', $nome)) {
                        $criar();
                    }
                }
            });
            DB::statement('CREATE INDEX IF NOT EXISTS ix_itens_ordem_manutencao_pedido_compra ON itens_ordem_manutencao (pedido_compra_id)');
            $this->fk('itens_ordem_manutencao', 'requisicao_manutencao_id', 'requisicoes_manutencao');
            $this->fk('itens_ordem_manutencao', 'pedido_compra_id', 'pedidos_compra');
        }
    }

    public function down(): void
    {
        foreach (['itens_ordem_manutencao' => ['requisicao_manutencao_id', 'pedido_compra_id', 'quantidade_armazem', 'quantidade_compra'],
            'linhas_requisicao_manutencao' => ['requisicao_manutencao_id', 'item_ordem_manutencao_id', 'produto_id', 'descricao', 'quantidade', 'quantidade_entregue', 'estado', 'custo'],
            'requisicoes_manutencao' => ['numero', 'ordem_manutencao_id', 'ativo_imobilizado_id', 'armazem_id', 'requerente', 'data', 'estado', 'satisfeita_em',
                'motivo_cancelamento', 'cancelado_em', 'cancelado_por', 'criado_por']] as $tabela => $colunas) {
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
        if (Schema::hasTable($alvo) && ! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', [$nome])) {
            DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT {$nome} FOREIGN KEY ({$coluna}) REFERENCES {$alvo} (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE");
        }
    }

    private function check(string $tabela, string $nome, string $condicao): void
    {
        if (! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', [$nome])) {
            DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT {$nome} CHECK ({$condicao})");
        }
    }
};
