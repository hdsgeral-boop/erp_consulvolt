<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestão Imobiliária, Onda 5 (F6): compra e venda de imóveis e fracções — negócios (fases, impostos da transmissão, aprovação, conclusão),
 * encargos, pagamentos (sinal e prestações) e trilho. Tabelas NOVAS, aditivas e idempotentes (`hasTable`, `CREATE … IF NOT EXISTS`, CHECK por
 * `pg_constraint`), RLS por empresa (IsolamentoRls) e GRANT a erp_app. `down()` não remove nada.
 *
 * ALTERs a tabelas existentes (TODOS aditivos, nuláveis e idempotentes, com `ADD COLUMN IF NOT EXISTS`):
 *  - `configuracao_imobiliario`: `produto_venda_imovel_id` (artigo de serviço da factura da venda, FK ao catálogo) e as contas da compra e
 *    venda (`conta_mais_valia`, `conta_menos_valia`, `conta_compra_contrapartida`), que cada empresa escolhe (nada pré-preenchido);
 *  - `imoveis`: `data_alienacao` (a data da escritura que aliena o último bocado do imóvel; o legado já a gravava).
 */
return new class extends Migration
{
    private const TABELAS = ['negocios_imobiliarios', 'custos_negocio_imo', 'pagamentos_negocio_imo', 'historico_negocio_imo'];

    public function up(): void
    {
        $this->negocios();
        $this->custos();
        $this->pagamentos();
        $this->historico();
        $this->alteracoes();

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

    private function negocios(): void
    {
        if (! Schema::hasTable('negocios_imobiliarios')) {
            Schema::create('negocios_imobiliarios', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('numero', 20);
                $t->string('tipo', 6);
                $t->string('estado', 12)->default('NEGOCIACAO');
                $t->unsignedBigInteger('imovel_id')->nullable();
                $t->unsignedBigInteger('fracao_id')->nullable();
                $t->unsignedBigInteger('terceiro_id');
                $t->decimal('preco', 18, 2);
                $t->decimal('vpt', 18, 2)->default(0);
                $t->decimal('sinal', 18, 2)->default(0);
                $t->string('finalidade', 12)->default('HABITACAO');
                $t->string('isencao', 6)->default('NAO');
                $t->date('data_promessa')->nullable();
                $t->date('data_escritura')->nullable();
                $t->date('data_registo')->nullable();
                $t->string('notario', 150)->nullable();
                $t->decimal('base', 18, 2)->default(0);
                $t->boolean('base_e_vpt')->default(false);
                $t->decimal('ip_taxa', 9, 4)->default(0);
                $t->decimal('ip_valor', 18, 2)->default(0);
                $t->decimal('selo_pct', 9, 4)->default(0);
                $t->decimal('selo_valor', 18, 2)->default(0);
                $t->decimal('escritura_valor', 18, 2)->default(0);
                $t->decimal('total_impostos', 18, 2)->default(0);
                $t->smallInteger('impostos_ano')->nullable();
                $t->date('impostos_fixados_em')->nullable();
                $t->string('aprovado_por', 100)->nullable();
                $t->timestampTz('aprovado_em')->nullable();
                $t->string('motivo_cancelamento', 300)->nullable();
                $t->timestampTz('cancelado_em')->nullable();
                $t->string('contratos_destino', 10)->nullable();
                $t->timestampTz('contratos_tratados_em')->nullable();
                $t->date('concluido_em')->nullable();
                $t->decimal('custo_aquisicao', 18, 2)->nullable();
                $t->unsignedBigInteger('ativo_id')->nullable();
                $t->decimal('valor_contabilistico', 18, 2)->nullable();
                $t->decimal('mais_valia', 18, 2)->nullable();
                $t->decimal('resultado_contabilistico', 18, 2)->nullable();
                $t->decimal('quota', 9, 6)->nullable();
                $t->boolean('abate_parcial')->default(false);
                $t->unsignedBigInteger('venda_id')->nullable();
                $t->unsignedBigInteger('abate_id')->nullable();
                $t->string('lancamento_compra', 40)->nullable();
                $t->string('lancamento_venda', 40)->nullable();
                $t->timestampTz('abatido_em')->nullable();
                $t->text('notas')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_negocios_imo_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('imovel_id', 'fk_negocios_imo_imovel')->references('id')->on('imoveis')->restrictOnDelete();
                $t->foreign('fracao_id', 'fk_negocios_imo_fracao')->references('id')->on('fracoes')->restrictOnDelete();
                $t->foreign('terceiro_id', 'fk_negocios_imo_terceiro')->references('id')->on('terceiros')->restrictOnDelete();
                $t->foreign('ativo_id', 'fk_negocios_imo_ativo')->references('id')->on('ativos_imobilizados')->nullOnDelete();
                $t->foreign('venda_id', 'fk_negocios_imo_venda')->references('id')->on('vendas')->nullOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_negocios_imo_numero ON negocios_imobiliarios (empresa_id, lower(numero))');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_negocios_imo_venda ON negocios_imobiliarios (venda_id) WHERE venda_id IS NOT NULL');
        // um activo nasce de uma só compra; e um só negócio aberto do mesmo tipo por imóvel ou fracção (o legado deixava vários)
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_negocios_imo_compra_ativo ON negocios_imobiliarios (empresa_id, ativo_id) WHERE tipo = 'COMPRA' AND ativo_id IS NOT NULL");
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_negocios_imo_aberto ON negocios_imobiliarios (empresa_id, tipo, coalesce(imovel_id, 0), coalesce(fracao_id, 0)) '
            ."WHERE estado NOT IN ('CONCLUIDO','CANCELADO') AND (imovel_id IS NOT NULL OR fracao_id IS NOT NULL)");
        DB::statement('CREATE INDEX IF NOT EXISTS ix_negocios_imo_tipo_estado ON negocios_imobiliarios (empresa_id, tipo, estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_negocios_imo_imovel ON negocios_imobiliarios (empresa_id, imovel_id)');
        $this->restricao('negocios_imobiliarios', 'ck_negocios_imo_enums', "tipo IN ('COMPRA','VENDA') AND estado IN ('NEGOCIACAO','PROMESSA','ESCRITURA','REGISTADO','CONCLUIDO','CANCELADO') "
            ."AND finalidade IN ('HABITACAO','COMERCIO','ESCRITORIO','ARMAZEM','TERRENO','OUTRO') AND isencao IN ('NAO','METADE','TOTAL') "
            ."AND (contratos_destino IS NULL OR contratos_destino IN ('TRANSMITE','MANTEM'))");
        $this->restricao('negocios_imobiliarios', 'ck_negocios_imo_valores', 'preco > 0 AND vpt >= 0 AND sinal >= 0 AND sinal <= preco AND ip_valor >= 0 AND selo_valor >= 0 AND escritura_valor >= 0 AND total_impostos >= 0');
        $this->restricao('negocios_imobiliarios', 'ck_negocios_imo_datas', '(data_escritura IS NULL OR data_promessa IS NULL OR data_escritura >= data_promessa) AND (data_registo IS NULL OR data_escritura IS NULL OR data_registo >= data_escritura)');
        $this->restricao('negocios_imobiliarios', 'ck_negocios_imo_alvo', 'imovel_id IS NOT NULL OR fracao_id IS NOT NULL OR tipo = \'COMPRA\'');
        $this->restricao('negocios_imobiliarios', 'ck_negocios_imo_quota', 'quota IS NULL OR (quota > 0 AND quota <= 1)');
    }

    private function custos(): void
    {
        if (! Schema::hasTable('custos_negocio_imo')) {
            Schema::create('custos_negocio_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('negocio_id');
                $t->string('tipo', 12)->default('OUTRO');
                $t->string('designacao', 200);
                $t->decimal('valor', 18, 2);
                $t->boolean('capitaliza')->default(false);
                $t->boolean('pago')->default(false);
                $t->date('data');
                $t->smallInteger('ordem')->default(0);
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_custos_negocio_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('negocio_id', 'fk_custos_negocio_negocio')->references('id')->on('negocios_imobiliarios')->cascadeOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_custos_negocio ON custos_negocio_imo (negocio_id, ordem)');
        $this->restricao('custos_negocio_imo', 'ck_custos_negocio_valores', "valor > 0 AND designacao <> '' AND tipo IN ('IMPOSTO','SELO','ESCRITURA','REGISTO','MEDIACAO','AVALIACAO','ADVOGADO','OUTRO')");
    }

    private function pagamentos(): void
    {
        if (! Schema::hasTable('pagamentos_negocio_imo')) {
            Schema::create('pagamentos_negocio_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('negocio_id');
                $t->string('tipo', 10);
                $t->decimal('valor', 18, 2);
                $t->date('data');
                $t->string('meio', 20)->nullable();
                $t->string('referencia', 120)->nullable();
                $t->string('estado', 8)->default('VALIDO');
                $t->string('chave', 80);
                $t->string('anulado_motivo', 300)->nullable();
                $t->string('anulado_por', 100)->nullable();
                $t->timestampTz('anulado_em')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_pag_negocio_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('negocio_id', 'fk_pag_negocio_negocio')->references('id')->on('negocios_imobiliarios')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_pag_negocio_chave ON pagamentos_negocio_imo (empresa_id, chave)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_pag_negocio ON pagamentos_negocio_imo (negocio_id, data)');
        $this->restricao('pagamentos_negocio_imo', 'ck_pag_negocio_valores', "valor > 0 AND tipo IN ('SINAL','PRESTACAO','SALDO') AND estado IN ('VALIDO','ANULADO')");
    }

    private function historico(): void
    {
        if (! Schema::hasTable('historico_negocio_imo')) {
            Schema::create('historico_negocio_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('negocio_id');
                $t->string('evento', 20);
                $t->string('estado_de', 12)->nullable();
                $t->string('estado_para', 12)->nullable();
                $t->string('motivo', 300)->nullable();
                $t->jsonb('dados')->nullable();
                $t->unsignedBigInteger('utilizador_id')->nullable();
                $t->timestampTz('em')->useCurrent();
                $t->foreign('empresa_id', 'fk_hist_negocio_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('negocio_id', 'fk_hist_negocio_negocio')->references('id')->on('negocios_imobiliarios')->cascadeOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_hist_negocio_em ON historico_negocio_imo (negocio_id, em)');
    }

    /** ALTERs aditivos e idempotentes (ver o cabeçalho). */
    private function alteracoes(): void
    {
        if (Schema::hasTable('configuracao_imobiliario')) {
            foreach (['produto_venda_imovel_id bigint', 'conta_mais_valia varchar(20)', 'conta_menos_valia varchar(20)', 'conta_compra_contrapartida varchar(20)'] as $col) {
                DB::statement("ALTER TABLE configuracao_imobiliario ADD COLUMN IF NOT EXISTS {$col} NULL");
            }
            DB::statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_cfgimo_produto_venda') THEN "
                .'ALTER TABLE configuracao_imobiliario ADD CONSTRAINT fk_cfgimo_produto_venda FOREIGN KEY (produto_venda_imovel_id) REFERENCES produtos (id) ON DELETE RESTRICT; END IF; END $$');
        }
        if (Schema::hasTable('imoveis')) {
            DB::statement('ALTER TABLE imoveis ADD COLUMN IF NOT EXISTS data_alienacao date NULL');
        }
    }

    public function down(): void
    {
        // Aditiva: não remove tabelas nem colunas com dados (política do projecto).
    }
};
