<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * O ecrã «BI contabilístico e Gestão documental» (accounting_bi, vistas accounting_bi e sgd) foi eliminado do sistema a
 * pedido do utilizador (17/10/2026): saiu do catálogo de permissões, do menu e das rotas. As chaves desse ecrã que ficaram
 * gravadas nos perfis deixariam de ser válidas e impediriam gravar o perfil (PERMISSAO_INEXISTENTE); por isso retiram-se
 * de perfis_utilizador.permissoes. O registo do que foi retirado (perfil e chaves) fica no log da aplicação e a coluna
 * permissoes_originais (formato do legado) não é tocada. Idempotente; nada mais é alterado.
 */
return new class extends Migration
{
    /** Chaves do ecrã eliminado (consulta das duas vistas e as do formato antigo do legado). */
    private const CHAVES = ['accounting_bi_view', 'sgd_view', 'accounting_bi_edit', 'accounting_bi_delete', 'sgd_edit', 'sgd_delete'];

    public function up(): void
    {
        if (! Schema::hasTable('perfis_utilizador') || ! Schema::hasColumn('perfis_utilizador', 'permissoes')) {
            return;
        }
        DB::transaction(function () {
            $perfis = DB::table('perfis_utilizador')->select(['id', 'nome', 'permissoes'])->orderBy('id')->lockForUpdate()->get();
            foreach ($perfis as $p) {
                $perms = json_decode((string) $p->permissoes, true);
                if (! is_array($perms)) {
                    continue;
                }
                $retiradas = array_values(array_intersect(array_keys($perms), self::CHAVES));
                if ($retiradas === []) {
                    continue;
                }
                $novas = array_diff_key($perms, array_flip($retiradas));
                DB::table('perfis_utilizador')->where('id', $p->id)
                    ->update(['permissoes' => json_encode($novas ?: new stdClass, JSON_UNESCAPED_UNICODE)]);
                Log::info('Migração r3_layout: BI contabilístico retirado do perfil.', ['perfil_id' => (int) $p->id, 'chaves' => $retiradas]);
            }
        });
    }

    /** O ecrã já não existe: não se repõem as chaves (ficam no log da aplicação). */
    public function down(): void {}
};
