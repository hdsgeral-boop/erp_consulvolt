<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 3 — produtos e categorias (aditiva, sem tocar em dados):
 *   1. contas contabilísticas na CATEGORIA (as mesmas colunas que o produto tinha): o produto passa a herdá-las por
 *      ContasProduto::de(); as colunas de conta do produto ficam (contas de recurso, nada se perde) até à conversão
 *      — comando `erp:produtos:converter-contas-categorias` (separado, idempotente, com --simular e registo);
 *   2. níveis de stock do produto: máximo, segurança e ponto de encomenda (o mínimo já existia);
 *   3. ficha técnica do produto: marca, modelo, dimensões, pesos, características (chave-valor) e observações;
 *   4. registo da conversão das contas dos produtos para categorias (um registo por produto convertido).
 */
return new class extends Migration
{
    private const CONTAS = ['codigo_conta' => 'Conta de proveitos (vendas 61 / serviços 62)', 'conta_custo' => 'Conta de custo (CMV, 71/75)',
        'conta_compra' => 'Conta de compras (21)', 'conta_inventario' => 'Conta de existências (22-27)', 'conta_iva_liquidado' => 'IVA liquidado (345)',
        'conta_iva_dedutivel' => 'IVA dedutível (345)', 'conta_quebra' => 'Quebras de inventário (custo, 7)', 'conta_sobra' => 'Sobras de inventário (proveito, 6)',
        'conta_ativo' => 'Activo imobilizado (classe 1)'];

    public function up(): void
    {
        Schema::table('categorias_produtos', function (Blueprint $table) {
            if (! Schema::hasColumn('categorias_produtos', 'descricao')) {
                $table->text('descricao')->nullable()->comment('Descrição/notas da categoria');
            }
            foreach (self::CONTAS as $coluna => $rotulo) {
                if (! Schema::hasColumn('categorias_produtos', $coluna)) {
                    $table->string($coluna, 20)->nullable()->comment($rotulo.' — herdada pelos produtos da categoria');
                }
            }
            if (! Schema::hasColumn('categorias_produtos', 'chave_conversao')) {
                $table->string('chave_conversao', 64)->nullable()->comment('Conversão das contas dos produtos: categoria de origem + combinação de contas (sha256)');
            }
        });

        Schema::table('produtos', function (Blueprint $table) {
            foreach (['stock_maximo' => 'Stock máximo (acima: alerta de excesso; alvo da reposição)', 'stock_seguranca' => 'Stock de segurança (abaixo: alerta crítico)',
                'ponto_encomenda' => 'Ponto de encomenda (posição de stock ≤ ponto: sugestão de reposição)'] as $coluna => $rotulo) {
                if (! Schema::hasColumn('produtos', $coluna)) {
                    $table->decimal($coluna, 12, 3)->nullable()->comment($rotulo);
                }
            }
            foreach (['marca' => 100, 'modelo' => 100] as $coluna => $tamanho) {
                if (! Schema::hasColumn('produtos', $coluna)) {
                    $table->string($coluna, $tamanho)->nullable()->comment('Ficha técnica: '.$coluna);
                }
            }
            foreach (['comprimento', 'largura', 'altura'] as $coluna) {
                if (! Schema::hasColumn('produtos', $coluna)) {
                    $table->decimal($coluna, 12, 3)->nullable()->comment("Ficha técnica: {$coluna} (na unidade de dimensão)");
                }
            }
            if (! Schema::hasColumn('produtos', 'unidade_dimensao')) {
                $table->string('unidade_dimensao', 5)->nullable()->comment('Ficha técnica: mm, cm ou m');
            }
            foreach (['peso_liquido', 'peso_bruto'] as $coluna) {
                if (! Schema::hasColumn('produtos', $coluna)) {
                    $table->decimal($coluna, 12, 3)->nullable()->comment("Ficha técnica: {$coluna} (kg)");
                }
            }
            if (! Schema::hasColumn('produtos', 'caracteristicas')) {
                $table->jsonb('caracteristicas')->nullable()->comment('Ficha técnica: composição/características [{chave, valor}]');
            }
            if (! Schema::hasColumn('produtos', 'observacoes_tecnicas')) {
                $table->text('observacoes_tecnicas')->nullable()->comment('Ficha técnica: observações');
            }
        });
        foreach (['stock_minimo', 'stock_maximo', 'stock_seguranca', 'ponto_encomenda'] as $coluna) {
            $nome = "ck_produtos_{$coluna}_nao_negativo";
            if (! $this->existeRestricao($nome)) {
                // NOT VALID: não verifica as linhas existentes (stock_minimo veio do legado), só as novas escritas
                DB::statement("ALTER TABLE produtos ADD CONSTRAINT {$nome} CHECK ({$coluna} IS NULL OR {$coluna} >= 0) NOT VALID");
            }
        }

        if (! Schema::hasTable('registos_conversao_contas_produtos')) {
            Schema::create('registos_conversao_contas_produtos', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('empresa_id');
                $table->bigInteger('produto_id')->comment('Produto convertido (um registo por produto: torna a conversão idempotente)');
                $table->bigInteger('categoria_anterior_id')->nullable();
                $table->bigInteger('categoria_nova_id');
                $table->boolean('categoria_criada')->default(false)->comment('A categoria foi criada pela conversão');
                $table->jsonb('contas')->comment('Contas do produto no momento da conversão (copiadas para a categoria)');
                $table->jsonb('avisos')->nullable()->comment('Contas inexistentes ou totalizadoras encontradas (copiadas sem alteração)');
                $table->string('executado_por', 100)->nullable();
                $table->timestampTz('criado_em')->nullable()->useCurrent();
                $table->timestampTz('atualizado_em')->nullable()->useCurrent();
                $table->foreign('empresa_id')->references('id')->on('empresas');
                $table->foreign('produto_id')->references('id')->on('produtos');
                $table->foreign('categoria_nova_id')->references('id')->on('categorias_produtos');
                $table->unique(['empresa_id', 'produto_id'], 'uq_registos_conversao_contas_produtos_produto');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registos_conversao_contas_produtos');
        foreach (['stock_minimo', 'stock_maximo', 'stock_seguranca', 'ponto_encomenda'] as $coluna) {
            DB::statement("ALTER TABLE produtos DROP CONSTRAINT IF EXISTS ck_produtos_{$coluna}_nao_negativo");
        }
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn(['stock_maximo', 'stock_seguranca', 'ponto_encomenda', 'marca', 'modelo', 'comprimento', 'largura', 'altura', 'unidade_dimensao',
                'peso_liquido', 'peso_bruto', 'caracteristicas', 'observacoes_tecnicas']);
        });
        Schema::table('categorias_produtos', function (Blueprint $table) {
            $table->dropColumn(array_merge(['descricao', 'chave_conversao'], array_keys(self::CONTAS)));
        });
    }

    private function existeRestricao(string $nome): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM pg_constraint WHERE conname = ?', [$nome]) !== null;
    }
};
