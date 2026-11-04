<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assistente IA do ERP (Bloco B da integração da produção, ADR-081): chat com histórico e anexos, que consulta o ERP,
 * propõe acções (o utilizador confirma) e pesquisa na internet. As chaves dos fornecedores (Anthropic, OpenAI, Google
 * Gemini) são da plataforma: configuradas na consola da plataforma e atribuídas por cliente, com quota mensal de tokens.
 *
 *  - ia_fornecedores (global): fornecedor, chave cifrada (SegredosGateway, prefixo `enc:`; nunca devolvida), modelos e
 *    preços para o custo estimado;
 *  - ia_atribuicoes (por cliente): fornecedor, modelo, quota mensal, pesquisa na internet e acções no ERP;
 *  - ia_conversas / ia_mensagens / ia_anexos / ia_accoes (por empresa, RLS): o chat de cada utilizador;
 *  - ia_consumo (por empresa, RLS): tokens e custo de cada pedido — a base da quota e dos relatórios da plataforma.
 *
 * Aditiva e idempotente: cada tabela só se cria se não existir (hasTable); a RLS entra por IsolamentoRls (tabelas com
 * empresa_id). A role `erp_app` recebe os privilégios (tabelas pelos privilégios por omissão; sequências aqui).
 */
return new class extends Migration
{
    private const TABELAS = ['ia_fornecedores', 'ia_atribuicoes', 'ia_conversas', 'ia_mensagens', 'ia_anexos', 'ia_accoes', 'ia_consumo'];

    public function up(): void
    {
        if (! Schema::hasTable('ia_fornecedores')) {
            Schema::create('ia_fornecedores', function (Blueprint $t) {
                $t->id();
                $t->string('fornecedor', 20)->unique('uq_ia_fornecedores_fornecedor')->comment('anthropic | openai | gemini');
                $t->string('nome', 80);
                $t->text('chave')->nullable()->comment('chave da API cifrada (SegredosGateway, enc:): nunca é devolvida pela API');
                $t->jsonb('modelos')->default('[]');
                $t->string('modelo_padrao', 80)->nullable();
                $t->decimal('preco_entrada_mtok', 10, 4)->default(0)->comment('USD por milhão de tokens de entrada');
                $t->decimal('preco_saida_mtok', 10, 4)->default(0)->comment('USD por milhão de tokens de saída');
                $t->boolean('ativo')->default(true);
                $t->timestampTz('testado_em')->nullable();
                $t->string('ultimo_erro', 500)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
            });
            DB::statement("ALTER TABLE ia_fornecedores ADD CONSTRAINT ck_ia_fornecedores_fornecedor CHECK (fornecedor IN ('anthropic','openai','gemini'))");
        }

        if (! Schema::hasTable('ia_atribuicoes')) {
            Schema::create('ia_atribuicoes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->unique('uq_ia_atribuicoes_tenant')->constrained('tenants')->cascadeOnDelete();
                $t->foreignId('fornecedor_id')->constrained('ia_fornecedores')->restrictOnDelete();
                $t->string('modelo', 80);
                $t->boolean('ativo')->default(true);
                $t->unsignedBigInteger('quota_tokens_mes')->nullable()->comment('null = sem limite');
                $t->boolean('pesquisa_web')->default(true);
                $t->boolean('accoes_erp')->default(true);
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
            });
        }

        if (! Schema::hasTable('ia_conversas')) {
            Schema::create('ia_conversas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->foreignId('utilizador_id')->constrained('utilizadores')->cascadeOnDelete();
                $t->string('titulo', 200)->default('Nova conversa');
                $t->string('fornecedor', 20);
                $t->string('modelo', 80);
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->timestampTz('eliminado_em')->nullable();
                $t->index(['utilizador_id', 'empresa_id', 'atualizado_em'], 'ix_ia_conversas_utilizador');
            });
        }

        if (! Schema::hasTable('ia_mensagens')) {
            Schema::create('ia_mensagens', function (Blueprint $t) {
                $t->id();
                $t->foreignId('conversa_id')->constrained('ia_conversas')->cascadeOnDelete();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->string('papel', 15)->comment('utilizador | assistente');
                $t->text('texto')->nullable()->comment('o que o ecrã mostra');
                $t->jsonb('extra')->default('{}')->comment('anexos, fontes da internet, acções propostas, ferramentas usadas');
                $t->jsonb('historico')->default('[]')->comment('mensagens no formato do fornecedor, para reenviar na conversa');
                $t->unsignedInteger('tokens_entrada')->default(0);
                $t->unsignedInteger('tokens_saida')->default(0);
                $t->timestampTz('criado_em')->useCurrent();
                $t->index(['conversa_id', 'id'], 'ix_ia_mensagens_conversa');
            });
        }

        if (! Schema::hasTable('ia_anexos')) {
            Schema::create('ia_anexos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('conversa_id')->constrained('ia_conversas')->cascadeOnDelete();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->foreignId('utilizador_id')->constrained('utilizadores')->cascadeOnDelete();
                $t->string('nome', 255);
                $t->string('mime', 120);
                $t->unsignedInteger('tamanho');
                $t->string('caminho', 500);
                $t->timestampTz('criado_em')->useCurrent();
            });
        }

        if (! Schema::hasTable('ia_accoes')) {
            Schema::create('ia_accoes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('conversa_id')->constrained('ia_conversas')->cascadeOnDelete();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->foreignId('utilizador_id')->constrained('utilizadores')->cascadeOnDelete();
                $t->string('tipo', 40);
                $t->string('resumo', 500);
                $t->string('metodo', 10);
                $t->string('url', 255);
                $t->jsonb('dados')->default('{}');
                $t->string('estado', 15)->default('PENDENTE')->comment('PENDENTE | EXECUTADA | REJEITADA | FALHOU');
                $t->jsonb('resultado')->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('decidida_em')->nullable();
            });
            DB::statement("ALTER TABLE ia_accoes ADD CONSTRAINT ck_ia_accoes_estado CHECK (estado IN ('PENDENTE','EXECUTADA','REJEITADA','FALHOU'))");
        }

        if (! Schema::hasTable('ia_consumo')) {
            Schema::create('ia_consumo', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->unsignedBigInteger('utilizador_id')->nullable();
                $t->unsignedBigInteger('conversa_id')->nullable();
                $t->string('fornecedor', 20);
                $t->string('modelo', 80);
                $t->unsignedInteger('tokens_entrada')->default(0);
                $t->unsignedInteger('tokens_saida')->default(0);
                $t->unsignedSmallInteger('pesquisas_web')->default(0);
                $t->decimal('custo_usd', 12, 6)->default(0);
                $t->timestampTz('criado_em')->useCurrent();
                $t->index(['tenant_id', 'criado_em'], 'ix_ia_consumo_tenant');
            });
        }

        (new IsolamentoRls(DB::connection()))->aplicarATodas();

        // Sequências para a role da aplicação (as tabelas já vêm dos privilégios por omissão, ver IsolamentoRls::prepararRole)
        $tabelas = implode(', ', array_map(fn ($t) => "'{$t}'", self::TABELAS));
        DB::unprepared(<<<SQL
            DO \$\$ DECLARE t text; BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN
                    FOREACH t IN ARRAY ARRAY[{$tabelas}] LOOP
                        EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON %I TO erp_app', t);
                        EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %I TO erp_app', t || '_id_seq');
                    END LOOP;
                END IF;
            END \$\$
        SQL);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABELAS) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
