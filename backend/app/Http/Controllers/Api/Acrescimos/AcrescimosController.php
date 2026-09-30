<?php

namespace App\Http\Controllers\Api\Acrescimos;

use App\Http\Controllers\Controller;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\PeriodoLancamentoAcrescimo;
use App\Services\Acrescimos\CalculadoraAcrescimos;
use App\Services\Acrescimos\ServicoDefinicoesAcrescimos;
use App\Services\Acrescimos\ServicoItensAcrescimos;
use App\Services\Acrescimos\ServicoPropostaAcrescimos;
use App\Services\Acrescimos\ServicoRecolhaAcrescimos;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/acrescimos — definições, registos (mapa, regularizar, terminar), proposta mensal, contabilização, reconciliação e recolha. */
final class AcrescimosController extends Controller
{
    private const VER = ['ad_registos_view', 'ad_propostas_view', 'ad_recolher_view', 'ad_editar', 'ad_contabilizar', 'ad_definicoes_edit'];

    public function __construct(
        private readonly ServicoDefinicoesAcrescimos $definicoes,
        private readonly ServicoItensAcrescimos $itens,
        private readonly ServicoPropostaAcrescimos $proposta,
        private readonly ServicoRecolhaAcrescimos $recolha,
    ) {}

    // ───────────── Definições ─────────────

    public function obterDefinicoes(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->definicoes->obter() + ['rotulos_contas' => ServicoDefinicoesAcrescimos::ROTULO_CONTA, 'modelos' => ServicoDefinicoesAcrescimos::MODELOS,
            'tipos' => CalculadoraAcrescimos::TIPOS, 'naturezas' => CalculadoraAcrescimos::NATUREZAS, 'estados' => CalculadoraAcrescimos::ESTADOS,
            'tipos_linha' => CalculadoraAcrescimos::TIPOS_LINHA], 'Definições de acréscimos e diferimentos.');
    }

    public function guardarDefinicoes(Request $r): JsonResponse
    {
        $this->exigir('ad_definicoes_edit');
        $d = $r->validate(['contas' => ['required', 'array'], 'contas.ACRESCIMO_CUSTO' => ['nullable', 'string', 'max:20'], 'contas.ACRESCIMO_PROVEITO' => ['nullable', 'string', 'max:20'],
            'contas.DIFERIMENTO_CUSTO' => ['nullable', 'string', 'max:20'], 'contas.DIFERIMENTO_PROVEITO' => ['nullable', 'string', 'max:20'],
            'diario_id' => ['nullable', 'integer'], 'prazo_documento_dias' => ['nullable', 'integer', 'min:0', 'max:3650']]);

        return RespostaApi::sucesso($this->definicoes->guardar($d), 'Definições gravadas.');
    }

    // ───────────── Registos ─────────────

    public function itens(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['tipo' => ['nullable', Rule::in(array_keys(CalculadoraAcrescimos::TIPOS))], 'estado' => ['nullable', 'string', 'max:20'],
            'texto' => ['nullable', 'string', 'max:100'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $estado = $f['estado'] ?? 'ABERTOS';
        $pagina = ItemAcrescimoDiferimento::query()->with(['terceiro' => fn ($q) => $q->withTrashed()->select(['id', 'nome'])])
            ->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))
            ->when($estado === 'ABERTOS', fn ($q) => $q->whereNotIn('estado', CalculadoraAcrescimos::FECHADOS))
            ->when($estado !== 'ABERTOS' && $estado !== 'TODOS', fn ($q) => $q->where('estado', $estado))
            ->when($f['texto'] ?? null, function ($q, $t) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%';
                $q->where(fn ($s) => $s->where('descricao', 'ilike', $termo)->orWhere('conta_resultado', 'like', $termo)->orWhere('conta_balanco', 'like', $termo)
                    ->orWhereRaw("origem->>'doc' ilike ?", [$termo])->orWhereRaw('regularizacao ilike ?', [$termo])
                    ->orWhereHas('terceiro', fn ($x) => $x->withTrashed()->where('nome', 'ilike', $termo)));
            })
            ->orderByDesc('data_inicio')->orderByDesc('id')->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));
        $ids = $pagina->getCollection()->pluck('id');
        $reconhecido = PeriodoLancamentoAcrescimo::query()->whereIn('item_acrescimo_diferimento_id', $ids)->where('estado', 'CONTABILIZADO')->whereIn('tipo', ['RECONHECIMENTO', 'TERMINO'])
            ->selectRaw('item_acrescimo_diferimento_id, SUM(valor) AS total')->groupBy('item_acrescimo_diferimento_id')->pluck('total', 'item_acrescimo_diferimento_id');
        $comLanc = PeriodoLancamentoAcrescimo::query()->whereIn('item_acrescimo_diferimento_id', $ids)->where('estado', 'CONTABILIZADO')->distinct()->pluck('item_acrescimo_diferimento_id')->flip();
        $hoje = now()->toDateString();
        $pagina->setCollection($pagina->getCollection()->map(fn ($it) => $it->toArray() + [
            'reconhecido' => CalculadoraAcrescimos::dinheiro((string) ($reconhecido[$it->id] ?? '0')), 'tem_lancamentos' => isset($comLanc[$it->id]),
            'sem_documento' => $it->tipo === 'ACRESCIMO' && $it->estado === 'ACTIVO' && $it->data_limite && $it->data_limite->toDateString() < $hoje,
        ]));

        return RespostaApi::paginado($pagina, null, 'Registos de acréscimos e diferimentos.');
    }

    public function item(int $item): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->itens->mapa(ItemAcrescimoDiferimento::query()->findOrFail($item)), 'Registo e mapa de reconhecimento.');
    }

    public function guardarItem(Request $r, ?int $item = null): JsonResponse
    {
        $this->exigir('ad_editar');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $daEmpresa = fn (string $t) => Rule::exists($t, 'id')->where('empresa_id', $empresa);
        $d = $r->validate([
            'tipo' => ['required', Rule::in(array_keys(CalculadoraAcrescimos::TIPOS))], 'natureza' => ['required', Rule::in(array_keys(CalculadoraAcrescimos::NATUREZAS))],
            'descricao' => ['required', 'string', 'max:1000'], 'valor' => ['required', 'numeric', 'decimal:0,2', 'max:9999999999999.99'],
            'conta_resultado' => ['required', 'string', 'max:20'], 'conta_balanco' => ['required', 'string', 'max:20'],
            'data_inicio' => ['required', 'date_format:Y-m-d'], 'data_fim' => ['required', 'date_format:Y-m-d'], 'reparticao' => ['nullable', 'in:DIAS,MESES'],
            'data_documento' => ['nullable', 'date_format:Y-m-d'], 'data_limite' => ['nullable', 'date_format:Y-m-d'], 'documento_em_balanco' => ['nullable', 'boolean'],
            'terceiro_id' => ['nullable', 'integer', $daEmpresa('terceiros')], 'unidade_negocio_id' => ['nullable', 'integer', $daEmpresa('unidades_negocio')],
            'centro_custo_id' => ['nullable', 'integer', $daEmpresa('centros_custo')], 'projeto_id' => ['nullable', 'integer', $daEmpresa('projetos')],
            'origem' => ['nullable', 'array'], 'origem.fonte' => ['nullable', 'string', 'max:20'], 'origem.id' => ['nullable'], 'origem.doc' => ['nullable', 'string', 'max:100'],
            'origem.data' => ['nullable', 'date_format:Y-m-d'], 'origem.conta' => ['nullable', 'string', 'max:20'], 'notas' => ['nullable', 'string', 'max:4000'],
        ]);
        $existente = $item ? ItemAcrescimoDiferimento::query()->findOrFail($item) : null;
        $res = $this->itens->guardar($d, $existente);
        $msg = $res['parcial'] ? 'O registo tem lançamentos: só as notas e a data limite foram alteradas.' : ($existente ? 'Registo actualizado.' : 'Registo criado.');

        return $existente ? RespostaApi::sucesso($res, $msg) : RespostaApi::criado($res, $msg);
    }

    public function eliminarItem(int $item): JsonResponse
    {
        $this->exigir('ad_editar');
        $this->itens->eliminar(ItemAcrescimoDiferimento::query()->findOrFail($item));

        return RespostaApi::sucesso(null, 'Registo eliminado.');
    }

    public function regularizar(Request $r, int $item): JsonResponse
    {
        $this->exigir('ad_editar');
        $d = $r->validate(['data' => ['required', 'date_format:Y-m-d'], 'anulacao' => ['nullable', 'boolean'], 'valor' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'doc' => ['nullable', 'string', 'max:60'], 'fonte' => ['nullable', Rule::in(['MANUAL', ...ServicoRecolhaAcrescimos::FONTES])], 'id' => ['nullable'],
            'motivo' => ['nullable', 'string', 'max:100']]);
        $res = $this->itens->regularizar(ItemAcrescimoDiferimento::query()->findOrFail($item), $d);

        return RespostaApi::sucesso($res, ! empty($d['anulacao']) ? 'Anulação registada: é contabilizada na proposta do mês.' : 'Documento real associado: a regularização é contabilizada na proposta do mês.');
    }

    public function terminar(Request $r, int $item): JsonResponse
    {
        $this->exigir('ad_editar');
        $d = $r->validate(['data' => ['required', 'date_format:Y-m-d'], 'motivo' => ['nullable', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->itens->terminar(ItemAcrescimoDiferimento::query()->findOrFail($item), $d['data'], $d['motivo'] ?? null),
            'Término registado: o saldo por reconhecer é contabilizado na proposta do mês.');
    }

    public function desfazerPedido(int $item): JsonResponse
    {
        $this->exigir('ad_editar');

        return RespostaApi::sucesso($this->itens->desfazerPedido(ItemAcrescimoDiferimento::query()->findOrFail($item)), 'Pedido desfeito.');
    }

    /** Plano de repartição sem gravar (pré-visualização do formulário). */
    public function simularQuotas(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['valor' => ['required', 'numeric', 'decimal:0,2', 'min:0'], 'data_inicio' => ['required', 'date_format:Y-m-d'],
            'data_fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_inicio'], 'reparticao' => ['nullable', 'in:DIAS,MESES']]);

        return RespostaApi::sucesso(CalculadoraAcrescimos::quotas((string) $d['valor'], $d['data_inicio'], $d['data_fim'], $d['reparticao'] ?? 'DIAS'), 'Plano de repartição.');
    }

    // ───────────── Proposta mensal e lançamentos ─────────────

    public function obterProposta(Request $r): JsonResponse
    {
        $this->exigir('ad_propostas_view', 'ad_contabilizar', 'ad_registos_view');
        $d = $r->validate(['mes' => ['required', 'string', 'size:7']]);

        return RespostaApi::sucesso($this->proposta->proposta($d['mes']), 'Proposta mensal.');
    }

    public function contabilizar(Request $r): JsonResponse
    {
        $this->exigir('ad_contabilizar');
        $d = $r->validate(['mes' => ['required', 'string', 'size:7'], 'chaves' => ['required', 'array', 'min:1', 'max:1000'], 'chaves.*' => ['string', 'max:60']]);
        $res = $this->proposta->contabilizar($d['mes'], $d['chaves']);

        return RespostaApi::sucesso($res, count($res['ok']).' lançamento(s) contabilizado(s)'.($res['erros'] ? ', '.count($res['erros']).' com erro.' : '.'));
    }

    public function lancamentos(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['item_id' => ['nullable', 'integer'], 'periodo' => ['nullable', 'string', 'size:7'], 'estado' => ['nullable', 'in:CONTABILIZADO,ANULADO'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $q = PeriodoLancamentoAcrescimo::query()->when($f['item_id'] ?? null, fn ($q, $v) => $q->where('item_acrescimo_diferimento_id', $v))
            ->when($f['periodo'] ?? null, fn ($q, $v) => $q->where('periodo', $v))->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->orderByDesc('data_documento')->orderByDesc('id');

        return RespostaApi::paginado($q->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1)), null, 'Lançamentos de acréscimos e diferimentos.');
    }

    public function descontabilizar(Request $r, int $lancamento): JsonResponse
    {
        $this->exigir('ad_contabilizar');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return RespostaApi::sucesso($this->proposta->descontabilizar(PeriodoLancamentoAcrescimo::query()->findOrFail($lancamento), $d['motivo']), 'Lançamento descontabilizado (estorno).');
    }

    public function reconciliacao(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['data' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso($this->proposta->reconciliacao($d['data'] ?? null), 'Reconciliação das contas de acréscimos e diferimentos.');
    }

    // ───────────── Recolha de documentos ─────────────

    public function candidatos(Request $r): JsonResponse
    {
        $this->exigir('ad_recolher_view', 'ad_editar');
        $f = $r->validate(['fonte' => ['required', Rule::in(ServicoRecolhaAcrescimos::FONTES)], 'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'], 'texto' => ['nullable', 'string', 'max:100']]);

        return RespostaApi::sucesso($this->recolha->candidatos($f['fonte'], $f), 'Documentos candidatos.');
    }

    public function acrescimosAbertos(Request $r): JsonResponse
    {
        $this->exigir('ad_recolher_view', 'ad_editar');
        $f = $r->validate(['natureza' => ['required', Rule::in(array_keys(CalculadoraAcrescimos::NATUREZAS))], 'terceiro_id' => ['nullable', 'integer'],
            'contas' => ['nullable', 'array'], 'contas.*' => ['string', 'max:20']]);

        return RespostaApi::sucesso($this->recolha->acrescimosParaRegularizar($f['natureza'], isset($f['terceiro_id']) ? (int) $f['terceiro_id'] : null, $f['contas'] ?? []),
            'Acréscimos em aberto que o documento pode regularizar.');
    }
}
