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

    public function wbs(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->planeamento->wbs(Projeto::query()->findOrFail($projeto)), 'Planeamento (WBS).');
    }

    // ───────────── Milestones ─────────────

    public function guardarMarco(Request $r, int $projeto, ?int $marco = null): JsonResponse
    {
        $this->exigir('proj_gerir');
        $p = Projeto::query()->findOrFail($projeto);
        $d = $r->validate(['nome' => [$marco ? 'sometimes' : 'required', 'string', 'max:255'], 'data' => ['nullable', 'date']]);
        $m = $marco ? MarcoProjeto::query()->where('projeto_id', $p->id)->findOrFail($marco) : null;
        $res = $this->planeamento->guardarMarco($p, $d, $m);

        return $m ? RespostaApi::sucesso($res, 'Milestone actualizado.') : RespostaApi::criado($res, 'Milestone criado.');
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
        ]);
        $t = $tarefa ? TarefaProjeto::query()->where('projeto_id', $p->id)->findOrFail($tarefa) : null;
        $res = $this->planeamento->guardarTarefa($p, $d, $t);

        return $t ? RespostaApi::sucesso($res, 'Tarefa actualizada.') : RespostaApi::criado($res, 'Tarefa criada.');
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
        $d = $r->validate(['tipo' => ['required', Rule::in(['ANTES', 'DEPOIS', 'DENTRO', 'MARCO'])], 'alvo_id' => ['nullable', 'integer'], 'marco_projeto_id' => ['nullable', 'integer']]);
        $t = TarefaProjeto::query()->where('projeto_id', $projeto)->findOrFail($tarefa);

        return RespostaApi::sucesso($this->planeamento->mover($t, $d), 'Tarefa movida.');
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
