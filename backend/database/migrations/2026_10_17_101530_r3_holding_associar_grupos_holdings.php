<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Holdings dos grupos de consolidação existentes (agente r3_holding): cada grupo tem de ter uma empresa-holding própria,
 * marcada como empresa de consolidação (empresas.e_consolidacao), para aparecer na troca de empresa e ter o menu da holding.
 *
 *   - grupo cuja holding existe mas não está marcada → marca-a (só se não tiver lançamentos manuais: uma empresa com movimentos
 *     próprios não passa a holding, regra de ServicoGestaoEmpresas; nesse caso fica no relatório para decisão manual);
 *   - grupo sem holding (ou com a holding apagada fisicamente) → cria uma holding com o nome do grupo e um NIF técnico único
 *     («HOLDING-G<id>», a corrigir no menu Consolidação) e associa-a ao grupo, aos membros e às execuções sem holding.
 *
 * Aditiva e idempotente: nada é apagado; cada alteração fica no registo (Log) com o id do grupo e da empresa.
 * O down não desfaz (marcar/criar holdings não perde dados e desfazer esconderia holdings em uso).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('grupos_consolidacao') || ! Schema::hasTable('empresas') || ! Schema::hasColumn('empresas', 'e_consolidacao')) {
            return;
        }
        $relatorio = ['marcadas' => [], 'criadas' => [], 'por_decidir' => []];
        DB::transaction(function () use (&$relatorio) {
            foreach (DB::table('grupos_consolidacao')->orderBy('id')->lockForUpdate()->get() as $g) {
                $holding = $g->empresa_holding_id ? DB::table('empresas')->where('id', $g->empresa_holding_id)->first(['id', 'e_consolidacao']) : null;
                if ($holding) {
                    if ($holding->e_consolidacao) {
                        continue;
                    }
                    $manuais = DB::table('lancamentos_contabeis')->where('empresa_id', $holding->id)->whereNull('tipo_consolidacao')->whereNull('execucao_consolidacao_id')->exists();
                    if ($manuais) {
                        $relatorio['por_decidir'][] = ['grupo' => $g->id, 'empresa' => $holding->id, 'motivo' => 'a empresa indicada como holding tem lançamentos próprios'];

                        continue;
                    }
                    DB::table('empresas')->where('id', $holding->id)->update(['e_consolidacao' => true, 'atualizado_em' => now()]);
                    $relatorio['marcadas'][] = ['grupo' => $g->id, 'empresa' => $holding->id];

                    continue;
                }
                $id = DB::table('empresas')->insertGetId([
                    'nome' => mb_substr((string) ($g->nome ?: "Holding do grupo {$g->id}"), 0, 255), 'nif' => "HOLDING-G{$g->id}",
                    'endereco' => 'Holding — contas consolidadas', 'e_consolidacao' => true, 'moeda_consolidacao' => $g->moeda_apresentacao ?: 'AOA',
                    'estado' => 'ATIVO', 'criado_em' => now(), 'atualizado_em' => now(),
                ]);
                DB::table('grupos_consolidacao')->where('id', $g->id)->update(['empresa_holding_id' => $id, 'empresa_id' => $id]);
                DB::table('membros_consolidacao')->where('grupo_consolidacao_id', $g->id)->update(['empresa_id' => $id]);
                if (Schema::hasTable('execucoes_consolidacao')) {
                    DB::table('execucoes_consolidacao')->where('grupo_consolidacao_id', $g->id)->whereNull('empresa_holding_id')->update(['empresa_holding_id' => $id]);
                }
                $relatorio['criadas'][] = ['grupo' => $g->id, 'empresa' => $id];
            }
        });
        if ($relatorio['marcadas'] || $relatorio['criadas'] || $relatorio['por_decidir']) {
            Log::info('r3_holding: holdings dos grupos de consolidação', $relatorio);
        }
    }

    public function down(): void
    {
        // sem reversão: ver o comentário da classe
    }
};
