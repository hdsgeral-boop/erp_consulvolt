<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestão Imobiliária, Onda 3 (F4): parqueamento (veículos dos contratos) e áreas comuns (espaços, reservas, vistorias, trilho das
 * reservas e ledger da caução das reservas). As vagas NÃO têm tabela própria: são `fracoes` com natureza ESTACIONAMENTO (colunas
 * `park_*` criadas na Onda 1). Só tabelas NOVAS, aditivas e idempotentes (`hasTable`, `CREATE … IF NOT EXISTS`, CHECK com
 * `pg_constraint`); o único ALTER é aditivo e idempotente: `configuracao_imobiliario.produto_reserva_id` (o artigo de serviço da
 * facturação das reservas), com a sua chave estrangeira. Não exige `btree_gist`: o conflito de horários verifica-se no serviço com o
 * espaço bloqueado (FOR UPDATE) e a idempotência assenta em UNIQUE (venda_id, chave do movimento). RLS por empresa (IsolamentoRls)
 * e GRANT a erp_app. `down()` não remove nada.
 */
return new class extends Migration
{
    private const TABELAS = ['veiculos_contrato', 'espacos_comuns', 'reservas_espaco', 'vistorias_reserva', 'historico_reserva_imo', 'movimentos_caucao_reserva'];

    public function up(): void
    {
        $this->veiculos();
        $this->espacos();
        $this->reservas();
        $this->vistorias();
        $this->historico();
        $this->caucao();
        $this->configuracao();

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

    private function veiculos(): void
    {
        if (! Schema::hasTable('veiculos_contrato')) {
            Schema::create('veiculos_contrato', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('contrato_id');
                $t->string('matricula', 20);
                $t->string('marca', 60)->nullable();
                $t->string('modelo', 60)->nullable();
                $t->string('cor', 40)->nullable();
                $t->string('porte', 8)->nullable();
                $t->string('condutor', 120)->nullable();
                $t->string('distico', 60)->nullable();
                $t->unsignedBigInteger('vaga_id')->nullable();
                $t->smallInteger('ordem')->default(0);
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_veiculos_contrato_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('contrato_id', 'fk_veiculos_contrato_contrato')->references('id')->on('contratos_arrendamento')->cascadeOnDelete();
                $t->foreign('vaga_id', 'fk_veiculos_contrato_vaga')->references('id')->on('fracoes')->nullOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_veiculos_contrato_matricula ON veiculos_contrato (contrato_id, matricula)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_veiculos_contrato_matricula ON veiculos_contrato (empresa_id, matricula)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_veiculos_contrato_vaga ON veiculos_contrato (vaga_id) WHERE vaga_id IS NOT NULL');
        $this->restricao('veiculos_contrato', 'ck_veiculos_contrato_porte', "porte IS NULL OR porte IN ('MOTA','LIGEIRO','SUV','PESADO')");
        $this->restricao('veiculos_contrato', 'ck_veiculos_contrato_matricula', "matricula <> ''");
    }

    private function espacos(): void
    {
        if (! Schema::hasTable('espacos_comuns')) {
            Schema::create('espacos_comuns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('imovel_id');
                $t->string('tipo', 16)->default('OUTRO');
                $t->string('nome', 120);
                $t->string('localizacao', 150)->nullable();
                $t->string('regime', 18)->default('RESERVA_GRATUITA');
                $t->integer('capacidade')->nullable();
                $t->string('hora_abre', 5)->default('08:00');
                $t->string('hora_fecha', 5)->default('22:00');
                $t->jsonb('dias_semana')->default('[0,1,2,3,4,5,6]');
                $t->decimal('duracao_min', 6, 2)->nullable();
                $t->decimal('duracao_max', 6, 2)->nullable();
                $t->smallInteger('antecedencia_min_dias')->default(0);
                $t->smallInteger('antecedencia_max_dias')->default(0);
                $t->smallInteger('limite_mes_fraccao')->nullable();
                $t->boolean('exige_aprovacao')->default(false);
                $t->decimal('preco', 18, 2)->default(0);
                $t->string('preco_unidade', 8)->default('HORA');
                $t->decimal('caucao', 18, 2)->default(0);
                $t->smallInteger('prazo_pagamento_horas')->default(48);
                $t->smallInteger('cancel_dias')->default(7);
                $t->smallInteger('cancel_pct')->default(50);
                $t->text('regulamento')->nullable();
                $t->string('regulamento_url', 1000)->nullable();
                $t->boolean('activo')->default(true);
                $t->text('notas')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_espacos_comuns_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('imovel_id', 'fk_espacos_comuns_imovel')->references('id')->on('imoveis')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_espacos_comuns_nome ON espacos_comuns (empresa_id, imovel_id, lower(nome))');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_espacos_comuns_imovel ON espacos_comuns (empresa_id, imovel_id)');
        $this->restricao('espacos_comuns', 'ck_espacos_comuns_tipo', "tipo IN ('SALAO','GINASIO','PISCINA','CHURRASQUEIRA','REUNIOES','COWORKING','TERRACO','CAMPO','LAVANDARIA','PARQUE_INFANTIL','OUTRO')");
        $this->restricao('espacos_comuns', 'ck_espacos_comuns_regime', "regime IN ('GRATUITO','RESERVA_GRATUITA','RESERVA_PAGA')");
        $this->restricao('espacos_comuns', 'ck_espacos_comuns_unidade', "preco_unidade IN ('HORA','PERIODO','DIA','FIXO')");
        $this->restricao('espacos_comuns', 'ck_espacos_comuns_valores', 'preco >= 0 AND caucao >= 0 AND prazo_pagamento_horas >= 1 AND cancel_dias >= 0 AND cancel_pct BETWEEN 0 AND 100 '
            .'AND antecedencia_min_dias >= 0 AND antecedencia_max_dias >= 0 AND (capacidade IS NULL OR capacidade > 0) AND (limite_mes_fraccao IS NULL OR limite_mes_fraccao > 0) '
            .'AND (duracao_min IS NULL OR duracao_min > 0) AND (duracao_max IS NULL OR duracao_max > 0)');
        $this->restricao('espacos_comuns', 'ck_espacos_comuns_horas', "hora_abre ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$' AND hora_fecha ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$|^24:00$'");
        $this->restricao('espacos_comuns', 'ck_espacos_comuns_dias', "jsonb_typeof(dias_semana) = 'array'");
    }

    private function reservas(): void
    {
        if (! Schema::hasTable('reservas_espaco')) {
            Schema::create('reservas_espaco', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('espaco_id');
                $t->unsignedBigInteger('imovel_id');
                $t->unsignedBigInteger('fracao_id')->nullable();
                $t->unsignedBigInteger('contrato_id')->nullable();
                $t->unsignedBigInteger('terceiro_id')->nullable();
                $t->boolean('interna')->default(false);
                $t->date('data');
                $t->string('hora_inicio', 5);
                $t->string('hora_fim', 5);
                $t->integer('pessoas')->nullable();
                $t->string('motivo', 300)->nullable();
                $t->string('contacto', 100)->nullable();
                $t->string('estado', 18)->default('PEDIDA');
                $t->timestampTz('estado_em')->nullable();
                $t->string('estado_por', 100)->nullable();
                $t->string('motivo_estado', 300)->nullable();
                $t->timestampTz('realizada_em')->nullable();
                $t->decimal('valor', 18, 2)->default(0);
                $t->string('valor_detalhe', 200)->nullable();
                $t->decimal('caucao', 18, 2)->default(0);
                $t->decimal('caucao_recebida', 18, 2)->default(0);
                $t->decimal('caucao_devolvida', 18, 2)->default(0);
                $t->decimal('caucao_retida', 18, 2)->default(0);
                $t->string('caucao_motivo', 300)->nullable();
                $t->decimal('divida_na_marcacao', 18, 2)->default(0);
                $t->string('autorizacao_motivo', 300)->nullable();
                $t->string('autorizacao_por', 100)->nullable();
                $t->timestampTz('autorizacao_em')->nullable();
                $t->timestampTz('prazo_pagamento')->nullable();
                $t->boolean('caducada')->default(false);
                $t->unsignedBigInteger('venda_id')->nullable();
                $t->unsignedBigInteger('nota_credito_id')->nullable();
                $t->decimal('reembolso_previsto', 18, 2)->nullable();
                $t->decimal('reembolso_retido', 18, 2)->nullable();
                $t->smallInteger('reembolso_pct')->nullable();
                $t->boolean('nc_pendente')->default(false);
                $t->text('notas')->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->string('atualizado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_reservas_espaco_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('espaco_id', 'fk_reservas_espaco_espaco')->references('id')->on('espacos_comuns')->restrictOnDelete();
                $t->foreign('imovel_id', 'fk_reservas_espaco_imovel')->references('id')->on('imoveis')->restrictOnDelete();
                $t->foreign('fracao_id', 'fk_reservas_espaco_fracao')->references('id')->on('fracoes')->restrictOnDelete();
                $t->foreign('contrato_id', 'fk_reservas_espaco_contrato')->references('id')->on('contratos_arrendamento')->restrictOnDelete();
                $t->foreign('terceiro_id', 'fk_reservas_espaco_terceiro')->references('id')->on('terceiros')->restrictOnDelete();
                $t->foreign('venda_id', 'fk_reservas_espaco_venda')->references('id')->on('vendas')->restrictOnDelete();
                $t->foreign('nota_credito_id', 'fk_reservas_espaco_nc')->references('id')->on('vendas')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_reservas_espaco_venda ON reservas_espaco (venda_id) WHERE venda_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_reservas_espaco_dia ON reservas_espaco (espaco_id, data)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_reservas_espaco_estado ON reservas_espaco (empresa_id, estado)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_reservas_espaco_fracao ON reservas_espaco (fracao_id, data) WHERE fracao_id IS NOT NULL');
        DB::statement("CREATE INDEX IF NOT EXISTS ix_reservas_espaco_prazo ON reservas_espaco (prazo_pagamento) WHERE estado = 'AGUARDA_PAGAMENTO'");
        $this->restricao('reservas_espaco', 'ck_reservas_espaco_estado', "estado IN ('PEDIDA','APROVADA','AGUARDA_PAGAMENTO','CONFIRMADA','REALIZADA','FALTOU','CANCELADA','RECUSADA')");
        $this->restricao('reservas_espaco', 'ck_reservas_espaco_horas', "hora_inicio ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$' AND hora_fim ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$|^24:00$' AND hora_fim > hora_inicio");
        $this->restricao('reservas_espaco', 'ck_reservas_espaco_valores', 'valor >= 0 AND caucao >= 0 AND caucao_recebida >= 0 AND caucao_devolvida >= 0 AND caucao_retida >= 0 AND divida_na_marcacao >= 0 '
            .'AND caucao_recebida <= caucao AND caucao_devolvida + caucao_retida <= caucao_recebida AND (pessoas IS NULL OR pessoas > 0)');
        $this->restricao('reservas_espaco', 'ck_reservas_espaco_interna', '(interna = (fracao_id IS NULL))');
        $this->restricao('reservas_espaco', 'ck_reservas_espaco_reembolso', '(reembolso_previsto IS NULL OR reembolso_previsto >= 0) AND (reembolso_retido IS NULL OR reembolso_retido >= 0) AND (reembolso_pct IS NULL OR reembolso_pct BETWEEN 0 AND 100)');
    }

    private function vistorias(): void
    {
        if (! Schema::hasTable('vistorias_reserva')) {
            Schema::create('vistorias_reserva', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('reserva_id');
                $t->string('quando', 6);
                $t->string('estado', 12)->default('BOM');
                $t->text('observacoes')->nullable();
                $t->string('url', 1000)->nullable();
                $t->date('data');
                $t->decimal('custo_danos', 18, 2)->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_vistorias_reserva_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('reserva_id', 'fk_vistorias_reserva_reserva')->references('id')->on('reservas_espaco')->cascadeOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_vistorias_reserva ON vistorias_reserva (reserva_id, quando, id)');
        $this->restricao('vistorias_reserva', 'ck_vistorias_reserva_valores', "quando IN ('ANTES','DEPOIS') AND estado IN ('BOM','SUJO','DANIFICADO','EM_FALTA') AND (custo_danos IS NULL OR custo_danos >= 0)");
    }

    private function historico(): void
    {
        if (! Schema::hasTable('historico_reserva_imo')) {
            Schema::create('historico_reserva_imo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('reserva_id');
                $t->string('evento', 20);
                $t->string('estado_de', 18)->nullable();
                $t->string('estado_para', 18)->nullable();
                $t->string('motivo', 300)->nullable();
                $t->jsonb('dados')->nullable();
                $t->unsignedBigInteger('utilizador_id')->nullable();
                $t->timestampTz('em')->useCurrent();
                $t->foreign('empresa_id', 'fk_hist_reserva_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('reserva_id', 'fk_hist_reserva_reserva')->references('id')->on('reservas_espaco')->cascadeOnDelete();
            });
        }
        DB::statement('CREATE INDEX IF NOT EXISTS ix_hist_reserva_em ON historico_reserva_imo (reserva_id, em)');
    }

    private function caucao(): void
    {
        if (! Schema::hasTable('movimentos_caucao_reserva')) {
            Schema::create('movimentos_caucao_reserva', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('reserva_id');
                $t->string('tipo', 10);
                $t->decimal('valor', 18, 2);
                $t->date('data');
                $t->string('conta_financeira', 20)->nullable();
                $t->string('motivo', 300)->nullable();
                $t->string('chave', 80);
                $t->string('estado', 8)->default('VALIDO');
                $t->string('lancamento_numero', 40)->nullable();
                $t->string('estorno_numero', 40)->nullable();
                $t->unsignedBigInteger('venda_id')->nullable();
                $t->string('anulado_por', 100)->nullable();
                $t->timestampTz('anulado_em')->nullable();
                $t->string('anulado_motivo', 300)->nullable();
                $t->string('criado_por', 100)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_mov_caucao_res_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('reserva_id', 'fk_mov_caucao_res_reserva')->references('id')->on('reservas_espaco')->restrictOnDelete();
                $t->foreign('venda_id', 'fk_mov_caucao_res_venda')->references('id')->on('vendas')->restrictOnDelete();
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_mov_caucao_res_chave ON movimentos_caucao_reserva (empresa_id, chave)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_mov_caucao_res_venda ON movimentos_caucao_reserva (venda_id) WHERE venda_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_mov_caucao_res_reserva ON movimentos_caucao_reserva (reserva_id, data)');
        $this->restricao('movimentos_caucao_reserva', 'ck_mov_caucao_res_tipo', "tipo IN ('RECEBIDA','DEVOLVIDA','RETIDA') AND estado IN ('VALIDO','ANULADO') AND valor > 0");
    }

    /** ALTER aditivo e idempotente: o artigo de serviço da facturação das reservas (cada empresa escolhe o seu). */
    private function configuracao(): void
    {
        if (! Schema::hasTable('configuracao_imobiliario')) {
            return;
        }
        DB::statement('ALTER TABLE configuracao_imobiliario ADD COLUMN IF NOT EXISTS produto_reserva_id bigint NULL');
        DB::statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_cfgimo_produto_reserva') THEN "
            .'ALTER TABLE configuracao_imobiliario ADD CONSTRAINT fk_cfgimo_produto_reserva FOREIGN KEY (produto_reserva_id) REFERENCES produtos (id) ON DELETE RESTRICT; END IF; END $$');
    }

    public function down(): void
    {
        // Aditiva: não remove tabelas nem colunas com dados (política do projecto).
    }
};
