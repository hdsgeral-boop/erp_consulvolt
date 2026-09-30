<?php

namespace App\Http\Controllers\Api\Orcamento;

use App\Http\Controllers\Controller;
use App\Models\LinhaOrcamento;
use App\Models\LogAlertaOrcamental;
use App\Models\OrcamentoAnual;
use App\Models\PedidoExtrapolacaoOrcamento;
use App\Models\RubricaOrcamental;
use App\Services\Orcamento\ServicoControloOrcamental;
use App\Services\Orcamento\ServicoExecucaoOrcamental;
use App\Services\Orcamento\ServicoOrcamentos;
use App\Services\Orcamento\ServicoRubricasOrcamentais;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/orcamento — rubricas, orçamentos (ciclo, versões, hierarquia) e controlo orçado × realizado. */
final class OrcamentoController extends Controller
{
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

        return RespostaApi::sucesso(RubricaOrcamental::query()->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))->orderBy('tipo')->orderBy('ordem')->orderBy('codigo')->get(),
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

        return $x ? RespostaApi::sucesso($res, 'Rubrica actualizada.') : RespostaApi::criado($res, 'Rubrica criada.');
    }

    public function eliminarRubrica(int $rubrica): JsonResponse
    {
        $this->exigir('orc_rubricas_edit');
        $this->rubricas->eliminar(RubricaOrcamental::query()->findOrFail($rubrica));

        return RespostaApi::sucesso(null, 'Rubrica eliminada.');
    }

    public function criarRubricasBase(Request $r): JsonResponse
    {
        $this->exigir('orc_rubricas_edit');
        $d = $r->validate(['tipo' => ['required', 'in:EXPLORACAO,TESOURARIA']]);
        $res = $this->rubricas->criarBase($d['tipo']);

        return RespostaApi::sucesso($res, count($res['criadas']).' rubrica(s) criada(s); '.count($res['ignoradas']).' ignorada(s).');
    }

    // ───────────── Orçamentos ─────────────

    public function orcamentos(Request $r): JsonResponse
    {
        $this->exigir('orc_orcamentos_view', 'orc_controlo_view', 'orc_contributo');
        $f = $r->validate(['ano' => ['nullable', 'integer'], 'tipo' => ['nullable', 'in:EXPLORACAO,TESOURARIA']]);

        return RespostaApi::sucesso(OrcamentoAnual::query()->when($f['ano'] ?? null, fn ($q, $a) => $q->where('ano', $a))->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))
            ->orderByDesc('ano')->orderBy('tipo')->orderByDesc('versao')->get(), 'Orçamentos.');
    }

    public function orcamento(int $orcamento): JsonResponse
    {
        $this->exigir('orc_orcamentos_view', 'orc_controlo_view', 'orc_contributo');
        $o = OrcamentoAnual::query()->findOrFail($orcamento);

        return RespostaApi::sucesso($o->toArray() + ['linhas' => LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->get(),
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

        return RespostaApi::criado($this->orcamentos->criar($d), 'Orçamento criado.');
    }

    public function gravarValores(Request $r, int $orcamento): JsonResponse
    {
        $o = OrcamentoAnual::query()->findOrFail($orcamento);
        ServicoOrcamentos::eResponsavel($o) ? $this->exigir('orc_contributo', 'orc_editar') : $this->exigir('orc_editar');
        $d = $r->validate(['linhas' => ['present', 'array'], 'linhas.*.rubrica_orcamental_id' => ['required', 'integer'], 'linhas.*.valores' => ['required', 'array', 'size:12'],
            'linhas.*.valores.*' => ['numeric'], 'linhas.*.notas' => ['nullable', 'string', 'max:2000']]);

        return RespostaApi::sucesso($this->orcamentos->gravarValores($o, $d['linhas']), 'Valores gravados.');
    }

    public function submeter(int $orcamento): JsonResponse
    {
        $o = OrcamentoAnual::query()->findOrFail($orcamento);
        ServicoOrcamentos::eResponsavel($o) ? $this->exigir('orc_contributo', 'orc_submeter') : $this->exigir('orc_submeter');

        return RespostaApi::sucesso($this->orcamentos->submeter($o), 'Orçamento submetido.');
    }

    public function aprovar(int $orcamento): JsonResponse
    {
        $this->exigir('orc_aprovar');

        return RespostaApi::sucesso($this->orcamentos->aprovar(OrcamentoAnual::query()->findOrFail($orcamento)), 'Orçamento aprovado.');
    }

    public function devolver(Request $r, int $orcamento): JsonResponse
    {
        $this->exigir('orc_aprovar');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:1000']]);

        return RespostaApi::sucesso($this->orcamentos->devolver(OrcamentoAnual::query()->findOrFail($orcamento), $d['motivo']), 'Orçamento devolvido para revisão.');
    }

    public function novaVersao(int $orcamento): JsonResponse
    {
        $this->exigir('orc_editar');

        return RespostaApi::criado($this->orcamentos->novaVersao(OrcamentoAnual::query()->findOrFail($orcamento)), 'Nova versão criada (a anterior vigora até à aprovação).');
    }

    public function eliminar(int $orcamento): JsonResponse
    {
        $this->exigir('orc_editar');
        $this->orcamentos->eliminar(OrcamentoAnual::query()->findOrFail($orcamento));

        return RespostaApi::sucesso(null, 'Orçamento eliminado.');
    }

    public function repartir(Request $r, int $orcamento): JsonResponse
    {
        $this->exigir('orc_hierarquia');
        $d = $r->validate(['criterio' => ['required', 'in:IGUAL,REALIZADO,MANUAL'], 'percentagens' => ['nullable', 'array'], 'percentagens.*' => ['numeric', 'min:0']]);

        return RespostaApi::sucesso($this->orcamentos->repartirTopDown(OrcamentoAnual::query()->findOrFail($orcamento), $d['criterio'], $d['percentagens'] ?? []), 'Orçamento repartido.');
    }

    public function pedirContributos(Request $r, int $orcamento): JsonResponse
    {
        $this->exigir('orc_hierarquia');
        $d = $r->validate(['filhos' => ['required', 'array', 'min:1'], 'filhos.*.unidade_negocio_id' => ['nullable', 'integer'], 'filhos.*.centro_custo_id' => ['nullable', 'integer'],
            'filhos.*.projeto_id' => ['nullable', 'integer'], 'filhos.*.responsavel' => ['required', 'string', 'max:100'], 'prazo' => ['nullable', 'date'], 'preencher' => ['nullable', 'boolean']]);

        return RespostaApi::criado($this->orcamentos->pedirContributos(OrcamentoAnual::query()->findOrFail($orcamento), $d['filhos'], $d['prazo'] ?? null, (bool) ($d['preencher'] ?? false)),
            'Contributos pedidos.');
    }

    public function consolidar(int $orcamento): JsonResponse
    {
        $this->exigir('orc_hierarquia');

        return RespostaApi::sucesso($this->orcamentos->consolidar(OrcamentoAnual::query()->findOrFail($orcamento)), 'Contributos consolidados.');
    }

    // ───────────── Controlo nos documentos ─────────────

    /** @return array<string, list<mixed>> */
    private function regrasDocumento(): array
    {
        return ['tipo' => ['required', 'in:EXPLORACAO,TESOURARIA'], 'origem' => ['required', 'string', 'max:30'], 'documento' => ['required', 'string', 'max:100'],
            'data' => ['required', 'date'], 'linhas' => ['required', 'array', 'min:1'], 'linhas.*.codigo_conta' => ['required', 'string', 'max:20'],
            'linhas.*.valor' => ['required', 'numeric'], 'linhas.*.unidade_negocio_id' => ['nullable', 'integer'], 'linhas.*.centro_custo_id' => ['nullable', 'integer'],
            'linhas.*.projeto_id' => ['nullable', 'integer']];
    }

    /** POST /verificar — simulação do controlo antes de gravar o documento (nada é registado). */
    public function verificar(Request $r): JsonResponse
    {
        $d = $r->validate($this->regrasDocumento());

        return RespostaApi::sucesso($this->controlo->verificar($d['tipo'], $d['data'], $d['linhas']), 'Verificação orçamental.');
    }

    public function pedirExcesso(Request $r): JsonResponse
    {
        $d = $r->validate($this->regrasDocumento() + ['motivo' => ['required', 'string', 'min:5', 'max:1000']]);

        return RespostaApi::criado($this->controlo->pedirExcesso($d['tipo'], ['origem' => $d['origem'], 'documento' => $d['documento'], 'data' => substr($d['data'], 0, 10)],
            $d['linhas'], $d['motivo']), 'Pedido de aprovação do excesso enviado.');
    }

    public function pedidosExcesso(Request $r): JsonResponse
    {
        $this->exigir('orc_alertas_view', 'orc_aprovar_excesso');
        $f = $r->validate(['estado' => ['nullable', 'in:PENDENTE,APROVADO,REJEITADO,UTILIZADO']]);

        return RespostaApi::sucesso(PedidoExtrapolacaoOrcamento::query()->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))->orderByDesc('id')->get(),
            'Pedidos de excesso orçamental.');
    }

    public function decidirExcesso(Request $r, int $pedido): JsonResponse
    {
        $this->exigir('orc_aprovar_excesso');
        $d = $r->validate(['decisao' => ['required', 'in:APROVADO,REJEITADO'], 'nota' => ['nullable', 'string', 'max:1000']]);

        return RespostaApi::sucesso($this->controlo->decidirPedido(PedidoExtrapolacaoOrcamento::query()->findOrFail($pedido), $d['decisao'], $d['nota'] ?? null),
            $d['decisao'] === 'APROVADO' ? 'Excesso aprovado: o documento já pode ser gravado.' : 'Excesso rejeitado.');
    }

    public function alertas(): JsonResponse
    {
        $this->exigir('orc_alertas_view');

        return RespostaApi::sucesso(LogAlertaOrcamental::query()->orderByDesc('em')->orderByDesc('id')->limit(300)->get(), 'Registo de alertas orçamentais.');
    }

    public function monitor(Request $r): JsonResponse
    {
        $this->exigir('orc_alertas_view', 'orc_controlo_view');
        $d = $r->validate(['ano' => ['required', 'integer'], 'mes' => ['nullable', 'integer', 'between:1,12']]);

        return RespostaApi::sucesso($this->controlo->monitor((int) $d['ano'], (int) ($d['mes'] ?? now()->month)), 'Monitor de consumo orçamental.');
    }

    // ───────────── Controlo ─────────────

    public function controlo(Request $r, int $orcamento): JsonResponse
    {
        $this->exigir('orc_controlo_view');
        $d = $r->validate(['mes' => ['nullable', 'integer', 'between:1,12'], 'vista' => ['nullable', 'in:MES,ACUMULADO,ANO']]);

        return RespostaApi::sucesso($this->execucao->controlo(OrcamentoAnual::query()->findOrFail($orcamento), (int) ($d['mes'] ?? now()->month), $d['vista'] ?? 'ACUMULADO'),
            'Controlo orçamental.');
    }
}
