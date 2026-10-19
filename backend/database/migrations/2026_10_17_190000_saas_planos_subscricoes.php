<?php

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS — cobrança da licença por plano e tempo (ADR-074). Aditiva e com guardas.
 *
 *  - `planos_licenca`: catálogo de planos da plataforma (preço mensal, limite de utilizadores). Sem `empresa_id`: global.
 *  - `subscricoes_empresa`: pedidos de renovação/compra por empresa (PENDENTE → PAGO | CANCELADO), com a referência de
 *    pagamento. Ao confirmar o pagamento, a licença da empresa é prolongada. Sem gateway de pagamentos: a confirmação é feita
 *    por um super-administrador (decisão do gateway por tomar).
 *  - Permissão `config_licenca_gerir` para quem já geria empresas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('planos_licenca')) {
            Schema::create('planos_licenca', function (Blueprint $t) {
                $t->id();
                $t->string('codigo', 30)->unique('uq_planos_licenca_codigo');
                $t->string('nome', 100);
                $t->text('descricao')->nullable();
                $t->decimal('preco_mensal', 14, 2)->default(0);
                $t->string('moeda', 3)->default('AOA');
                $t->unsignedInteger('max_utilizadores')->nullable();
                $t->boolean('ativo')->default(true);
                $t->unsignedInteger('ordem')->default(0);
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
            });
        }

        if (! Schema::hasTable('subscricoes_empresa')) {
            Schema::create('subscricoes_empresa', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('plano_id');
                $t->unsignedSmallInteger('meses');
                $t->decimal('valor', 14, 2);
                $t->string('moeda', 3)->default('AOA');
                $t->string('estado', 12)->default('PENDENTE');
                $t->string('referencia', 40)->unique('uq_subscricoes_empresa_referencia');
                $t->date('periodo_inicio')->nullable();
                $t->date('periodo_fim')->nullable();
                $t->timestampTz('pago_em')->nullable();
                $t->unsignedBigInteger('pago_por')->nullable();
                $t->string('metodo', 30)->nullable();
                $t->text('notas')->nullable();
                $t->unsignedBigInteger('criado_por')->nullable();
                $t->timestampTz('criado_em')->useCurrent();
                $t->timestampTz('atualizado_em')->useCurrent();
                $t->foreign('empresa_id', 'fk_subscricoes_empresa_empresa_id')->references('id')->on('empresas')->restrictOnDelete();
                $t->foreign('plano_id', 'fk_subscricoes_empresa_plano_id')->references('id')->on('planos_licenca')->restrictOnDelete();
                $t->index(['empresa_id', 'estado'], 'idx_subscricoes_empresa_estado');
            });
            DB::statement("ALTER TABLE subscricoes_empresa ADD CONSTRAINT ck_subscricoes_empresa_estado CHECK (estado IN ('PENDENTE','PAGO','CANCELADO'))");
            DB::statement('ALTER TABLE subscricoes_empresa ADD CONSTRAINT ck_subscricoes_empresa_meses CHECK (meses BETWEEN 1 AND 36)');
        }

        // RLS por tenant (contrato da fase 2) e privilégios da role da aplicação nas tabelas novas.
        (new IsolamentoRls(DB::connection()))->aplicarATodas();
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'erp_app'") !== null) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON planos_licenca, subscricoes_empresa TO erp_app');
            DB::statement('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO erp_app');
        }

        $this->atribuirPermissoes(['config_licenca_view' => ['config_empresas_view', 'config_empresas_gerir'], 'config_licenca_gerir' => ['config_empresas_gerir']]);
    }

    public function down(): void
    {
        $this->retirarPermissoes(['config_licenca_view', 'config_licenca_gerir']);
        Schema::dropIfExists('subscricoes_empresa');
        Schema::dropIfExists('planos_licenca');
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