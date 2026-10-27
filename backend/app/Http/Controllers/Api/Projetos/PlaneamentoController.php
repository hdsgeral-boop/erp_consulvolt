<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\MarcoProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Services\Projetos\ServicoPlaneamentoProjetos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/projetos/{projeto} — planeamento: WBS, milestones, tarefas e Kanban. */
final class PlaneamentoController extends Controller
{
    public function __construct(private readonly ServicoPlaneamentoProjetos $planeamento) {}

    public function wbs(Request $r, int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);
        $f = $r->validate(['ordenacao' => ['nullable', Rule::in(['auto', 'manual'])]]);

        return RespostaApi::sucesso($this->planeamento->wbs(Projeto::query()->findOrFail($projeto), $f['ordenacao'] ?? 'auto'), 'Planeamento (WBS).');
    }

    // ───────────── Milestones ─────────────

    public function guardarMarco(Request $r, int $projeto, ?int $marco = null): JsonResponse
    {
        $this->exigir('proj_gerir');
        $p = Projeto::query()->findOrFail($projeto);
        $d = $r->validate(['nome' => [$marco ? 'sometimes' : 'required', 'string', 'max:255'], 'data' => ['nullable', 'date'],
            // Versão Actual: data de início e meta da fase (ui_projects.js:2481-2560)
            'data_inicio' => ['nullable', 'date'], 'meta_unidade' => ['nullable', 'string', 'max:20'], 'meta_quantidade' => ['nullable', 'numeric', 'min:0', 'max:1000000000']]);
        $m = $marco ? MarcoProjeto::query()->where('projeto_id', $p->id)->findOrFail($marco) : null;
        $res = $this->planeamento->guardarMarcoComAvisos($p, $d, $m);
        $dados = $res['marco']->toArray() + ['avisos' => $res['avisos']];

        return $m ? RespostaApi::sucesso($dados, 'Milestone actualizado.') : RespostaApi::criado($dados, 'Milestone criado.');
    }

    public function eliminarMarco(Request $r, int $projeto, int $marco): JsonResponse
    {
        $this->exigir('proj_eliminar');
        $m = MarcoProjeto::query()->where('projeto_id', $projeto)->findOrFail($marco);
        $n = $this->planeamento->eliminarMarco($m, $r->input('confirmacao'));

        return RespostaApi::sucesso(['tarefas_sem_marco' => $n], 'Milestone eliminado.');
    }

    // ───────────── Tarefas ─────────────

    public function guardarTarefa(Request $r, int $projeto, ?int $tarefa = null): JsonResponse
    {
        $this->exigir('proj_gerir');
        $p = Projeto::query()->findOrFail($projeto);
        $d = $r->validate([
            'codigo' => ['nullable', 'string', 'max:50'], 'nome' => [$tarefa ? 'sometimes' : 'required', 'string', 'max:255'],
            'tarefa_pai_id' => ['nullable', 'integer'], 'marco_projeto_id' => ['nullable', 'integer'], 'atribuido_a_id' => ['nullable', 'integer'],
            'data_inicio' => ['nullable', 'date'], 'data_fim' => ['nullable', 'date'], 'estado' => ['nullable', Rule::in(TarefaProjeto::ESTADOS)],
            'percentagem_execucao' => ['nullable', 'integer', 'between:0,100'], 'valor_contrato' => ['nullable', 'numeric', 'min:0'],
            // bandeira de atenção manual (tarefas e subtarefas)
            'flag' => ['nullable', Rule::in(TarefaProjeto::FLAGS)],
            // Versão Actual: meta por quantidade, dependência, posição do organigrama e encaixe das datas (ui_projects.js:2188-2258)
            'meta_unidade' => ['nullable', 'string', 'max:20'], 'meta_quantidade' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'meta_realizado' => ['nullable', 'numeric', 'min:0', 'max:1000000000'], 'tarefa_predecessora_id' => ['nullable', 'integer'],
            'tipo_dependencia' => ['nullable', Rule::in(TarefaProjeto::TIPOS_DEPENDENCIA)], 'desfasamento_dias' => ['nullable', 'integer', 'between:-3650,3650'],
            'no_organigrama_projeto_id' => ['nullable', 'integer'], 'encaixar_datas' => ['nullable', 'boolean'],
        ]);
        $t = $tarefa ? TarefaProjeto::query()->where('projeto_id', $p->id)->findOrFail($tarefa) : null;
        $res = $this->planeamento->guardarTarefaComAvisos($p, $d, $t);
        $dados = $res['tarefa']->toArray() + ['avisos' => $res['avisos']];

        return $t ? RespostaApi::sucesso($dados, 'Tarefa actualizada.') : RespostaApi::criado($dados, 'Tarefa criada.');
    }

    public function execucao(Request $r, int $projeto, int $tarefa): JsonResponse
    {
        $this->exigir('proj_gerir', 'proj_execucao');
        $d = $r->validate(['percentagem_execucao' => ['required', 'integer', 'between:0,100']]);
        $t = TarefaProjeto::query()->where('projeto_id', $projeto)->findOrFail($tarefa);

        return RespostaApi::sucesso($this->planeamento->atualizarExecucao($t, (int) $d['percentagem_execucao']), 'Execução actualizada.');
    }

    public function mover(Request $r, int $projeto, int $tarefa): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['tipo' => ['required', Rule::in(['ANTES', 'DEPOIS', 'DENTRO', 'MARCO'])], 'alvo_id' => ['nullable', 'integer'], 'marco_projeto_id' => ['nullable', 'integer'],
            'encaixar_datas' => ['nullable', 'boolean']]);
        $t = TarefaProjeto::query()->where('projeto_id', $projeto)->findOrFail($tarefa);
        $res = $this->planeamento->moverComDatas($t, $d, (bool) ($d['encaixar_datas'] ?? true));

        return RespostaApi::sucesso($res['tarefa']->toArray() + ['avisos' => $res['avisos']], 'Tarefa movida.');
    }

    public function moverMarco(Request $r, int $projeto, int $marco): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['direcao' => ['required', Rule::in(['CIMA', 'BAIXO'])]]);
        $m = MarcoProjeto::query()->where('projeto_id', $projeto)->findOrFail($marco);

        return RespostaApi::sucesso($this->planeamento->moverMarco($m, $d['direcao'])->toArray(), 'Milestone movido.');
    }

    public function eliminarTarefa(Request $r, int $projeto, int $tarefa): JsonResponse
    {
        $this->exigir('proj_eliminar');
        $t = TarefaProjeto::query()->where('projeto_id', $projeto)->findOrFail($tarefa);
        $ids = $this->planeamento->eliminarTarefa($t, $r->input('confirmacao'));

        return RespostaApi::sucesso(['eliminadas' => $ids], count($ids) > 1 ? 'Tarefa e subtarefas eliminadas.' : 'Tarefa eliminada.');
    }

    // ───────────── Kanban ─────────────

    public function kanban(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->planeamento->kanban(Projeto::query()->findOrFail($projeto)), 'Quadro Kanban.');
    }

    public function colunasKanban(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $r->validate(['colunas' => ['required', 'array', 'min:1'], 'colunas.*.id' => ['required', 'string', 'max:30'], 'colunas.*.titulo' => ['nullable', 'string', 'max:100'],
            'colunas.*.cor' => ['nullable', 'string', 'max:7'], 'colunas.*.estado' => ['nullable', Rule::in(TarefaProjeto::ESTADOS)]]);

        return RespostaApi::sucesso($this->planeamento->guardarColunasKanban(Projeto::query()->findOrFail($projeto), $r->input('colunas')), 'Colunas do Kanban gravadas.');
    }

    public function moverKanban(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir', 'proj_execucao');
        $d = $r->validate(['tarefa_id' => ['required', 'integer'], 'coluna' => ['required', 'string', 'max:30']]);
        $p = Projeto::query()->findOrFail($projeto);
        $t = TarefaProjeto::query()->where('projeto_id', $p->id)->findOrFail($d['tarefa_id']);

        return RespostaApi::sucesso($this->planeamento->moverKanban($p, $t, strtoupper($d['coluna'])), 'Tarefa movida no Kanban.');
    }
}
