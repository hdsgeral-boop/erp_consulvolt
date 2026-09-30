<?php

namespace App\Services\Projetos;

use Illuminate\Support\Facades\DB;

/**
 * Pós-carga dos Projectos (ETL), idempotente:
 *   1. colunas do Kanban (configuracoes_projetos, chave kanban_cols): chaves {id, title, color} do legado → {id, titulo, cor}
 *      e o estado-base de cada coluna (as 4 base são o próprio id; FAZENDO/«EM CURSO» → EM_CURSO, como a normalização
 *      de project_tasks.status; as desconhecidas ficam sem estado-base, não se inventa);
 *   2. linhas de auto SUBEMPREITADA com o id do MEMBRO da equipa em terceiro_id (defeito do legado, ui_projects.js:2735):
 *      passa ao terceiro desse membro. Só corrige quando o valor é o id de um membro externo da equipa do projecto e
 *      NÃO é já o terceiro de algum membro dessa equipa — por isso pode correr várias vezes.
 */
final class ServicoMigracaoProjetos
{
    private const COLUNA = ['id' => 'id', 'title' => 'titulo', 'color' => 'cor', 'estado' => 'estado', 'titulo' => 'titulo', 'cor' => 'cor'];

    private const ESTADO_COLUNA = ['PENDENTE' => 'PENDENTE', 'EM_CURSO' => 'EM_CURSO', 'CONCLUIDA' => 'CONCLUIDA', 'BLOQUEADA' => 'BLOQUEADA',
        'FAZENDO' => 'EM_CURSO', 'EM CURSO' => 'EM_CURSO'];

    /** @return array{kanban: int, linhas_revisao: int} */
    public function normalizar(): array
    {
        $r = ['kanban' => 0, 'linhas_revisao' => 0];
        foreach (DB::table('configuracoes_projetos')->where('chave', 'kanban_cols')->whereNotNull('valor')->get(['id', 'valor']) as $c) {
            $cols = json_decode((string) $c->valor, true);
            if (! is_array($cols)) {
                continue;
            }
            $novas = array_map(function ($col) {
                $x = [];
                foreach ((array) $col as $k => $v) {
                    $x[self::COLUNA[$k] ?? $k] = $v;
                }
                $id = strtoupper(trim((string) ($x['id'] ?? '')));
                $x['id'] = $id;
                $x['titulo'] ??= $id;
                $x['cor'] ??= '#94a3b8';
                if (! array_key_exists('estado', $x)) {
                    $x['estado'] = self::ESTADO_COLUNA[str_replace('_', ' ', $id)] ?? self::ESTADO_COLUNA[$id] ?? null;
                }

                return ['id' => $x['id'], 'titulo' => $x['titulo'], 'cor' => $x['cor'], 'estado' => $x['estado']];
            }, $cols);
            $json = json_encode($novas, JSON_UNESCAPED_UNICODE);
            if ($json !== $c->valor) {
                DB::table('configuracoes_projetos')->where('id', $c->id)->update(['valor' => $json]);
                $r['kanban']++;
            }
        }

        $linhas = DB::table('linhas_revisao_projeto as l')->join('revisoes_mensais_projeto as r', 'r.id', '=', 'l.revisao_mensal_projeto_id')
            ->where('l.tipo', 'SUBEMPREITADA')->whereNotNull('l.terceiro_id')->get(['l.id', 'l.terceiro_id', 'r.projeto_id']);
        foreach ($linhas as $l) {
            $membros = DB::table('membros_equipa_projeto as m')->join('equipas_projeto as e', 'e.id', '=', 'm.equipa_projeto_id')
                ->where('e.projeto_id', $l->projeto_id)->get(['m.id', 'm.terceiro_id', 'm.colaborador_id']);
            if ($membros->contains('terceiro_id', $l->terceiro_id)) {
                continue;
            }
            $m = $membros->firstWhere('id', $l->terceiro_id);
            if ($m && ! $m->colaborador_id && $m->terceiro_id) {
                DB::table('linhas_revisao_projeto')->where('id', $l->id)->update(['terceiro_id' => $m->terceiro_id]);
                $r['linhas_revisao']++;
            }
        }

        return $r;
    }
}
