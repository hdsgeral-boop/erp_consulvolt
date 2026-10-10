<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contabilidade\CriarLancamentoRequest;
use App\Http\Resources\Contabilidade\LancamentoResource;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Utilizador;
use App\Services\Contabilidade\ServicoClassificacaoLancamentos;
use App\Services\Contabilidade\ServicoDescontabilizacaoLancamentos;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoOrigemLancamentos;
use App\Services\Contabilidade\ServicoTransferenciaLancamentos;
use App\Services\Orcamento\ServicoControloOrcamental;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** /api/contabilidade/lancamentos — ecrã "lancamentos" do legado. */
final class LancamentoController extends Controller
{
    public function __construct(private readonly ServicoLancamentos $lancamentos) {}

    /** GET — linhas de lançamentos com filtros (paridade: o legado exige pelo menos um critério e exclui a classe 9). */
    public function index(Request $request): JsonResponse
    {
        $this->exigir('lancamentos_view');
        $f = $request->validate(self::regrasFiltros() + [
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        // 8.1.2: nome da conta (subconsulta ao plano da empresa) e código do projecto na mesma consulta (sem N+1)
        $q = LancamentoContabil::query()->with(self::comTerceiro() + self::comProjeto())
            ->addSelect(['conta_descricao' => PlanoConta::query()->select('descricao')->whereColumn('plano_contas.codigo', 'lancamentos_contabeis.codigo_conta')->limit(1)]);
        $pagina = ServicoClassificacaoLancamentos::filtrar($q, $f)
            ->orderByDesc('data_documento')->orderByDesc('id')
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));

        return RespostaApi::paginado($pagina, LancamentoResource::class);
    }

    /**
     * POST /classificacao — A-05: notas em massa (DEMO, fluxo, UN, CC) nas linhas seleccionadas (`ids`) ou em todas as
     * filtradas (`filtros`), e edição da descrição/terceiro na ficha. Nunca altera conta, valor, D/C, data nem diário.
     */
    public function classificacao(Request $request, ServicoClassificacaoLancamentos $servico): JsonResponse
    {
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $daEmpresa = fn (string $t) => Rule::exists($t, 'id')->where('empresa_id', $empresa);
        $d = $request->validate([
            'ids' => ['required_without:filtros', 'array', 'min:1', 'max:'.ServicoClassificacaoLancamentos::MAXIMO], 'ids.*' => ['integer'],
            'filtros' => ['required_without:ids', 'array'],
            'campos' => ['required', 'array', 'min:1'],
            'campos.nota_demonstracao_id' => ['nullable', 'integer', $daEmpresa('notas_demonstracao_resultados')],
            'campos.nota_fluxo_caixa_id' => ['nullable', 'integer', $daEmpresa('notas_fluxo_caixa')],
            'campos.unidade_negocio_id' => ['nullable', 'integer', $daEmpresa('unidades_negocio')],
            'campos.centro_custo_id' => ['nullable', 'integer', $daEmpresa('centros_custo')],
            'campos.descricao' => ['nullable', 'string', 'max:1000'],
            'campos.terceiro_id' => ['nullable', 'integer', $daEmpresa('terceiros')],
        ], [], ['campos' => 'campos a alterar', 'ids' => 'linhas']);
        $ficha = array_intersect_key($d['campos'], array_flip(ServicoClassificacaoLancamentos::CAMPOS_FICHA)) !== [];
        // descrição e terceiro: só na ficha (lancamentos_editar); notas/UN/CC: notas em massa ou edição
        $ficha ? $this->exigir('lancamentos_editar') : $this->exigir('lancamentos_bulk_notes', 'lancamentos_editar');
        $filtros = isset($d['ids']) ? null : validator($d['filtros'], self::regrasFiltros())->validate();
        $r = $servico->aplicar(isset($d['ids']) ? array_map('intval', $d['ids']) : null, $filtros, $d['campos']);

        return RespostaApi::sucesso($r, "Classificação aplicada a {$r['actualizadas']} linha(s)."
            .($r['propagadas'] ? " Também actualizadas {$r['propagadas']} linha(s) do estorno/original correspondente." : ''));
    }

    /** POST /classificacao/previsao — 8.1.1: n.º de linhas que a alteração em massa vai afectar (antes de confirmar). */
    public function previsaoClassificacao(Request $request): JsonResponse
    {
        $this->exigir('lancamentos_bulk_notes', 'lancamentos_editar');
        [$ids, $filtros] = $this->alvo($request);
        $q = LancamentoContabil::query();
        $ids !== null ? $q->whereKey($ids) : ServicoClassificacaoLancamentos::filtrar($q, $filtros);
        $n = $q->limit(ServicoClassificacaoLancamentos::MAXIMO + 1)->pluck('id')->count();

        return RespostaApi::sucesso(['linhas' => min($n, ServicoClassificacaoLancamentos::MAXIMO), 'excede' => $n > ServicoClassificacaoLancamentos::MAXIMO,
            'maximo' => ServicoClassificacaoLancamentos::MAXIMO], "A alteração afecta {$n} linha(s).");
    }

    /**
     * POST /descontabilizar — 8.1.1 «Descontabilizar Selecionados»: estorna os lançamentos das linhas seleccionadas (`ids`)
     * ou de todas as filtradas (`filtros`). `simular` devolve a análise (o que é estornado, bloqueado ou ignorado).
     */
    public function descontabilizar(Request $request, ServicoDescontabilizacaoLancamentos $servico): JsonResponse
    {
        $this->exigir('contab_lanc_del');
        [$ids, $filtros] = $this->alvo($request);
        $simular = $request->boolean('simular');
        $d = $request->validate(['motivo' => [Rule::requiredIf(! $simular), 'nullable', 'string', 'min:5', 'max:300']], [], ['motivo' => 'motivo da descontabilização']);
        if ($simular) {
            $a = $servico->analisar($ids, $filtros);

            return RespostaApi::sucesso($a, "{$a['a_estornar']} lançamento(s) a descontabilizar, {$a['bloqueados']} bloqueado(s), {$a['ignorados']} ignorado(s).");
        }
        $r = $servico->executar($ids, $filtros, trim((string) $d['motivo']));

        return RespostaApi::sucesso($r, count($r['estornados']).' lançamento(s) descontabilizado(s) por estorno.'.($r['ignorados'] ? " {$r['ignorados']} ignorado(s) (já estornados ou estornos)." : ''));
    }

    /** POST /{id}/transferir — M-09: transfere o lançamento para outra empresa (criação no destino + estorno na origem). */
    public function transferir(Request $request, int $lancamento, ServicoTransferenciaLancamentos $servico): JsonResponse
    {
        $this->exigir('contab_lanc_transferir');
        $d = $request->validate([
            'empresa_destino_id' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ], [], ['empresa_destino_id' => 'empresa de destino', 'motivo' => 'motivo da transferência']);
        /** @var Utilizador $actor */
        $actor = $request->user();
        $r = $servico->transferir(LancamentoContabil::query()->findOrFail($lancamento), (int) $d['empresa_destino_id'], $d['motivo'], $actor);

        return RespostaApi::criado($r, "Lançamento transferido para {$r['destino']['empresa']} ({$r['destino']['numero_lan']}); estorno {$r['estorno']['numero_lan']} na origem.");
    }

    /** GET /transferir/destinos — 8.1.3: empresas acessíveis onde o utilizador pode registar lançamentos (excepto a activa). */
    public function destinosTransferencia(Request $request, ServicoTransferenciaLancamentos $servico): JsonResponse
    {
        $this->exigir('contab_lanc_transferir');
        /** @var Utilizador $actor */
        $actor = $request->user();

        return RespostaApi::sucesso($servico->destinos($actor), 'Empresas de destino obtidas com sucesso.');
    }

    /** GET /{id}/origem — 8.1.3 «Ver Documento de Origem» / «Ver Fluxo Completo»: módulo e documento que geraram o lançamento. */
    public function origem(int $lancamento, ServicoOrigemLancamentos $origens): JsonResponse
    {
        $this->exigir('lancamentos_view');
        $linhas = $this->lancamentos->documento(LancamentoContabil::query()->findOrFail($lancamento));
        $origem = $origens->origem($linhas);
        // só ligações http(s) (o URL vem de importações: nunca «javascript:» nem outros esquemas)
        $url = $linhas->pluck('url_documento')->map(fn ($u) => trim((string) $u))->first(fn ($u) => (bool) preg_match('#^https?://#i', $u));

        return RespostaApi::sucesso(['origem' => $origem, 'manual' => $origem === null, 'url_documento' => $url,
            'tipo_origem' => $linhas->first()?->tipo_origem], $origem ? "Lançamento gerado pelo módulo {$origem['nome']}." : 'Lançamento da Contabilidade (sem documento de módulo).');
    }

    /** @return array<string, list<mixed>> filtros da lista (também usados na classificação e na descontabilização por filtro) */
    private static function regrasFiltros(): array
    {
        return [
            // listas coladas («Colar múltiplas contas/documentos»): texto limitado aqui e no máximo 500 valores (ServicoClassificacaoLancamentos::lista)
            'diario_id' => ['nullable', 'integer'], 'codigo_conta' => ['nullable', 'string', 'max:20'], 'filtro_contas' => ['nullable', 'string', 'max:12000'],
            'numero_lan' => ['nullable', 'string', 'max:20000'], 'numero_documento' => ['nullable', 'string', 'max:20000'], 'referencia' => ['nullable', 'string', 'max:20000'],
            'terceiro_id' => ['nullable', 'integer'], 'unidade_negocio_id' => ['nullable', 'integer'], 'centro_custo_id' => ['nullable', 'integer'],
            'data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d'],
            'data_doc_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_doc_fim' => ['nullable', 'date_format:Y-m-d'],
            'criado_inicio' => ['nullable', 'date_format:Y-m-d'], 'criado_fim' => ['nullable', 'date_format:Y-m-d'],
            'pesquisa' => ['nullable', 'string', 'max:200'], 'incluir_classe_9' => ['nullable', 'boolean'],
            'sem' => ['nullable', 'array', 'max:4'], 'sem.*' => ['string', 'in:demo,fluxo,un,cc'],
        ];
    }

    /**
     * Linhas alvo de uma operação em massa: `ids` (seleccionadas) ou `filtros` (todas as filtradas), validados.
     *
     * @return array{0: list<int>|null, 1: array<string, mixed>}
     */
    private function alvo(Request $request): array
    {
        $d = $request->validate([
            'ids' => ['required_without:filtros', 'array', 'min:1', 'max:'.ServicoClassificacaoLancamentos::MAXIMO], 'ids.*' => ['integer'],
            'filtros' => ['required_without:ids', 'array'],
        ], [], ['ids' => 'linhas']);
        if (isset($d['ids'])) {
            return [array_values(array_unique(array_map('intval', $d['ids']))), []];
        }

        return [null, validator($d['filtros'], self::regrasFiltros())->validate()];
    }

    /** GET /{id} — o lançamento (documento) completo a que a linha pertence. */
    public function show(int $lancamento): JsonResponse
    {
        $this->exigir('lancamentos_view');
        $linhas = $this->lancamentos->documento(LancamentoContabil::query()->findOrFail($lancamento));

        return RespostaApi::sucesso($this->documento($linhas), 'Lançamento obtido com sucesso.');
    }

    /** POST — cria um lançamento equilibrado (tarefa "Gravar lançamentos no diário"). */
    public function store(CriarLancamentoRequest $request): JsonResponse
    {
        $this->exigir('lancamentos_post');
        // lançamento manual com controlo orçamental (ui_lancamentos.js:1686): débitos consomem, créditos abatem
        $dados = $this->lancamentos->prepararMoedaManual($request->validated());   // M7 (js/moedas_lancamentos.js)
        $this->lancamentos->exigirNotasFluxoManual($dados);   // M6 (js/ui_lancamentos.js:1707-1731)
        $linhas = DB::transaction(fn () => $this->criarComOrcamento($dados));

        return RespostaApi::criado($this->documento($linhas), "Lançamento {$linhas->first()->numero_lan} gravado com sucesso.");
    }

    /**
     * POST /{id}/corrigir — 8.1.3 «Editar Lançamento Completo» (legado: editFullJournalEntry, que regravava as linhas).
     * Sem apagar nada (ADR-016): numa só transacção estorna o original e grava o lançamento corrigido (equilíbrio, contas
     * de movimento, exercício aberto, notas de fluxo e controlo orçamental como um lançamento novo). Lançamentos gerados
     * por um módulo não se editam aqui (só a classificação): corrigem-se no módulo de origem.
     */
    public function corrigir(Request $request, int $lancamento, ServicoOrigemLancamentos $origens): JsonResponse
    {
        $this->exigir('lancamentos_post');
        $this->exigir('lancamentos_editar', 'contab_lanc_transferir');
        $motivo = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']], [], ['motivo' => 'motivo da correcção'])['motivo'];
        $dados = app(CriarLancamentoRequest::class)->validated();   // as regras do lançamento novo (validadas depois da autorização)
        $original = LancamentoContabil::query()->findOrFail($lancamento);
        $doc = $this->lancamentos->documento($original);
        if ($origem = $origens->origem($doc)) {
            throw new ErroNegocio("Este lançamento pertence ao módulo {$origem['nome']}: aqui só se edita a classificação. {$origem['instrucoes']}", 'LANCAMENTO_DE_MODULO', 422, ['origem' => $origem]);
        }
        app(ServicoExercicios::class)->exigirAberto(app(ContextoEmpresa::class)->obrigatorio(), $doc->first()->data_documento->toDateString());
        $dados = $this->lancamentos->prepararMoedaManual($dados);
        $this->lancamentos->exigirNotasFluxoManual($dados);
        [$estorno, $linhas] = DB::transaction(function () use ($original, $dados, $motivo) {
            $estorno = $this->lancamentos->estornar($original, mb_substr("Correcção (editar lançamento completo): {$motivo}", 0, 500));

            return [$estorno, $this->criarComOrcamento($dados)];
        });

        return RespostaApi::criado($this->documento($linhas) + ['estorno' => ['numero_lan' => (string) $estorno->first()->numero_lan, 'primeira_linha_id' => (int) $estorno->first()->id]],
            "Lançamento corrigido: {$linhas->first()->numero_lan} gravado e original estornado ({$estorno->first()->numero_lan}).");
    }

    /** POST /{id}/estornar — estorno com rasto (ADR-016; tarefa "Transferir e estornar lançamentos"). */
    public function estornar(Request $request, int $lancamento): JsonResponse
    {
        $this->exigir('contab_lanc_transferir');
        $dados = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo do estorno']);
        $estorno = $this->lancamentos->estornar(LancamentoContabil::query()->findOrFail($lancamento), $dados['motivo']);

        return RespostaApi::criado($this->documento($estorno), "Estorno {$estorno->first()->numero_lan} gravado com sucesso.");
    }

    /**
     * Lançamento manual com controlo orçamental (ui_lancamentos.js:1686): débitos consomem, créditos abatem. Chamar dentro de uma transacção.
     *
     * @param  array<string, mixed>  $dados
     * @return Collection<int, LancamentoContabil>
     */
    private function criarComOrcamento(array $dados): Collection
    {
        $linhas = $this->lancamentos->criar($dados);
        $l0 = $linhas->first();
        app(ServicoControloOrcamental::class)->avaliar('EXPLORACAO',
            ['origem' => 'LANCAMENTO', 'documento' => (string) $l0->numero_lan, 'data' => $l0->data_documento->toDateString()],
            $linhas->map(fn ($l) => ['codigo_conta' => $l->codigo_conta, 'valor' => ($l->tipo_dc === 'D' ? 1 : -1) * (float) $l->valor,
                'unidade_negocio_id' => $l->unidade_negocio_id, 'centro_custo_id' => $l->centro_custo_id, 'projeto_id' => $l->projeto_id])->all(),
            ['numero_lan' => $l0->numero_lan]);

        return $linhas;
    }

    /** Terceiro das linhas numa só consulta (sem N+1); os eliminados (soft delete) continuam a mostrar o nome. */
    private static function comTerceiro(): array
    {
        return ['terceiro' => fn ($q) => $q->withTrashed()->select(['id', 'nome', 'nif'])];
    }

    /** Projecto das linhas (código e nome) numa só consulta; os eliminados continuam a mostrar o código. */
    private static function comProjeto(): array
    {
        return ['projeto' => fn ($q) => $q->withTrashed()->select(['id', 'codigo', 'nome'])];
    }

    /** @param  Collection<int, LancamentoContabil>  $linhas */
    private function documento(Collection $linhas): array
    {
        if ($linhas instanceof EloquentCollection) {
            $linhas->loadMissing(self::comTerceiro());
        }
        $primeira = $linhas->first();
        $debito = $linhas->where('tipo_dc', 'D')->reduce(fn ($a, $l) => bcadd($a, (string) $l->valor, 2), '0.00');
        $credito = $linhas->where('tipo_dc', 'C')->reduce(fn ($a, $l) => bcadd($a, (string) $l->valor, 2), '0.00');

        return [
            'diario_id' => $primeira->diario_id, 'numero_lan' => $primeira->numero_lan, 'numero_documento' => $primeira->numero_documento,
            'data_documento' => $primeira->data_documento?->toDateString(), 'debito' => $debito, 'credito' => $credito,
            'equilibrado' => bccomp($debito, $credito, 2) === 0,
            'linhas' => LancamentoResource::collection($linhas)->resolve(),
        ];
    }
}
