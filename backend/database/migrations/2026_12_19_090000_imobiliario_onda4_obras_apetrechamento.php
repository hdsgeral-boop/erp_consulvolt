<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestão Imobiliária, Onda 4 (F5): obras e apetrechamento — fichas-tipo, bens da fracção, ordens de trabalho com as suas necessidades e
 * trilho, e planos de manutenção e vistoria. Só tabelas NOVAS, aditivas e idempotentes (`hasTable`, `CREATE … IF NOT EXISTS`, CHECK por
 * `pg_constraint`); NENHUM ALTER a tabelas existentes: a ligação ao armazém (requisições de manutenção) e às Compras (pedidos e linhas)
 * guarda-se do lado das necessidades (`requisicao_manutencao_id`, `linha_requisicao_id`, `pedido_compra_id`, `linha_compra_id`, com
 * ON DELETE SET NULL), e a ordem aberta de uma vistoria de reserva guarda a vistoria na própria ordem. RLS por empresa
 * (IsolamentoRls) e GRANT a erp_app. `down()` não remove nada.
 */
return new class extends Migration
{
    private const TABELAS = ['fichas_apetrechamento', 'itens_ficha_apetrechamento', 'bens_fracao', 'planos_manutencao_imo', 'ordens_trabalho_imo', 'necessidades_ordem_imo', 'historico_ordem_imo'];

    public function up(): void
    {
        $this->fichas();
        $this->bens();
        $this->planos();
        $this->ordens();
        $this->necessidades();
        $this->historico();

        foreach (self::TABELAS as $tabela) {
            DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
                ."GRANT SELECT, INSERT, UPDATE, DELETE ON {$tabela} TO erp_app; "
                ."GRANT USAGE, SELECT ON SEQUENCE {$tabela}_id_seq TO erp_app; END IF; END \$\$");
        }

        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    private function restricao(string $tabela, string $nome, string $condicao): void
    {
        DB::statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = '{$nome}') THEN "
            ."ALTER TABLE {$tabela} ADD CONSTRAINT {$nome} CHECK ({$condicao}); END IF; END \$\$");
    }

    private function fichas(): void
    {
        if (! Schema::hasTable('fichas_apetrechamento')) {
            Schema::create('fichas_apetrechamento', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('nome', 120);
                $t->string('tipologia', 20)->nullable();
                $t->string('finalidade', 20)->nullable();
                $t->boolean('activo')->default(true);
                $t->text('notas')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_fichas_apet_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_fichas_apet_nome ON fichas_apetrechamento (empresa_id, lower(nome))');

        if (! Schema::hasTable('itens_ficha_apetrechamento')) {
            Schema::create('itens_ficha_apetrechamento', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('ficha_id');
                $t->unsignedBigInteger('produto_id')->nullable();
                $t->string('descricao', 200)->nullable();
                $t->integer('quantidade')->default(1);
                $t->boolean('obrigatorio')->default(true);
                $t->string('notas', 300)->nullable();
                $t->smallInteger('ordem')->default(0);
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_itens_ficha_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('ficha_id', 'fk_itens_ficha_ficha')->references('id')->on('fichas_apetrechamento')->cascadeOnDelete();
                $t->foreign('produto_id', 'fk_itens_ficha_produto')->references('id')->on('produtos')->restrictOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_itens_ficha_ficha ON itens_ficha_apetrechamento (ficha_id, ordem)');
        $this->restricao('itens_ficha_apetrechamento', 'ck_itens_ficha_valores', "quantidade >= 1 AND (produto_id IS NOT NULL OR (descricao IS NOT NULL AND descricao <> ''))");
    }

    private function bens(): void
    {
        if (! Schema::hasTable('bens_fracao')) {
            Schema::create('bens_fracao', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('fracao_id');
                $t->unsignedBigInteger('produto_id')->nullable();
                $t->string('descricao', 200)->nullable();
                $t->decimal('quantidade', 12, 3)->default(1);
                $t->unsignedBigInteger('ativo_id')->nullable();
                $t->string('estado', 12)->default('BOM');
                $t->string('origem', 12)->default('MANUAL');
                $t->date('data');
                $t->string('notas', 300)->nullable();
                $t->unsignedBigInteger('necessidade_id')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_bens_fracao_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('fracao_id', 'fk_bens_fracao_fracao')->references('id')->on('fracoes')->cascadeOnDelete();
                $t->foreign('produto_id', 'fk_bens_fracao_produto')->references('id')->on('produtos')->restrictOnDelete();
                $t->foreign('ativo_id', 'fk_bens_fracao_ativo')->references('id')->on('ativos_imobilizados')->nullOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_bens_fracao_fracao ON bens_fracao (empresa_id, fracao_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_bens_fracao_ativo ON bens_fracao (fracao_id, ativo_id) WHERE ativo_id IS NOT NULL');
        $this->restricao('bens_fracao', 'ck_bens_fracao_valores', "quantidade > 0 AND estado IN ('NOVO','BOM','USADO','DANIFICADO','EM_FALTA') AND origem IN ('MANUAL','INVENTARIO','COMPRA','ARMAZEM')"
            ."  AND (produto_id IS NOT NULL OR (descricao IS NOT NULL AND descricao <> ''))");
    }

    private function planos(): void
    {
        if (! Schema::hasTable('planos_manutencao_imo')) {
            Schema::create('planos_manutencao_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('nome', 120);
                $t->string('tipo', 12)->default('PREVENTIVA');
                $t->text('descricao');
                $t->unsignedBigInteger('imovel_id');
                $t->unsignedBigInteger('fracao_id')->nullable();
                $t->string('periodicidade', 12)->default('ANUAL');
                $t->date('primeira_data')->nullable();
                $t->smallInteger('quantas')->nullable();
                $t->jsonb('datas')->default('[]');
                $t->string('prioridade', 8)->default('NORMAL');
                $t->string('quem_paga', 10)->default('SENHORIO');
                $t->boolean('debitavel')->default(false);
                $t->string('responsavel', 120)->nullable();
                $t->integer('antecedencia_dias')->default(30);
                $t->decimal('custo_estimado', 18, 2)->nullable();
                $t->text('notas')->nullable();
                $t->boolean('activo')->default(true);
                $t->date('proxima')->nullable();
                $t->date('ultima_geracao')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_planos_imo_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('imovel_id', 'fk_planos_imo_imovel')->references('id')->on('imoveis')->restrictOnDelete();
                $t->foreign('fracao_id', 'fk_planos_imo_fracao')->references('id')->on('fracoes')->restrictOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_planos_imo_empresa ON planos_manutencao_imo (empresa_id, activo)');
        $this->restricao('planos_manutencao_imo', 'ck_planos_imo_valores', "tipo IN ('PREVENTIVA','VISTORIA') AND periodicidade IN ('MENSAL','BIMESTRAL','TRIMESTRAL','SEMESTRAL','ANUAL','BIANUAL','MANUAL')"
            ."  AND prioridade IN ('BAIXA','NORMAL','ALTA','URGENTE') AND quem_paga IN ('SENHORIO','INQUILINO','PARTILHADO') AND antecedencia_dias >= 0"
            .'  AND (custo_estimado IS NULL OR custo_estimado >= 0) AND (debitavel = false OR quem_paga <> \'SENHORIO\')');
    }

    private function ordens(): void
    {
        if (! Schema::hasTable('ordens_trabalho_imo')) {
            Schema::create('ordens_trabalho_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('numero', 20);
                $t->string('tipo', 14)->default('CORRECTIVA');
                $t->text('descricao');
                $t->unsignedBigInteger('imovel_id');
                $t->unsignedBigInteger('fracao_id')->nullable();
                $t->string('prioridade', 8)->default('NORMAL');
                $t->string('quem_paga', 10)->default('SENHORIO');
                $t->boolean('debitavel')->default(false);
                $t->date('data_pedido');
                $t->date('data_prevista')->nullable();
                $t->date('data_conclusao')->nullable();
                $t->string('responsavel', 120)->nullable();
                $t->string('pedido_por', 120)->nullable();
                $t->decimal('custo_estimado', 18, 2)->nullable();
                $t->unsignedBigInteger('ficha_id')->nullable();
                $t->unsignedBigInteger('plano_id')->nullable();
                $t->date('plano_data')->nullable();
                $t->unsignedBigInteger('vistoria_reserva_id')->nullable();
                $t->string('estado', 16)->default('ABERTA');
                $t->string('motivo_cancelamento', 300)->nullable();
                $t->string('aprovada_por', 100)->nullable();
                $t->timestampTz('aprovada_em')->nullable();
                $t->timestampTz('debitado_em')->nullable();
                $t->unsignedBigInteger('debitado_renda_id')->nullable();
                $t->decimal('debitado_valor', 18, 2)->nullable();
                $t->text('notas')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_ordens_imo_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('imovel_id', 'fk_ordens_imo_imovel')->references('id')->on('imoveis')->restrictOnDelete();
                $t->foreign('fracao_id', 'fk_ordens_imo_fracao')->references('id')->on('fracoes')->restrictOnDelete();
                $t->foreign('ficha_id', 'fk_ordens_imo_ficha')->references('id')->on('fichas_apetrechamento')->nullOnDelete();
                $t->foreign('plano_id', 'fk_ordens_imo_plano')->references('id')->on('planos_manutencao_imo')->nullOnDelete();
                $t->foreign('vistoria_reserva_id', 'fk_ordens_imo_vistoria')->references('id')->on('vistorias_reserva')->nullOnDelete();
                $t->foreign('debitado_renda_id', 'fk_ordens_imo_renda')->references('id')->on('rendas_arrendamento')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_ordens_imo_numero ON ordens_trabalho_imo (empresa_id, lower(numero))');
        // nunca duas ordens para a mesma data do mesmo plano (o legado podia duplicar com dois cliques ou dois utilizadores)
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_ordens_imo_plano_data ON ordens_trabalho_imo (plano_id, plano_data) WHERE plano_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_ordens_imo_vistoria ON ordens_trabalho_imo (vistoria_reserva_id) WHERE vistoria_reserva_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_ordens_imo_estado ON ordens_trabalho_imo (empresa_id, estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_ordens_imo_fracao ON ordens_trabalho_imo (empresa_id, fracao_id)');
        $this->restricao('ordens_trabalho_imo', 'ck_ordens_imo_valores', "tipo IN ('APETRECHAMENTO','PREVENTIVA','CORRECTIVA','MELHORIA','VISTORIA') AND prioridade IN ('BAIXA','NORMAL','ALTA','URGENTE')"
            ."  AND quem_paga IN ('SENHORIO','INQUILINO','PARTILHADO') AND estado IN ('ABERTA','AGUARDA_MATERIAL','EM_CURSO','CONCLUIDA','CANCELADA')"
            .'  AND (custo_estimado IS NULL OR custo_estimado >= 0) AND (debitavel = false OR quem_paga <> \'SENHORIO\')'
            .'  AND (data_prevista IS NULL OR data_prevista >= data_pedido) AND (debitado_em IS NULL OR debitado_renda_id IS NOT NULL)');
    }

    private function necessidades(): void
    {
        if (! Schema::hasTable('necessidades_ordem_imo')) {
            Schema::create('necessidades_ordem_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('ordem_id');
                $t->unsignedBigInteger('produto_id')->nullable();
                $t->string('descricao', 200)->nullable();
                $t->decimal('quantidade', 12, 3);
                $t->string('estado', 14)->default('POR_PEDIR');
                $t->decimal('quantidade_recebida', 12, 3)->default(0);
                $t->decimal('quantidade_armazem', 12, 3)->default(0);
                $t->decimal('quantidade_compra', 12, 3)->default(0);
                $t->decimal('quantidade_apetrechada', 12, 3)->default(0);
                $t->unsignedBigInteger('requisicao_manutencao_id')->nullable();
                $t->unsignedBigInteger('linha_requisicao_id')->nullable();
                $t->unsignedBigInteger('pedido_compra_id')->nullable();
                $t->unsignedBigInteger('linha_compra_id')->nullable();
                $t->smallInteger('ordem')->default(0);
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_neces_imo_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('ordem_id', 'fk_neces_imo_ordem')->references('id')->on('ordens_trabalho_imo')->cascadeOnDelete();
                $t->foreign('produto_id', 'fk_neces_imo_produto')->references('id')->on('produtos')->restrictOnDelete();
                $t->foreign('requisicao_manutencao_id', 'fk_neces_imo_requisicao')->references('id')->on('requisicoes_manutencao')->nullOnDelete();
                $t->foreign('linha_requisicao_id', 'fk_neces_imo_linha_req')->references('id')->on('linhas_requisicao_manutencao')->nullOnDelete();
                $t->foreign('pedido_compra_id', 'fk_neces_imo_pedido')->references('id')->on('pedidos_compra')->nullOnDelete();
                $t->foreign('linha_compra_id', 'fk_neces_imo_linha_compra')->references('id')->on('itens_compra')->nullOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_neces_imo_ordem ON necessidades_ordem_imo (ordem_id, ordem)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_neces_imo_pedido ON necessidades_ordem_imo (pedido_compra_id) WHERE pedido_compra_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_neces_imo_requisicao ON necessidades_ordem_imo (requisicao_manutencao_id) WHERE requisicao_manutencao_id IS NOT NULL');
        $this->restricao('necessidades_ordem_imo', 'ck_neces_imo_valores', "quantidade > 0 AND estado IN ('POR_PEDIR','REQUISITADO','PEDIDO_COMPRA','RECEBIDO','CANCELADO')"
            .'  AND quantidade_recebida >= 0 AND quantidade_armazem >= 0 AND quantidade_compra >= 0 AND quantidade_apetrechada >= 0'
            ."  AND (produto_id IS NOT NULL OR (descricao IS NOT NULL AND descricao <> ''))");
        // o bem que nasceu de uma recepção aponta para a necessidade (a tabela dos bens é anterior: a chave junta-se aqui)
        DB::statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_bens_fracao_necessidade') THEN "
            .'ALTER TABLE bens_fracao ADD CONSTRAINT fk_bens_fracao_necessidade FOREIGN KEY (necessidade_id) REFERENCES necessidades_ordem_imo (id) ON DELETE SET NULL; END IF; END $$');
    }

    private function historico(): void
    {
        if (! Schema::hasTable('historico_ordem_imo')) {
            Schema::create('historico_ordem_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('ordem_id');
                $t->string('evento', 20);
                $t->string('estado_de', 16)->nullable();
                $t->string('estado_para', 16)->nullable();
                $t->string('motivo', 300)->nullable();
                $t->jsonb('dados')->nullable();
                $t->unsignedBigInteger('utilizador_id')->nullable();
                $t->timestampTz('em')->useCurrent();
                $t->foreign('empresa_id', 'fk_hist_ordem_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('ordem_id', 'fk_hist_ordem_ordem')->references('id')->on('ordens_trabalho_imo')->cascadeOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_hist_ordem_em ON historico_ordem_imo (ordem_id, em)');
    }

    public function down(): void
    {
        // Aditiva: não remove tabelas com dados (política do projecto).
    }
};
