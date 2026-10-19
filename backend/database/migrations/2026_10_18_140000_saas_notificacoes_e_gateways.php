<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelas para:
 * 1. `notificacoes_sistema`: alertas e notificações de eventos no sistema por empresa (isolamento multi-empresa com RLS).
 * 2. `configuracoes_gateway`: credenciais e parâmetros para Pagamento por Referência Multicaixa, Multicaixa Express e Coordenadas Bancárias.
 * 3. `transacoes_gateway`: histórico e rastreio de transações de pagamento iniciadas via gateway para subscrições de licenças.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notificacoes_sistema')) {
            Schema::create('notificacoes_sistema', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->foreignId('utilizador_id')->nullable()->constrained('utilizadores')->nullOnDelete();
                $t->string('autor_nome', 100)->nullable();
                $t->string('tipo', 20)->default('INFO'); // INFO, SUCESSO, AVISO, URGENTE
                $t->string('categoria', 30)->default('SISTEMA'); // VENDAS, TESOURARIA, CONTABILIDADE, STOCK, SISTEMA, LICENCA, UTILIZADORES
                $t->string('titulo', 200);
                $t->text('mensagem');
                $t->string('link', 255)->nullable();
                $t->boolean('lida')->default(false);
                $t->timestampTz('lida_em')->nullable();
                $t->timestampTz('criado_em')->useCurrent();

                $t->index(['empresa_id', 'lida', 'criado_em'], 'idx_notificacoes_empresa_lida');
                $t->index(['empresa_id', 'criado_em'], 'idx_notificacoes_empresa_data');
            });
        }

        if (! Schema::hasTable('configuracoes_gateway')) {
            Schema::create('configuracoes_gateway', function (Blueprint $t) {
                $t->string('chave', 80)->primary();
                $t->text('valor')->nullable();
                $t->timestampTz('atualizado_em')->useCurrent();
            });

            // Configurações por omissão para os métodos de pagamento (Angola)
            $predefinidos = [
                // Pagamento por Referência Multicaixa
                'ref_ativo' => 'true',
                'ref_entidade' => '00123',
                'ref_subentidade' => '',
                'ref_chave_api' => '',
                'ref_url_api' => '',
                'ref_webhook_segredo' => '',

                // Multicaixa Express
                'mcx_ativo' => 'true',
                'mcx_merchant_id' => 'CONSULVOLT_SaaS',
                'mcx_terminal_id' => '',
                'mcx_chave_api' => '',
                'mcx_url_api' => '',
                'mcx_webhook_segredo' => '',

                // Coordenadas Bancárias da Plataforma / Empresa
                'banco_nome' => 'Banco Angolano de Investimentos (BAI)',
                'titular_conta' => 'Consulvolt Lda.',
                'iban' => 'AO06 0040 0000 1234 5678 9012 3',
                'swift_bic' => 'BAIAOAOX',
            ];

            foreach ($predefinidos as $k => $v) {
                DB::table('configuracoes_gateway')->insertOrIgnore([
                    'chave' => $k,
                    'valor' => $v,
                    'atualizado_em' => now(),
                ]);
            }
        }

        if (! Schema::hasTable('transacoes_gateway')) {
            Schema::create('transacoes_gateway', function (Blueprint $t) {
                $t->id();
                $t->foreignId('subscricao_id')->constrained('subscricoes_empresa')->cascadeOnDelete();
                $t->string('gateway', 20); // REFERENCIA, EXPRESS, MANUAL
                $t->string('referencia_externa', 100)->nullable();
                $t->string('entidade', 20)->nullable();
                $t->string('referencia', 50)->nullable();
                $t->string('telemovel', 30)->nullable();
                $t->decimal('valor', 14, 2);
                $t->string('moeda', 3)->default('AOA');
                $t->string('estado', 20)->default('PENDENTE'); // PENDENTE, PAGO, CANCELADO, FALHOU, EXPIRADA
                $t->jsonb('dados_pedido')->nullable();
                $t->jsonb('dados_resposta')->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();

                $t->index(['subscricao_id', 'estado'], 'idx_transacoes_subscricao');
                $t->index(['gateway', 'referencia'], 'idx_transacoes_gateway_ref');
                $t->index(['referencia_externa'], 'idx_transacoes_externa');
            });
        }

        // Privilégios para o role erp_app caso exista
        DB::statement("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'erp_app') THEN "
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON notificacoes_sistema, configuracoes_gateway, transacoes_gateway TO erp_app; '
            .'GRANT USAGE, SELECT ON SEQUENCE notificacoes_sistema_id_seq, transacoes_gateway_id_seq TO erp_app; END IF; END $$');

        // Aplicar a política RLS isolamento_tenant em todas as tabelas com empresa_id (notificacoes_sistema)
        (new IsolamentoRls(DB::connection()))->aplicarATodas();
    }

    public function down(): void
    {
        Schema::dropIfExists('transacoes_gateway');
        Schema::dropIfExists('configuracoes_gateway');
        Schema::dropIfExists('notificacoes_sistema');
    }
};
