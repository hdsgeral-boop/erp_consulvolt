<?php

namespace App\Http\Controllers\Api\Orcamento;

use App\Http\Controllers\Controller;
use App\Models\LinhaOrcamento;
use App\Models\LogAlertaOrcamental;
use App\Models\OrcamentoAnual;
use App\Models\PedidoExtrapolacaoOrcamento;
use App\Models\RubricaOrcamental;
use App\Services\Orcamento\FormatoOrcamento;
use App\Services\Orcamento\ServicoControloOrcamental;
use App\Services\Orcamento\ServicoExecucaoOrcamental;
use App\Services\Orcamento\ServicoOrcamentos;
use App\Services\Orcamento\ServicoRubricasOrcamentais;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** /api/orcamento — rubricas, orçamentos (ciclo, versões, hierarquia) e controlo orçado × realizado. */
final class OrcamentoController extends Controller
{
    /**
     * Quem grava documentos sujeitos ao controlo orçamental (compras, tesouraria, lançamentos) ou acompanha os alertas:
     * pode simular a verificação (revela a disponibilidade de uma rubrica) e pedir a aprovação de um excesso (ADR-069).
     */
    private const PERMISSOES_DOCUMENTOS_CONTROLADOS = ['compras_ped_criar', 'compras_enc_criar', 'compras_fact_registar', 'teso_doc_emitir', 'lancamentos_post', 'orc_alertas_view'];

    public function __construct(
        private readonly ServicoRubricasOrcamentais $rubricas,
        private readonly ServicoOrcamentos $orcamentos,
        private readonly ServicoExecucaoOrcamental $execucao,
        private readonly ServicoControloOrcamental $controlo,
    ) {}

    // ───────────── Rubricas ─────────────

    public function rubricas(Request $r): JsonResponse
    {
        $this->exigir('orc_rubricas_view', 'orc_orcamentos_view', 'orc_controlo_view');
        $f = $r->validate(['tipo' => ['nullable', 'in:EXPLORACAO,TESOURARIA']]);

        return $this->ok(RubricaOrcamental::query()->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))->orderBy('tipo')->orderBy('ordem')->orderBy('codigo')->get(),
            'Rubricas orçamentais.');
    }

    public function guardarRubrica(Request $r, ?int $rubrica = null): JsonResponse
    {
        $this->exigir('orc_rubricas_edit');
        $d = $r->validate(['tipo' => ['required', 'in:EXPLORACAO,TESOURARIA'], 'codigo' => ['required', 'string', 'max:50'], 'nome' => ['required', 'string', 'max:255'],
            'natureza' => ['required', 'in:PROVEITO,CUSTO,RECEBIMENTO,PAGAMENTO'], 'grupo' => ['nullable', 'string', 'max:50'], 'ordem' => ['nullable', 'integer'],
            'ativo' => ['nullable', 'boolean'], 'descricao' => ['nullable', 'string', 'max:2000'], 'contas' => ['required', 'array', 'min:1'],
            'contas.*.codigo' => ['required', 'string', 'max:20'], 'contas.*.prefixo' => ['nullable', 'boolean'], 'controlo' => ['nullable', 'array'],
            'controlo.modo' => ['nullable', Rule::in(ServicoRubricasOrcamentais::MODOS_CONTROLO)], 'controlo.aviso_pct' => ['nullable', 'numeric', 'min:0'],
            'controlo.limite_pct' => ['nullable', 'numeric', 'min:0'], 'controlo.base' => ['nullable', 'in:ACUMULADO,ANO'],
            'indutor' => ['nullable', 'string', 'max:50'], 'cambial_pct' => ['nullable', 'numeric', 'between:0,100'], 'variavel_pct' => ['nullable', 'numeric', 'between:0,100']]);
        $x = $rubrica ? RubricaOrcamental::query()->findOrFail($rubrica) : null;
        $res = $this->rubricas->guardar($d, $x);

        return $x ? $this->ok($res, 'Rubrica actualizada.') : $this->novo($res, 'Rubrica criada.');
    }

    public function eliminarRubrica(int $rubrica): JsonResponse
    {
        $this->exigir('orc_rubricas_edit');
        $this->rubricas->eliminar(RubricaOrcamental::query()->findOrFail($rubrica));

        return $this->ok(null, 'Rubrica eliminada.');
    }

    public function criarRubricasBase(Request $r): JsonResponse
    {
        $this->exigir('orc_rubricas_edit');
        $d = $r->validate(['tipo' => ['required', 'in:EXPLORACAO,TESOURARIA']]);
        $res = $this->rubricas->criarBase($d['tipo']);

        return $this->ok($res, count($res['criadas']).' rubrica(s) criada(s); '.count($res['ignoradas']).' ignorada(s).');
    }

    // ───────────── Orçamentos ─────────────

    public function orcamentos(Request $r): JsonResponse
    {
        $this->exigir('orc_orcamentos_view', 'orc_controlo_view', 'orc_contributo');
        $f = $r->validate(['ano' => ['nullable', 'integer'], 'tipo' => ['nullable', 'in:EXPLORACAO,TESOURARIA'], 'estado' => ['nullable', 'string', 'max:30'],
            'pesquisa' => ['nullable', 'string', 'max:100'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        // paginado (ADR-064): «dados» continua a ser a lista; metadados.paginacao descreve a página (por omissão 200 por página)
        $pagina = OrcamentoAnual::query()->when($f['ano'] ?? null, fn ($q, $a) => $q->where('ano', $a))->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($f['pesquisa'] ?? null, fn ($q, $p) => $q->where('nome', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $p).'%'))
            ->orderByDesc('ano')->orderBy('tipo')->orderByDesc('versao')->orderByDesc('id')
            ->paginate((int) ($f['por_pagina'] ?? 200), ['*'], 'pagina', (int) ($f['pagina'] ?? 1));

        return RespostaApi::sucesso(FormatoOrcamento::normalizar($pagina->getCollection()), 'Orçamentos.', 200, ['paginacao' => [
            'pagina_atual' => $pagina->currentPage(), 'por_pagina' => $pagina->perPage(), 'total' => $pagina->total(), 'ultima_pagina' => $pagina->lastPage()]]);
    }

    public function orcamento(int $orcamento): JsonResponse
    {
        $this->exigir('orc_orcamentos_view', 'orc_controlo_view', 'orc_contributo');
        $o = OrcamentoAnual::query()->findOrFail($orcamento);

        return $this->ok($o->toArray() + ['linhas' => LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->get(),
            'filhos' => OrcamentoAnual::query()->where('orcamento_pai_id', $o->id)->get(['id', 'nome', 'versao', 'estado', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'responsavel'])],
            'Orçamento.');
    }

    public function criar(Request $r): JsonResponse
    {
        $this->exigir('orc_editar');
        $e = app(ContextoEmpresa::class)->obrigatorio();
        $existe = fn (string $t) => Rule::exists($t, 'id')->where('empresa_id', $e);
        $d = $r->validate(['ano' => ['required', 'integer', 'between:2000,2100'], 'tipo' => ['required', 'in:EXPLORACAO,TESOURARIA'], 'nome' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:2000'], 'unidade_negocio_id' => ['nullable', 'integer', $existe('unidades_negocio')],
            'centro_custo_id' => ['nullable', 'integer', $existe('centros_custo')], 'projeto_id' => ['nullable', 'integer', $existe('projetos')],
            'metodo' => ['nullable', 'in:HISTORICO,BASE_ZERO'], 'origem' => ['nullable', 'in:REALIZADO_ANTERIOR,ORCAMENTO_ANTERIOR'],
            'crescimento_proveitos_pct' => ['nullable', 'numeric', 'between:-100,1000'], 'crescimento_custos_pct' => ['nullable', 'numeric', 'between:-100,1000'],
            'inflacao_pct' => ['nullable', 'numeric', 'between:-100,1000'], 'abordagem' => ['nullable', 'in:TOP_DOWN,BOTTOM_UP'], 'responsavel' => ['nullable', 'string', 'max:100'],
            'orcamento_pai_id' => ['nullable', 'integer', $existe('orcamentos_anuais')], 'dimensao_filhos' => ['nullable', 'in:UN,CC,PROJETO'],
            'saldo_inicial' => ['nullable', 'numeric']]);

        return $this->novo($this->orcamentos->criar($d), 'Orçamento criado.');
    }

    public function gravarValores(Request $r, int $orcamento): JsonResponse
    {
        $o = OrcamentoAnual::query()->findOrFail($orcamento);
        ServicoOrcamentos::eResponsavel($o) ? $this->exigir('orc_contributo', 'orc_editar') : $this->exigir('orc_editar');
        $d = $r->validate(['linhas' => ['present', 'array'], 'linhas.*.rubrica_orcamental_id' => ['required', 'integer'], 'linhas.*.valores' => ['required', 'array', 'size:12'],
            'linhas.*.valores.*' => ['numeric'], 'linhas.*.notas' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->orcamentos->gravarValores($o, $d['linhas']), 'Valores gravados.');
    }

    public function submeter(int $orcamento): JsonResponse
    {
        $o = OrcamentoAnual::query()->findOrFail($orcamento);
        ServicoOrcamentos::eResponsavel($o) ? $this->exigir('orc_contributo', 'orc_submeter') : $this->exigir('orc_submeter');

        return $this->ok($this->orcamentos->submeter($o), 'Orçamento submetido.');
    }

    public function aprovar(int $orcamento): JsonResponse
    {
        $this->exigir('orc_aprovar');

        return $this->ok($this->orcamentos->aprovar(OrcamentoAnual::query()->findOrFail($orcamento)), 'Orçamento aprovado.');
    }

    public function devolver(Request $r, int $orcamento): JsonResponse
    {
        $this->exigir('orc_aprovar');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->ok($this->orcamentos->devolver(OrcamentoAnual::query()->findOrFail($orcamento), $d['motivo']), 'Orçamento devolvido para revisão.');
    }

    public function novaVersao(int $orcamento): JsonResponse
    {
        $this->exigir('orc_editar');

        return $this->novo($this->orcamentos->novaVersao(OrcamentoAnual::query()->findOrFail($orcamento)), 'Nova versão criada (a anterior vigora até à aprovação).');
    }

    public function eliminar(int $orcamento): JsonResponse
    {
        $this->exigir('orc_editar');
        $this->orcamentos->eliminar(OrcamentoAnual::query()->findOrFail($orcamento));

        return $this->ok(null, 'Orçamento eliminado.');
    }

    public function repartir(Request $r, int $orcamento): JsonResponse
    {
        $this->exigir('orc_hierarquia');
        $d = $r->validate(['criterio' => ['required', 'in:IGUAL,REALIZADO,MANUAL'], 'percentagens' => ['nullable', 'array'], 'percentagens.*' => ['numeric', 'min:0']]);

        return $this->ok($this->orcamentos->repartirTopDown(OrcamentoAnual::query()->findOrFail($orcamento), $d['criterio'], $d['percentagens'] ?? []), 'Orçamento repartido.');
    }

    public function pedirContributos(Request $r, int $orcamento): JsonResponse
    {
        $this->exigir('orc_hierarquia');
        $d = $r->validate(['filhos' => ['required', 'array', 'min:1'], 'filhos.*.unidade_negocio_id' => ['nullable', 'integer'], 'filhos.*.centro_custo_id' => ['nullable', 'integer'],
            'filhos.*.projeto_id' => ['nullable', 'integer'], 'filhos.*.responsavel' => ['required', 'string', 'max:100'], 'prazo' => ['nullable', 'date'], 'preencher' => ['nullable', 'boolean']]);

        return $this->novo($this->orcamentos->pedirContributos(OrcamentoAnual::query()->findOrFail($orcamento), $d['filhos'], $d['prazo'] ?? null, (bool) ($d['preencher'] ?? false)),
            'Contributos pedidos.');
    }

    public function consolidar(int $orcamento): JsonResponse
    {
        $this->exigir('orc_hierarquia');

        return $this->ok($this->orcamentos->consolidar(OrcamentoAnual::query()->findOrFail($orcamento)), 'Contributos consolidados.');
    }

    // ───────────── Controlo nos documentos ─────────────

    /** @return array<string, list<mixed>> */
    private function regrasDocumento(): array
    {
        return ['tipo' => ['required', 'in:EXPLORACAO,TESOURARIA'], 'origem' => ['required', 'string', 'max:30'], 'documento' => ['required', 'string', 'max:100'],
            'data' => ['required', 'date'], 'linhas' => ['required', 'array', 'min:1', 'max:500'], 'linhas.*.codigo_conta' => ['required', 'string', 'max:20'],
            'linhas.*.valor' => ['required', 'numeric'], 'linhas.*.unidade_negocio_id' => ['nullable', 'integer'], 'linhas.*.centro_custo_id' => ['nullable', 'integer'],
            'linhas.*.projeto_id' => ['nullable', 'integer']];
    }

    /** POST /verificar — simulação do controlo antes de gravar o documento (nada é registado). */
    public function verificar(Request $r): JsonResponse
    {
        // OWASP A01: a simulação revela orçado/consumido da rubrica — mesma lista any-of do pedido de excesso
        $this->exigir(...self::PERMISSOES_DOCUMENTOS_CONTROLADOS);
        $d = $r->validate($this->regrasDocumento());

        return $this->ok($this->controlo->verificar($d['tipo'], $d['data'], $d['linhas']), 'Verificação orçamental.', ['documento']);   // aqui «documento» é o valor
    }

    public function pedirExcesso(Request $r): JsonResponse
    {
        $this->exigir(...self::PERMISSOES_DOCUMENTOS_CONTROLADOS);
        $d = $r->validate($this->regrasDocumento() + ['motivo' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->novo($this->controlo->pedirExcesso($d['tipo'], ['origem' => $d['origem'], 'documento' => $d['documento'], 'data' => substr($d['data'], 0, 10)],
            $d['linhas'], $d['motivo']), 'Pedido de aprovação do excesso enviado.');
    }

    public function pedidosExcesso(Request $r): JsonResponse
    {
        $this->exigir('orc_alertas_view', 'orc_aprovar_excesso');
        $f = $r->validate(['estado' => ['nullable', 'in:PENDENTE,APROVADO,REJEITADO,UTILIZADO']]);

        return $this->ok($this->comNomes(PedidoExtrapolacaoOrcamento::query()->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))->orderByDesc('id')->get()),
            'Pedidos de excesso orçamental.');
    }

    public function decidirExcesso(Request $r, int $pedido): JsonResponse
    {
        $this->exigir('orc_aprovar_excesso');
        $d = $r->validate(['decisao' => ['required', 'in:APROVADO,REJEITADO'], 'nota' => ['nullable', 'string', 'max:1000']]);

        return $this->ok($this->controlo->decidirPedido(PedidoExtrapolacaoOrcamento::query()->findOrFail($pedido), $d['decisao'], $d['nota'] ?? null),
            $d['decisao'] === 'APROVADO' ? 'Excesso aprovado: o documento já pode ser gravado.' : 'Excesso rejeitado.');
    }

    public function alertas(): JsonResponse
    {
        $this->exigir('orc_alertas_view');

        return $this->ok($this->comNomes(LogAlertaOrcamental::query()->orderByDesc('em')->orderByDesc('id')->limit(300)->get()), 'Registo de alertas orçamentais.');
    }

    public function monitor(Request $r): JsonResponse
    {
        $this->exigir('orc_alertas_view', 'orc_controlo_view');
        $d = $r->validate(['ano' => ['required', 'integer'], 'mes' => ['nullable', 'integer', 'between:1,12']]);

        return $this->ok($this->controlo->monitor((int) $d['ano'], (int) ($d['mes'] ?? now()->month)), 'Monitor de consumo orçamental.');
    }

    // ───────────── Controlo ─────────────

    public function controlo(Request $r, int $orcamento): JsonResponse
    {
        $this->exigir('orc_controlo_view');
        $d = $r->validate(['mes' => ['nullable', 'integer', 'between:1,12'], 'vista' => ['nullable', 'in:MES,ACUMULADO,ANO']]);

        return $this->ok($this->execucao->controlo(OrcamentoAnual::query()->findOrFail($orcamento), (int) ($d['mes'] ?? now()->month), $d['vista'] ?? 'ACUMULADO'),
            'Controlo orçamental.');
    }

    /**
     * Pedidos de excesso e alertas com a rubrica ({id, codigo, nome}) e o orçamento ({id, nome, ano, tipo, versao}) por nome (ADR-064).
     *
     * @param  Collection<int, Model>  $itens
     */
    private function comNomes($itens): array
    {
        $rubricas = RubricaOrcamental::query()->withTrashed()->whereIn('id', $itens->pluck('rubrica_orcamental_id')->filter()->unique()->values()->all())
            ->get(['id', 'codigo', 'nome'])->keyBy('id');
        $orcamentos = OrcamentoAnual::query()->whereIn('id', $itens->pluck('orcamento_anual_id')->filter()->unique()->values()->all())
            ->get(['id', 'nome', 'ano', 'tipo', 'versao'])->keyBy('id');

        return $itens->map(fn ($i) => $i->toArray() + [
            'rubrica' => ($x = $rubricas[$i->rubrica_orcamental_id] ?? null) ? ['id' => $x->id, 'codigo' => $x->codigo, 'nome' => $x->nome] : null,
            'orcamento' => ($o = $orcamentos[$i->orcamento_anual_id] ?? null) ? ['id' => $o->id, 'nome' => $o->nome, 'ano' => $o->ano, 'tipo' => $o->tipo, 'versao' => $o->versao] : null,
        ])->values()->all();
    }

    /** Resposta com os valores monetários em texto decimal de 2 casas (ADR-064). */
    private function ok(mixed $dados, string $mensagem, array $extra = []): JsonResponse
    {
        return RespostaApi::sucesso(FormatoOrcamento::normalizar($dados, $extra), $mensagem);
    }

    private function novo(mixed $dados, string $mensagem): JsonResponse
    {
        return RespostaApi::criado(FormatoOrcamento::normalizar($dados), $mensagem);
    }
}
