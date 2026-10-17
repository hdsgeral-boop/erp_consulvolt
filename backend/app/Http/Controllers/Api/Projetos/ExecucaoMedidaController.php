<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\MarcoProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Services\Projetos\RegrasMetasProjetos;
use App\Services\Projetos\ServicoHierarquiaProjetos;
use App\Services\Projetos\ServicoMetasProjetos;
use App\Services\Projetos\ServicoPaineisExecucaoProjetos;
use App\Services\Projetos\ServicoRevisoesProjetos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/projetos/{projeto} — Fase E (Versão Actual): execução medida (metas por quantidade), folha de medição, assistente
 * «Tarefas por quantidade», dependências (predecessoras possíveis), duplicar e mudar de nível, separador Execução, mapa
 * de quantidades, carga de trabalho / ordem de trabalho e meses dos autos. A permissão é verificada antes da validação.
 */
final class ExecucaoMedidaController extends Controller
{
    /** Quem regista execução no legado novo (ProjMetas.podeExecutar, projectos_metas.js:352). */
    private const EXECUTAR = ['proj_execucao', 'proj_gerir'];

    public function __construct(
        private readonly ServicoMetasProjetos $metas,
        private readonly ServicoHierarquiaProjetos $hierarquia,
        private readonly ServicoPaineisExecucaoProjetos $paineis,
    ) {}

    private static function projeto(int $id): Projeto
    {
        return Projeto::query()->findOrFail($id);
    }

    // ───────────── Medições ─────────────

    public function registarMedicao(Request $r, int $projeto, int $tarefa): JsonResponse
    {
        $this->exigir(...self::EXECUTAR);
        $d = $r->validate(['quantidade' => ['required', 'numeric', 'between:-1000000000,1000000000'], 'data' => ['nullable', 'date_format:Y-m-d'], 'nota' => ['nullable', 'string', 'max:500'],
            'membro_id' => ['nullable', 'integer', 'min:1']]);
        $p = self::projeto($projeto);
        $res = $this->metas->registar($p, $tarefa, $d['quantidade'], $d['data'] ?? null, $d['nota'] ?? null, isset($d['membro_id']) ? (int) $d['membro_id'] : null);

        return RespostaApi::criado(['tarefa' => $res['tarefa'], 'realizado' => $res['realizado'], 'pct' => $res['pct']], "Registado. Execução: {$res['pct']}%.");
    }

    public function folhaMedicao(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->metas->folhaMedicao(self::projeto($projeto)), 'Folha de medição.');
    }

    public function registarLote(Request $r, int $projeto): JsonResponse
    {
        $this->exigir(...self::EXECUTAR);
        $d = $r->validate(['data' => ['nullable', 'date_format:Y-m-d'], 'nota' => ['nullable', 'string', 'max:500'], 'linhas' => ['required', 'array', 'min:1', 'max:500'],
            'linhas.*.tarefa_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'between:-1000000000,1000000000'],
            'linhas.*.nota' => ['nullable', 'string', 'max:500'], 'linhas.*.membro_id' => ['nullable', 'integer', 'min:1']]);
        $res = $this->metas->registarEmLote(self::projeto($projeto), $d['linhas'], $d['data'] ?? null, $d['nota'] ?? null);
        $total = count($d['linhas']);

        return RespostaApi::sucesso($res, "{$res['gravadas']} de {$total} medição(ões) registada(s)".($res['falhadas'] ? '; '.count($res['falhadas']).' por corrigir' : '').'.');
    }

    public function criarPorQuantidade(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['nome' => ['required', 'string', 'max:150'], 'unidade' => ['required', 'string', 'max:20'], 'total' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'marco_projeto_id' => ['nullable', 'integer'], 'tarefa_pai_id' => ['nullable', 'integer'], 'data_inicio' => ['nullable', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d'], 'repartir_prazo' => ['nullable', 'boolean'], 'partes' => ['required', 'array', 'min:1', 'max:60'],
            'partes.*.nome' => ['nullable', 'string', 'max:160'], 'partes.*.quantidade' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'partes.*.responsavel' => ['nullable', 'string', 'regex:/^[mn]:\d{1,18}$/']]);
        $ids = $this->metas->criarPorQuantidade(self::projeto($projeto), $d);

        return RespostaApi::criado(['tarefas' => $ids], count($ids)." tarefa(s) criadas com meta de {$d['total']} {$d['unidade']}.");
    }

    // ───────────── Hierarquia e dependências ─────────────

    public function candidatasPredecessora(Request $r, int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);
        $d = $r->validate(['tarefa_id' => ['nullable', 'integer']]);
        $p = self::projeto($projeto);
        $t = ! empty($d['tarefa_id']) ? TarefaProjeto::query()->where('projeto_id', $p->id)->findOrFail($d['tarefa_id']) : null;

        return RespostaApi::sucesso($this->hierarquia->candidatas($p, $t), 'Tarefas que podem ser predecessoras.');
    }

    public function duplicar(Request $r, int $projeto, string $tipo, int $id): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['nome' => ['nullable', 'string', 'max:255'], 'deslocar_dias' => ['nullable', 'integer', 'between:-3660,3660']]);
        $tipo = $tipo === 'marcos' ? 'MARCO' : 'TAREFA';
        $res = $this->hierarquia->duplicar(self::projeto($projeto), $tipo, $id, $d['nome'] ?? null, (int) ($d['deslocar_dias'] ?? 0));

        return RespostaApi::criado($res, "Duplicado: {$res['criados']} registo(s) criados.");
    }

    public function mudarNivel(Request $r, int $projeto, string $tipo, int $id): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['destino' => ['required', Rule::in(['SUB', 'RAIZ', 'FASE'])], 'alvo_id' => ['nullable', 'integer'], 'marco_projeto_id' => ['nullable', 'integer'],
            'encaixar' => ['nullable', 'boolean']]);
        $p = self::projeto($projeto);
        if ($tipo === 'marcos') {
            MarcoProjeto::query()->where('projeto_id', $p->id)->findOrFail($id);
        }

        return RespostaApi::sucesso($this->hierarquia->mudarNivel($p, $tipo === 'marcos' ? 'MARCO' : 'TAREFA', $id, $d), 'Nível alterado.');
    }

    // ───────────── Painéis ─────────────

    public function execucao(Request $r, int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);
        $d = $r->validate(['de' => ['required', 'date_format:Y-m-d'], 'ate' => ['required', 'date_format:Y-m-d', 'after_or_equal:de']]);

        return RespostaApi::sucesso($this->paineis->execucao(self::projeto($projeto), $d['de'], $d['ate']), 'Execução do projecto.');
    }

    public function mapaQuantidades(Request $r, int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);
        $d = $r->validate(['escala' => ['nullable', Rule::in(RegrasMetasProjetos::ESCALAS)], 'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso($this->paineis->mapa(self::projeto($projeto), $d['escala'] ?? 'mes', $d['de'] ?? null, $d['ate'] ?? null), 'Mapa de quantidades.');
    }

    public function cargaTrabalho(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->paineis->carga(self::projeto($projeto)), 'Carga de trabalho da equipa.');
    }

    public function mesesRevisao(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso(ServicoRevisoesProjetos::mesesDoProjeto(self::projeto($projeto)), 'Meses com trabalho planeado.');
    }
}
