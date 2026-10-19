<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 4 (r4_agt) — facturação electrónica AGT por empresa (aditiva, com guardas; nenhum dado existente é alterado):
 *
 *   agt_ligacoes_empresa   ligação de cada empresa à AGT: ambiente, credenciais, chave do contribuinte (cifradas com a
 *                          APP_KEY — cast «encrypted»; nunca devolvidas à interface), certificado/chave pública, webhook;
 *   agt_comunicacoes       registo de cada pedido/resposta (dados sensíveis mascarados, duração, código);
 *   agt_submissoes         «outbox» dos lotes enviados: o submissionUUID é estável por lote e reutilizado nas
 *                          retentativas (a AGT devolve o requestID original a um pedido duplicado — idempotência);
 *   agt_webhook_eventos    notificações recebidas (anti-replay por empresa + nonce);
 *   vendas.saft_source_billing / sistema_origem / numero_documento_saft — documentos integrados de outro sistema
 *                          (SAF-T(AO) SourceBilling «I»): o n.º original fica intacto e o n.º no formato SAF-T é guardado
 *                          à parte (erp:saft:documentos-importados).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agt_ligacoes_empresa')) {
            Schema::create('agt_ligacoes_empresa', function (Blueprint $t) {
                $t->id();
                $t->bigInteger('empresa_id')->comment('Empresa (uma ligação por empresa)');
                $t->boolean('ativo')->default(false)->comment('Usa esta ligação (senão, a do servidor: AGT_DRIVER)');
                $t->string('ambiente', 20)->default('homologacao')->comment('homologacao | producao');
                $t->string('url_base', 255)->nullable()->comment('Só se a AGT mudar o endereço; HTTPS obrigatório');
                $t->text('utilizador')->nullable()->comment('Cifrado (APP_KEY). Basic auth; vazio = credenciais do produtor no servidor');
                $t->text('palavra_passe')->nullable()->comment('Cifrado (APP_KEY)');
                $t->timestampTz('credenciais_atualizadas_em')->nullable();
                $t->string('credenciais_atualizadas_por', 255)->nullable();
                $t->text('chave_privada')->nullable()->comment('Cifrado (APP_KEY). Chave RSA do contribuinte (Portal do Contribuinte)');
                $t->text('chave_publica')->nullable()->comment('Chave pública derivada (não é segredo)');
                $t->text('certificado')->nullable()->comment('Certificado X.509 opcional (não é segredo)');
                $t->string('chave_impressao_digital', 64)->nullable()->comment('SHA-256 da chave pública (DER)');
                $t->string('chave_anterior_impressao_digital', 64)->nullable()->comment('Rotação: impressão digital da chave substituída');
                $t->integer('chave_bits')->nullable();
                $t->integer('chave_versao')->default(0)->comment('Incrementa a cada rotação');
                $t->timestampTz('certificado_valido_de')->nullable();
                $t->timestampTz('certificado_valido_ate')->nullable();
                $t->timestampTz('chave_atualizada_em')->nullable();
                $t->string('chave_atualizada_por', 255)->nullable();
                $t->boolean('webhook_ativo')->default(false);
                $t->string('webhook_token_hash', 64)->nullable()->comment('SHA-256 do identificador público do endereço');
                $t->text('webhook_segredo')->nullable()->comment('Cifrado (APP_KEY). Segredo HMAC das notificações');
                $t->timestampTz('webhook_atualizado_em')->nullable();
                $t->string('webhook_atualizado_por', 255)->nullable();
                $t->timestampTz('ultimo_teste_em')->nullable();
                $t->boolean('ultimo_teste_ok')->nullable();
                $t->text('ultimo_teste_mensagem')->nullable();
                $t->string('atualizado_por', 255)->nullable();
                $t->timestampTz('criado_em')->nullable();
                $t->timestampTz('atualizado_em')->nullable();
            });
            DB::statement('CREATE UNIQUE INDEX uq_agt_ligacoes_empresa_empresa ON agt_ligacoes_empresa (empresa_id)');
            DB::statement('CREATE UNIQUE INDEX uq_agt_ligacoes_empresa_webhook ON agt_ligacoes_empresa (webhook_token_hash) WHERE webhook_token_hash IS NOT NULL');
            DB::statement("ALTER TABLE agt_ligacoes_empresa ADD CONSTRAINT ck_agt_ligacoes_empresa_ambiente CHECK (ambiente IN ('homologacao','producao'))");
            $this->fkEmpresa('agt_ligacoes_empresa');
        }

        if (! Schema::hasTable('agt_comunicacoes')) {
            Schema::create('agt_comunicacoes', function (Blueprint $t) {
                $t->id();
                $t->bigInteger('empresa_id');
                $t->string('operacao', 40)->comment('registarFactura, obterEstado, consultarFactura, solicitarSerie, webhook, teste');
                $t->string('ambiente', 20)->nullable();
                $t->string('endpoint', 255)->nullable();
                $t->integer('http_estado')->nullable();
                $t->boolean('sucesso')->default(false);
                $t->string('codigo_erro', 60)->nullable();
                $t->text('mensagem')->nullable();
                $t->integer('duracao_ms')->nullable();
                $t->smallInteger('tentativa')->default(1);
                $t->string('submission_uuid', 36)->nullable();
                $t->string('request_id', 40)->nullable();
                $t->jsonb('documentos')->nullable()->comment('N.os dos documentos do pedido');
                $t->jsonb('pedido')->nullable()->comment('Pedido com assinaturas, credenciais e NIF de clientes mascarados');
                $t->jsonb('resposta')->nullable()->comment('Resposta (truncada)');
                $t->string('utilizador', 255)->nullable();
                $t->timestampTz('criado_em')->useCurrent();
            });
            DB::statement('CREATE INDEX ix_agt_comunicacoes_empresa_data ON agt_comunicacoes (empresa_id, criado_em DESC)');
            DB::statement('CREATE INDEX ix_agt_comunicacoes_empresa_falhas ON agt_comunicacoes (empresa_id, criado_em DESC) WHERE NOT sucesso');
            $this->fkEmpresa('agt_comunicacoes');
        }

        if (! Schema::hasTable('agt_submissoes')) {
            Schema::create('agt_submissoes', function (Blueprint $t) {
                $t->id();
                $t->bigInteger('empresa_id');
                $t->string('submission_uuid', 36);
                $t->jsonb('venda_ids');
                $t->jsonb('documentos')->nullable();
                $t->string('estado', 20)->default('PENDENTE')->comment('PENDENTE | ACEITE | RECUSADA | INCERTA');
                $t->integer('tentativas')->default(0);
                $t->string('request_id', 40)->nullable();
                $t->text('ultimo_erro')->nullable();
                $t->timestampTz('proxima_tentativa_em')->nullable();
                $t->timestampTz('criado_em')->nullable();
                $t->timestampTz('atualizado_em')->nullable();
            });
            DB::statement('CREATE UNIQUE INDEX uq_agt_submissoes_uuid ON agt_submissoes (submission_uuid)');
            DB::statement('CREATE INDEX ix_agt_submissoes_empresa_estado ON agt_submissoes (empresa_id, estado)');
            DB::statement("ALTER TABLE agt_submissoes ADD CONSTRAINT ck_agt_submissoes_estado CHECK (estado IN ('PENDENTE','ACEITE','RECUSADA','INCERTA'))");
            $this->fkEmpresa('agt_submissoes');
        }

        if (! Schema::hasTable('agt_webhook_eventos')) {
            Schema::create('agt_webhook_eventos', function (Blueprint $t) {
                $t->id();
                $t->bigInteger('empresa_id');
                $t->string('nonce', 100);
                $t->timestampTz('carimbo')->comment('Carimbo temporal assinado pelo emissor');
                $t->timestampTz('recebido_em')->useCurrent();
                $t->string('ip', 45)->nullable();
                $t->jsonb('corpo')->nullable();
                $t->string('estado', 20)->default('RECEBIDO')->comment('RECEBIDO | PROCESSADO | IGNORADO | ERRO');
                $t->timestampTz('processado_em')->nullable();
                $t->text('resultado')->nullable();
            });
            DB::statement('CREATE UNIQUE INDEX uq_agt_webhook_eventos_nonce ON agt_webhook_eventos (empresa_id, nonce)');
            $this->fkEmpresa('agt_webhook_eventos');
        }

        if (Schema::hasTable('vendas')) {
            Schema::table('vendas', function (Blueprint $t) {
                if (! Schema::hasColumn('vendas', 'saft_source_billing')) {
                    $t->string('saft_source_billing', 1)->nullable()->comment('SAF-T(AO) SourceBilling: P (produzido aqui; null = P), I (integrado de outro sistema), M (manual/recuperado)');
                }
                if (! Schema::hasColumn('vendas', 'sistema_origem')) {
                    $t->string('sistema_origem', 120)->nullable()->comment('Sistema que emitiu o documento integrado (ex.: ERP legado v4)');
                }
                if (! Schema::hasColumn('vendas', 'numero_documento_saft')) {
                    $t->string('numero_documento_saft', 60)->nullable()->comment('N.º no formato SAF-T de um documento integrado cujo n.º original não o cumpre (o original fica intacto)');
                }
            });
            DB::statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_vendas_saft_source_billing') THEN
                ALTER TABLE vendas ADD CONSTRAINT ck_vendas_saft_source_billing CHECK (saft_source_billing IS NULL OR saft_source_billing IN ('P','I','M')); END IF; END \$\$");
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_vendas_empresa_numero_saft ON vendas (empresa_id, numero_documento_saft) WHERE numero_documento_saft IS NOT NULL');
        }

        // Permissões novas do catálogo atribuídas a quem já fazia a acção (ninguém perde acesso)
        $this->atribuirPermissoes([
            'vendas_agt_ligacao' => ['vendas_fe_config'],
            'vendas_agt_registos' => ['vendas_fe_config'],
            'vendas_agt_enviar' => ['vendas_fat_emitir'],
        ]);
    }

    public function down(): void
    {
        $this->retirarPermissoes(['vendas_agt_ligacao', 'vendas_agt_registos', 'vendas_agt_enviar']);
        if (Schema::hasTable('vendas')) {
            DB::statement('DROP INDEX IF EXISTS uq_vendas_empresa_numero_saft');
            DB::statement('ALTER TABLE vendas DROP CONSTRAINT IF EXISTS ck_vendas_saft_source_billing');
            Schema::table('vendas', function (Blueprint $t) {
                foreach (['numero_documento_saft', 'sistema_origem', 'saft_source_billing'] as $c) {
                    if (Schema::hasColumn('vendas', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
        foreach (['agt_webhook_eventos', 'agt_submissoes', 'agt_comunicacoes', 'agt_ligacoes_empresa'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }

    private function fkEmpresa(string $tabela): void
    {
        if (Schema::hasTable('empresas')) {
            DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT fk_{$tabela}_empresa_id FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE RESTRICT");
        }
    }

    /** @param  array<string, list<string>>  $atribuicoes */
    private function atribuirPermissoes(array $atribuicoes): void
    {
        if (! Schema::hasTable('perfis_utilizador')) {
            return;
        }
        foreach (DB::table('perfis_utilizador')->orderBy('id')->get(['id', 'permissoes']) as $perfil) {
            $p = json_decode((string) $perfil->permissoes, true);
            if (! is_array($p) || empty($p['_v2'])) {
                continue;
            }
            $alterado = false;
            foreach ($atribuicoes as $nova => $origens) {
                if (empty($p[$nova]) && collect($origens)->contains(fn ($o) => ! empty($p[$o]))) {
                    $p[$nova] = true;
                    $alterado = true;
                }
            }
            if ($alterado) {
                DB::table('perfis_utilizador')->where('id', $perfil->id)->update(['permissoes' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    /** @param  list<string>  $chaves */
    private function retirarPermissoes(array $chaves): void
    {
        if (! Schema::hasTable('perfis_utilizador')) {
            return;
        }
        $retirar = array_flip($chaves);
        foreach (DB::table('perfis_utilizador')->get(['id', 'permissoes']) as $perfil) {
            $p = json_decode((string) $perfil->permissoes, true);
            if (is_array($p) && array_intersect_key($p, $retirar)) {
                DB::table('perfis_utilizador')->where('id', $perfil->id)->update(['permissoes' => json_encode(array_diff_key($p, $retirar), JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
};
