<?php

namespace App\Services\Sistema;

use Illuminate\Support\Facades\DB;

/**
 * Atribuição das tarefas novas do catálogo de permissões aos perfis que já faziam a acção (ronda 2, ADR-068), para
 * ninguém perder acesso quando a acção passa a ter permissão própria:
 *
 *   - rh_tabela_irt_gerir           (decisão 1)  → perfis com rh_infotipos_gerir (gerem as regras salariais);
 *   - vendas_alterar_preco          (decisão 8)  → perfis com vendas_fat_emitir (emitiam com o preço alterado);
 *   - cambio_manual_fora_tolerancia (decisão 9)  → só perfis com config_moedas_gerir (quem gere moedas e câmbios).
 *     Melhor prática aceite pelo utilizador: forçar um câmbio fora da tolerância é uma excepção de controlo e não
 *     acompanha a emissão de documentos; os perfis de acesso total ({all: true}) já a têm por definição.
 *
 * Reutilizado pelas migrações de dados (bases que já têm perfis, ex.: produção) e pela migração do legado
 * (ServicoMigracaoLegado, depois do COMMIT): numa base nova as migrações correm antes de a ETL carregar os perfis.
 * Só perfis no formato v2; idempotente (só acrescenta chaves em falta, nunca retira).
 */
final class ServicoPermissoesNovas
{
    /** tarefa nova => tarefas de origem (basta uma) */
    public const ATRIBUICOES = [
        'rh_tabela_irt_gerir' => ['rh_infotipos_gerir'],
        'vendas_alterar_preco' => ['vendas_fat_emitir'],
        'cambio_manual_fora_tolerancia' => ['config_moedas_gerir'],
    ];

    /**
     * Acrescenta as tarefas novas aos perfis v2 que têm alguma das tarefas de origem.
     *
     * @param  list<string>|null  $so  só estas tarefas novas (null = todas)
     * @return array<string, int> tarefa nova => perfis a que foi atribuída
     */
    public static function aplicar(?array $so = null): array
    {
        $atribuicoes = $so === null ? self::ATRIBUICOES : array_intersect_key(self::ATRIBUICOES, array_flip($so));
        $contagem = array_fill_keys(array_keys($atribuicoes), 0);
        foreach (DB::table('perfis_utilizador')->orderBy('id')->get(['id', 'permissoes']) as $perfil) {
            $p = json_decode((string) $perfil->permissoes, true);
            if (! is_array($p) || empty($p['_v2'])) {
                continue;
            }
            $alterado = false;
            foreach ($atribuicoes as $nova => $origens) {
                if (empty($p[$nova]) && collect($origens)->contains(fn ($o) => ! empty($p[$o]))) {
                    $p[$nova] = true;
                    $contagem[$nova]++;
                    $alterado = true;
                }
            }
            if ($alterado) {
                DB::table('perfis_utilizador')->where('id', $perfil->id)->update(['permissoes' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
            }
        }

        return $contagem;
    }

    /**
     * Retira as tarefas indicadas de todos os perfis (down() das migrações).
     *
     * @param  list<string>  $chaves
     */
    public static function retirar(array $chaves): void
    {
        $retirar = array_flip($chaves);
        foreach (DB::table('perfis_utilizador')->get(['id', 'permissoes']) as $perfil) {
            $p = json_decode((string) $perfil->permissoes, true);
            if (! is_array($p) || ! array_intersect_key($p, $retirar)) {
                continue;
            }
            DB::table('perfis_utilizador')->where('id', $perfil->id)
                ->update(['permissoes' => json_encode(array_diff_key($p, $retirar), JSON_UNESCAPED_UNICODE)]);
        }
    }
}
