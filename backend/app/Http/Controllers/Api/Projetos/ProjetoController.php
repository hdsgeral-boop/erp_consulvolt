<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\AssuntoMetodoTrabalho;
use App\Models\LogAtividadeProjeto;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Services\Projetos\RegrasMetasProjetos;
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
        $q = Projeto::query()->with(['cliente' => fn ($c) => $c->withTrashed()->select(['id', 'nome', 'nif'])])   // nome do cliente na carteira (ADR-064)
            ->when($f['estado'] ?? null, fn ($q, $v) => $v === 'ACTIVO' ? $q->whereIn('estado', ['ACTIVO', 'EM_CURSO']) : $q->where('estado', $v))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($f['pesquisa'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('codigo', 'ilike', "%{$v}%")->orWhere('nome', 'ilike', "%{$v}%")));

        $pagina = $q->orderBy('codigo')->paginate($f['por_pagina'] ?? 50, ['*'], 'pagina', $f['pagina'] ?? 1);
        // semáforo da carteira: indicadores de prazo e orçamento só dos projectos desta página (a regra da cor está no ecrã)
        $semaforo = $this->analitico->indicadoresSemaforo($pagina->getCollection()->pluck('id')->all());
        $pagina->getCollection()->transform(fn (Projeto $p) => $p->toArray() + ['semaforo' => $semaforo[$p->id] ?? null]);

        return RespostaApi::paginado($pagina, null, 'Carteira de projectos.');
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
            'cliente' => $p->cliente()->withTrashed()->first(['id', 'nome']),
            // Versão Actual (projectos_dashboard.js:400-402, 555-557): projecto nascido de um assunto do Método de Trabalho
            'assunto_metodo' => self::assuntoDeOrigem($p)], 'Projecto.');
    }

    /** Número e título do assunto de origem (da mesma empresa do projecto); null quando o projecto não nasceu de um assunto. */
    private static function assuntoDeOrigem(Projeto $p): ?array
    {
        if (! $p->assunto_metodo_id) {
            return null;
        }
        $a = AssuntoMetodoTrabalho::query()->where('empresa_id', $p->empresa_id)->whereKey($p->assunto_metodo_id)->first(['id', 'numero', 'titulo', 'estado']);

        return $a ? ['id' => $a->id, 'numero' => $a->numero, 'titulo' => $a->titulo, 'estado' => $a->estado] : null;
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
        // filtros simples do separador «Actividade»: texto (acção ou detalhe), acção, utilizador e intervalo de datas
        $f = $r->validate(['por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1'],
            'pesquisa' => ['nullable', 'string', 'max:100'], 'acao' => ['nullable', 'string', 'max:255'], 'utilizador' => ['nullable', 'string', 'max:255'],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de']]);
        $termo = isset($f['pesquisa']) && trim($f['pesquisa']) !== '' ? '%'.addcslashes(trim($f['pesquisa']), '%_\\').'%' : null;
        $q = LogAtividadeProjeto::query()->where('projeto_id', $p->id)
            ->when($termo, fn ($q) => $q->where(fn ($w) => $w->where('acao', 'ilike', $termo)->orWhere('detalhes', 'ilike', $termo)))
            ->when($f['acao'] ?? null, fn ($q, $v) => $q->where('acao', $v))
            ->when($f['utilizador'] ?? null, fn ($q, $v) => $q->where('nome_utilizador', $v))
            ->when($f['de'] ?? null, fn ($q, $v) => $q->where('ocorrido_em', '>=', "{$v} 00:00:00"))
            ->when($f['ate'] ?? null, fn ($q, $v) => $q->where('ocorrido_em', '<=', "{$v} 23:59:59"));

        return RespostaApi::paginado($q->orderByDesc('ocorrido_em')->orderByDesc('id')
            ->paginate($f['por_pagina'] ?? 50, ['*'], 'pagina', $f['pagina'] ?? 1), null, 'Actividade do projecto.');
    }

    /** Acções e utilizadores distintos do registo de actividade do projecto (opções dos filtros). */
    public function atividadeFiltros(int $projeto): JsonResponse
    {
        $this->exigir(...self::LER);
        $p = Projeto::query()->findOrFail($projeto);
        $base = fn () => LogAtividadeProjeto::query()->where('projeto_id', $p->id);

        return RespostaApi::sucesso(['acoes' => $base()->distinct()->orderBy('acao')->limit(300)->pluck('acao')->filter()->values(),
            'utilizadores' => $base()->whereNotNull('nome_utilizador')->distinct()->orderBy('nome_utilizador')->limit(300)->pluck('nome_utilizador')->filter()->values()],
            'Filtros da actividade.');
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
            // Versão Actual: calendário de trabalho (modelo ou dias 0..6 com 0 / 0,5 / 1) e descrição
            'calendario' => ['nullable', 'array'], 'calendario.modelo' => ['nullable', Rule::in([...array_keys(RegrasMetasProjetos::MODELOS_CAL), 'PERSONALIZADO'])],
            'calendario.horas_dia' => ['nullable', 'numeric', 'between:0.5,24'], 'calendario.dias' => ['nullable', 'array', 'size:7'],
            'calendario.dias.*' => ['numeric', Rule::in(['0', '0.5', '1'])], 'descricao' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array'], 'tags.*' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
