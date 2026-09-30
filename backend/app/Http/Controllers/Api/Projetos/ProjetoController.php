<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\LogAtividadeProjeto;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Services\Projetos\ServicoAnaliticoProjetos;
use App\Services\Projetos\ServicoPlaneamentoProjetos;
use App\Services\Projetos\ServicoProjetos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/projetos — carteira, ficha e estados, Gantt global, resumo, extracto analítico, fluxo, rentabilidade e configuração. */
final class ProjetoController extends Controller
{
    /** Quem vê a carteira: o ecrã ou qualquer tarefa do módulo. */
    public const LER = ['projectos_carteira_view', 'proj_gerir', 'proj_execucao', 'proj_requisitar', 'proj_estado', 'proj_revisao', 'proj_eliminar'];

    public function __construct(
        private readonly ServicoProjetos $projetos,
        private readonly ServicoAnaliticoProjetos $analitico,
    ) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::LER);
        $f = $r->validate(['estado' => ['nullable', Rule::in(Projeto::ESTADOS)], 'tipo' => ['nullable', Rule::in(Projeto::TIPOS)], 'pesquisa' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $q = Projeto::query()
            ->when($f['estado'] ?? null, fn ($q, $v) => $v === 'ACTIVO' ? $q->whereIn('estado', ['ACTIVO', 'EM_CURSO']) : $q->where('estado', $v))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($f['pesquisa'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('codigo', 'ilike', "%{$v}%")->orWhere('nome', 'ilike', "%{$v}%")));

        return RespostaApi::paginado($q->orderBy('codigo')->paginate($f['por_pagina'] ?? 50, ['*'], 'pagina', $f['pagina'] ?? 1), null, 'Carteira de projectos.');
    }

    public function ativos(): JsonResponse
    {
        $this->exigir(...self::LER);

        return RespostaApi::sucesso($this->projetos->ativos(), 'Projectos activos.');
    }

    public function show(int $projeto): JsonResponse
    {
        $this->exigir(...self::LER);
        $p = Projeto::query()->findOrFail($projeto);
        $tarefas = TarefaProjeto::query()->where('projeto_id', $p->id)->get();

        return RespostaApi::sucesso($p->toArray() + ['execucao' => ServicoPlaneamentoProjetos::progresso($tarefas)['global'],
            'encomenda' => $p->encomendaVenda()->first(['id', 'numero_documento', 'cliente_id', 'total_liquido', 'estado']),
            'cliente' => $p->cliente()->withTrashed()->first(['id', 'nome'])], 'Projecto.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $this->validar($r);
        if (ServicoProjetos::mudancaSensivel(null, $d['estado'] ?? null)) {
            $this->exigir('proj_estado');
        }

        return RespostaApi::criado($this->projetos->guardar($d), 'Projecto criado.');
    }

    public function update(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $p = Projeto::query()->findOrFail($projeto);
        $d = $this->validar($r, $p);
        if (ServicoProjetos::mudancaSensivel($p, $d['estado'] ?? null)) {
            $this->exigir('proj_estado');
        }

        return RespostaApi::sucesso($this->projetos->guardar($d, $p), 'Projecto actualizado.');
    }

    public function estado(Request $r, int $projeto): JsonResponse
    {
        $p = Projeto::query()->findOrFail($projeto);
        $d = $r->validate(['estado' => ['required', Rule::in([...Projeto::ESTADOS, 'EM_CURSO'])]]);
        ServicoProjetos::mudancaSensivel($p, $d['estado']) ? $this->exigir('proj_estado') : $this->exigir('proj_gerir', 'proj_estado');

        return RespostaApi::sucesso($this->projetos->mudarEstado($p, $d['estado']), 'Estado do projecto alterado.');
    }

    public function resumo(int $projeto): JsonResponse
    {
        $this->exigir(...self::LER);

        return RespostaApi::sucesso($this->analitico->resumo(Projeto::query()->findOrFail($projeto)), 'Resumo e indicadores do projecto.');
    }

    public function atividade(Request $r, int $projeto): JsonResponse
    {
        $this->exigir(...self::LER);
        $p = Projeto::query()->findOrFail($projeto);
        $f = $r->validate(['por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::paginado(LogAtividadeProjeto::query()->where('projeto_id', $p->id)->orderByDesc('ocorrido_em')->orderByDesc('id')
            ->paginate($f['por_pagina'] ?? 50, ['*'], 'pagina', $f['pagina'] ?? 1), null, 'Actividade do projecto.');
    }

    public function gantt(): JsonResponse
    {
        $this->exigir('projectos_gantt_view', ...self::LER);

        return RespostaApi::sucesso($this->projetos->ganttGlobal(), 'Gantt global da carteira.');
    }

    public function extracto(Request $r): JsonResponse
    {
        $this->exigir('projectos_extracto_view');
        $f = $r->validate(['projeto_id' => ['nullable', 'integer'], 'inicio' => ['nullable', 'date'], 'fim' => ['nullable', 'date', 'after_or_equal:inicio']]);
        if (! empty($f['projeto_id'])) {
            Projeto::query()->findOrFail($f['projeto_id']);
        }

        return RespostaApi::sucesso($this->analitico->extracto($f['projeto_id'] ?? null, $f['inicio'] ?? null, $f['fim'] ?? null), 'Extracto analítico de projectos.');
    }

    public function fluxo(): JsonResponse
    {
        $this->exigir(...self::LER);

        return RespostaApi::sucesso($this->analitico->fluxo(), 'Fluxo dos projectos.');
    }

    public function rentabilidade(Request $r): JsonResponse
    {
        $this->exigir('projectos_extracto_view');
        $f = $r->validate(['inicio' => ['required', 'date'], 'fim' => ['required', 'date', 'after_or_equal:inicio']]);

        return RespostaApi::sucesso($this->analitico->rentabilidade($f['inicio'], $f['fim']), 'Rentabilidade dos projectos.');
    }

    public function configuracao(): JsonResponse
    {
        $this->exigir(...self::LER);

        return RespostaApi::sucesso($this->projetos->configuracao(), 'Configuração dos projectos.');
    }

    public function guardarConfiguracao(Request $r): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['produto_subempreitada_id' => ['nullable', 'integer'], 'produto_faturacao_id' => ['nullable', 'integer']]);
        foreach ($d as $chave => $id) {
            if ($id && ! Produto::query()->whereKey($id)->exists()) {
                throw new ErroNegocio('Artigo inexistente.', 'PRODUTO_INEXISTENTE', 422);
            }
            $this->projetos->definirConfiguracao(null, $chave, $id ? (int) $id : null);
        }

        return RespostaApi::sucesso($this->projetos->configuracao(), 'Configuração dos projectos gravada.');
    }

    private function validar(Request $r, ?Projeto $p = null): array
    {
        return $r->validate([
            'codigo' => ['nullable', 'string', 'max:50'], 'nome' => [$p ? 'sometimes' : 'required', 'string', 'max:255'],
            'tipo' => ['nullable', Rule::in(Projeto::TIPOS)], 'estado' => ['nullable', Rule::in([...Projeto::ESTADOS, 'EM_CURSO'])],
            'unidade_negocio_id' => ['nullable', 'integer'], 'centro_custo_id' => ['nullable', 'integer'],
            'cliente_id' => ['nullable', 'integer'], 'encomenda_venda_id' => ['nullable', 'integer'],
        ]);
    }
}
