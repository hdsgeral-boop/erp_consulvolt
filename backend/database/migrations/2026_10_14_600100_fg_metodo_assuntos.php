<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase G (Versão Actual, ADR-071) — Método de Trabalho 5W1H (js/modules/metodo/assuntos.js, Dexie v1274).
 *   - assuntos_metodo_trabalho: o legado grava também purchase_request_id, concluido_em/por, cancelado_em/por e
 *     motivo_cancelamento (:313-314, :344), que não vieram no backup (sem valores). Ficam aqui, com a ligação ao pedido
 *     de compra do lado do assunto — a tabela pedidos_compra (Compras) não é alterada.
 *   - centro_custo_id e encomenda_venda_id: o erp_laravel exige, num projecto interno, unidade de negócio E centro de
 *     custo e, num externo, a nota de encomenda (NE) do cliente (ServicoProjetos::guardar). O legado criava o projecto
 *     sem eles; passam a pedir-se no assunto quando o método é «projecto novo».
 *   - linhas_pedido_compra: o pedido de compra do erp_laravel tem linhas com artigo (ServicoProcessoCompras::criarPedido);
 *     o legado criava-o só com o valor estimado.
 *   - fundamentacao/causa_raiz (varchar 150) e historico.detalhe (varchar 150) alargam-se: a fundamentação exige ≥ 50
 *     caracteres e o legado não tinha limite. Mantém-se a família (character varying) do contrato do esquema.
 * Migração aditiva, com guardas.
 */
return new class extends Migration
{
    private const COLUNAS = ['pedido_compra_id', 'centro_custo_id', 'encomenda_venda_id', 'linhas_pedido_compra', 'concluido_em', 'concluido_por',
        'cancelado_em', 'cancelado_por', 'motivo_cancelamento'];

    public function up(): void
    {
        if (Schema::hasTable('assuntos_metodo_trabalho')) {
            Schema::table('assuntos_metodo_trabalho', function (Blueprint $table) {
                $colunas = [
                    'pedido_compra_id' => fn () => $table->bigInteger('pedido_compra_id')->nullable()->comment('legado: purchase_request_id'),
                    'centro_custo_id' => fn () => $table->bigInteger('centro_custo_id')->nullable()->comment('novo: centro de custo do projecto interno'),
                    'encomenda_venda_id' => fn () => $table->bigInteger('encomenda_venda_id')->nullable()->comment('novo: NE do cliente para o projecto externo'),
                    'linhas_pedido_compra' => fn () => $table->jsonb('linhas_pedido_compra')->nullable()->comment('novo: artigos do pedido de compra [{produto_id, quantidade, preco_unitario, descricao}]'),
                    'concluido_em' => fn () => $table->timestampTz('concluido_em')->nullable()->comment('legado: concluido_em'),
                    'concluido_por' => fn () => $table->string('concluido_por', 100)->nullable()->comment('legado: concluido_por'),
                    'cancelado_em' => fn () => $table->timestampTz('cancelado_em')->nullable()->comment('legado: cancelado_em'),
                    'cancelado_por' => fn () => $table->string('cancelado_por', 100)->nullable()->comment('legado: cancelado_por'),
                    'motivo_cancelamento' => fn () => $table->text('motivo_cancelamento')->nullable()->comment('legado: motivo_cancelamento'),
                ];
                foreach ($colunas as $nome => $criar) {
                    if (! Schema::hasColumn('assuntos_metodo_trabalho', $nome)) {
                        $criar();
                    }
                }
            });
            DB::statement('CREATE INDEX IF NOT EXISTS ix_assuntos_metodo_trabalho_pedido ON assuntos_metodo_trabalho (pedido_compra_id)');
            $this->fk('assuntos_metodo_trabalho', 'pedido_compra_id', 'pedidos_compra');
            $this->fk('assuntos_metodo_trabalho', 'centro_custo_id', 'centros_custo');
            $this->fk('assuntos_metodo_trabalho', 'encomenda_venda_id', 'vendas');
            $this->alargar('assuntos_metodo_trabalho', 'fundamentacao', 4000);
            $this->alargar('assuntos_metodo_trabalho', 'causa_raiz', 4000);
        }
        if (Schema::hasTable('historico_assuntos_metodo')) {
            $this->alargar('historico_assuntos_metodo', 'detalhe', 1000);
            DB::statement('CREATE INDEX IF NOT EXISTS ix_historico_assuntos_metodo_assunto ON historico_assuntos_metodo (assunto_id)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('assuntos_metodo_trabalho')) {
            return;
        }
        foreach (['pedido_compra_id', 'centro_custo_id', 'encomenda_venda_id'] as $c) {
            DB::statement("ALTER TABLE assuntos_metodo_trabalho DROP CONSTRAINT IF EXISTS fk_assuntos_metodo_trabalho_{$c}");
        }
        DB::statement('DROP INDEX IF EXISTS ix_assuntos_metodo_trabalho_pedido');
        Schema::table('assuntos_metodo_trabalho', function (Blueprint $table) {
            foreach (self::COLUNAS as $c) {
                if (Schema::hasColumn('assuntos_metodo_trabalho', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        // os alargamentos de varchar não se desfazem (encurtar podia cortar texto já gravado)
    }

    private function fk(string $tabela, string $coluna, string $alvo): void
    {
        $nome = "fk_{$tabela}_{$coluna}";
        if (Schema::hasTable($alvo) && ! DB::selectOne('SELECT 1 AS existe FROM pg_constraint WHERE conname = ?', [$nome])) {
            DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT {$nome} FOREIGN KEY ({$coluna}) REFERENCES {$alvo} (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE");
        }
    }

    /** Alarga um varchar (só se for mais curto que $tamanho; nunca encurta). */
    private function alargar(string $tabela, string $coluna, int $tamanho): void
    {
        $atual = DB::selectOne("SELECT character_maximum_length AS t FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ? AND data_type = 'character varying'",
            [$tabela, $coluna]);
        if ($atual && $atual->t !== null && (int) $atual->t < $tamanho) {
            DB::statement("ALTER TABLE {$tabela} ALTER COLUMN {$coluna} TYPE varchar({$tamanho})");
        }
    }
};
