<?php

namespace App\Http\Controllers\Api\Logistica;

use App\Http\Controllers\Controller;
use App\Models\Armazem;
use App\Models\GuiaSaida;
use App\Models\ItemGuiaSaida;
use App\Models\LinhaSessaoInventario;
use App\Models\MovimentoInventario;
use App\Models\SessaoInventario;
use App\Services\Compras\RelacoesNomes;
use App\Services\Logistica\ServicoArmazens;
use App\Services\Logistica\ServicoConfigLogistica;
use App\Services\Logistica\ServicoGuiasSaida;
use App\Services\Logistica\ServicoInventario;
use App\Services\Logistica\ServicoRecalculoStock;
use App\Services\Logistica\ServicoStock;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/logistica — armazéns, stock, movimentos, transferências, ajustes, extracto do artigo e inventários. */
final class StockController extends Controller
{
    public function __construct(
        private readonly ServicoArmazens $armazens,
        private readonly ServicoStock $stock,
        private readonly ServicoInventario $inventario,
        private readonly ServicoConfigLogistica $config,
        private readonly ServicoGuiasSaida $guiasSaida,
    ) {}

    // ───────────── Armazéns ─────────────

    public function armazens(): JsonResponse
    {
        $this->exigir('armazem_armazens_view', 'armazem_stock_view', 'vendas_faturacao_view', 'compras_faturacao_view');

        return RespostaApi::sucesso(Armazem::query()->orderByDesc('predefinido')->orderBy('nome')->get(), 'Armazéns.');
    }

    public function guardarArmazem(Request $r, ?int $armazem = null): JsonResponse
    {
        $this->exigir('armazem_config');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'codigo' => ['nullable', 'string', 'max:20'], 'localizacao' => ['nullable', 'string', 'max:150'],
            'predefinido' => ['nullable', 'boolean']]);
        $a = $armazem ? Armazem::query()->findOrFail($armazem) : null;
        $res = $this->armazens->guardar($d, $a);

        return $a ? RespostaApi::sucesso($res, 'Armazém actualizado.') : RespostaApi::criado($res, 'Armazém criado.');
    }

    public function eliminarArmazem(int $armazem): JsonResponse
    {
        $this->exigir('armazem_config');
        $this->armazens->eliminar(Armazem::query()->findOrFail($armazem));

        return RespostaApi::sucesso(null, 'Armazém eliminado.');
    }

    // ───────────── Stock e movimentos ─────────────

    public function stock(Request $r): JsonResponse
    {
        $this->exigir('armazem_stock_view');
        $f = $r->validate(['armazem_id' => ['nullable', 'integer'], 'com_stock' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso(['linhas' => $this->armazens->stock($f['armazem_id'] ?? null, (bool) ($f['com_stock'] ?? false)), 'valorizacao' => $this->armazens->valorizacao()],
            'Stock por armazém.');
    }

    public function movimentos(Request $r): JsonResponse
    {
        $this->exigir('armazem_movimentos_view', 'armazem_stock_view');
        $f = $r->validate(['produto_id' => ['nullable', 'integer'], 'armazem_id' => ['nullable', 'integer'], 'tipo' => ['nullable', 'in:ENTRADA,SAIDA,TRANSFERENCIA,AJUSTE'],
            'de' => ['nullable', 'date'], 'ate' => ['nullable', 'date'], 'referencia' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::paginado(MovimentoInventario::query()
            ->when($f['produto_id'] ?? null, fn ($q, $v) => $q->where('produto_id', $v))->when($f['armazem_id'] ?? null, fn ($q, $v) => $q->where('armazem_id', $v))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))->when($f['de'] ?? null, fn ($q, $v) => $q->where('data', '>=', $v))
            ->when($f['ate'] ?? null, fn ($q, $v) => $q->where('data', '<=', $v.' 23:59:59'))
            ->when($f['referencia'] ?? null, fn ($q, $v) => $q->where('referencia', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->orderByDesc('data')->orderByDesc('id')->paginate(perPage: (int) ($f['por_pagina'] ?? 100), page: (int) ($f['pagina'] ?? 1)));
    }

    public function extracto(Request $r, int $produto): JsonResponse
    {
        $this->exigir('armazem_movimentos_view', 'armazem_stock_view');
        $f = $r->validate(['armazem_id' => ['nullable', 'integer'], 'de' => ['required', 'date'], 'ate' => ['required', 'date', 'after_or_equal:de']]);

        return RespostaApi::sucesso($this->armazens->extracto($produto, $f['armazem_id'] ?? null, $f['de'], $f['ate']), 'Extracto do artigo.');
    }

    public function transferir(Request $r): JsonResponse
    {
        $this->exigir('armazem_transferencia', 'armazem_ajuste');
        $d = $r->validate(['armazem_origem_id' => ['required', 'integer'], 'armazem_destino_id' => ['required', 'integer'], 'data' => ['required', 'date'],
            'observacoes' => ['nullable', 'string', 'max:500'], 'linhas' => ['required', 'array', 'min:1', 'max:500'], 'linhas.*.produto_id' => ['required', 'integer'],
            'linhas.*.quantidade' => ['required', 'numeric', 'gt:0']]);

        return RespostaApi::criado($this->stock->transferir((int) $d['armazem_origem_id'], (int) $d['armazem_destino_id'], $d['linhas'], $d['data'], $d['observacoes'] ?? null),
            'Transferência registada.');
    }

    public function ajustar(Request $r): JsonResponse
    {
        $this->exigir('armazem_ajuste');
        $d = $r->validate(['produto_id' => ['required', 'integer'], 'armazem_id' => ['required', 'integer'], 'sentido' => ['required', 'in:E,S'],
            'quantidade' => ['required', 'numeric', 'gt:0'], 'custo_unitario' => ['nullable', 'numeric', 'min:0'], 'data' => ['required', 'date'],
            'motivo' => ['required', 'string', 'min:5', 'max:500']]);
        // M4: a saída de um ajuste manual sai sempre ao custo médio (o legado valorizava ao custo do produto; um custo indicado
        // numa saída mudaria o custo médio do que fica, E-STK-1). Na entrada mantém-se o custo indicado — stock inicial: o
        // sistema novo não tem o «preço de custo» da ficha que o legado usava — ou, sem ele, o custo médio.
        $custo = $d['sentido'] === 'E' && isset($d['custo_unitario']) ? (string) $d['custo_unitario'] : null;
        $res = $this->stock->ajustar((int) $d['produto_id'], (int) $d['armazem_id'], $d['sentido'], (string) $d['quantidade'],
            $custo, $d['data'], "Ajuste manual: {$d['motivo']}", ['documento_tipo' => 'AJUSTE']);

        return RespostaApi::criado($res['movimento'], 'Ajuste de stock registado.');
    }

    // ───────────── Guias de saída (consumo interno) ─────────────

    public function guias(Request $r): JsonResponse
    {
        $this->exigir('armazem_guias_view');
        $f = $r->validate(['tipo' => ['nullable', 'in:VENDA,BACK_TO_BACK,CONSUMO'], 'armazem_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'string', 'max:30'],
            'contabilizado' => ['nullable', 'boolean'], 'pesquisa' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::paginado(GuiaSaida::query()->with(RelacoesNomes::terceiro())
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($f['armazem_id'] ?? null, fn ($q, $v) => $q->where('armazem_id', $v))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when(isset($f['contabilizado']), fn ($q) => $q->where('contabilizado', (bool) $f['contabilizado']))
            ->when($f['pesquisa'] ?? null, function ($q, $v) {
                $termo = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($v)).'%';
                $q->where(fn ($w) => $w->where('numero_documento', 'ilike', $termo)->orWhere('area_rececao', 'ilike', $termo));
            })
            ->orderByDesc('data')->orderByDesc('id')->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1)), null, 'Guias de saída.');
    }

    public function guia(int $guia): JsonResponse
    {
        $this->exigir('armazem_guias_view');
        $g = GuiaSaida::query()->with(RelacoesNomes::terceiro())->findOrFail($guia);

        return RespostaApi::sucesso($g->toArray() + ['linhas' => ItemGuiaSaida::query()->where('guia_saida_id', $g->id)->with(RelacoesNomes::produto())->orderBy('id')->get()],
            'Guia de saída.');
    }

    public function emitirGuia(Request $r): JsonResponse
    {
        $this->exigir('armazem_guias_emitir');
        $d = $r->validate(['armazem_id' => ['required', 'integer'], 'data' => ['required', 'date'], 'area_rececao' => ['required', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string', 'max:2000'], 'linhas' => ['required', 'array', 'min:1', 'max:500'], 'linhas.*.produto_id' => ['required', 'integer'],
            'linhas.*.quantidade' => ['required', 'numeric', 'gt:0']]);
        Armazem::query()->findOrFail($d['armazem_id']);

        return RespostaApi::criado($this->guiasSaida->emitirConsumo($d), 'Guia de consumo emitida.');
    }

    public function contabilizarGuia(int $guia): JsonResponse
    {
        $this->exigir('armazem_guias_contab');

        return RespostaApi::sucesso($this->guiasSaida->contabilizar(GuiaSaida::query()->findOrFail($guia)), 'Guia contabilizada.');
    }

    public function descontabilizarGuia(Request $r, int $guia): JsonResponse
    {
        $this->exigir('armazem_guias_contab');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return RespostaApi::sucesso($this->guiasSaida->descontabilizar(GuiaSaida::query()->findOrFail($guia), $d['motivo']), 'Guia descontabilizada (estorno).');
    }

    public function anularGuia(Request $r, int $guia): JsonResponse
    {
        $this->exigir('armazem_guias_anular');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return RespostaApi::sucesso($this->guiasSaida->anular(GuiaSaida::query()->findOrFail($guia), $d['motivo']), 'Guia anulada (stock reposto).');
    }

    // ───────────── Configuração ─────────────

    public function contas(): JsonResponse
    {
        $this->exigir('armazem_config', 'armazem_stock_view');

        return RespostaApi::sucesso($this->config->todas(), 'Contas da logística.');
    }

    public function definirContas(Request $r): JsonResponse
    {
        $this->exigir('armazem_config');
        $d = $r->validate(['contas' => ['required', 'array']]);
        $this->config->definir($d['contas']);

        return RespostaApi::sucesso($this->config->todas(), 'Contas da logística gravadas.');
    }

    // ───────────── Inventários ─────────────

    public function sessoes(Request $r): JsonResponse
    {
        $this->exigir('inventario_sessoes_view', 'inventario_contagem_view', 'inventario_revisao_view');

        $f = $r->validate(['armazem_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'string', 'max:30'], 'pesquisa' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::paginado(SessaoInventario::query()->when($f['armazem_id'] ?? null, fn ($q, $a) => $q->where('armazem_id', $a))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($f['pesquisa'] ?? null, fn ($q, $v) => $q->where('descricao', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($v)).'%'))
            ->orderByDesc('data')->orderByDesc('id')->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1)), null, 'Inventários.');
    }

    // ───────────── Recálculo das valorizações (ADR-064) ─────────────

    /** POST /api/logistica/stock/recalcular-valorizacoes — simulação por omissão; `aplicar` grava (tarefa armazem_recalcular). */
    public function recalcularValorizacoes(Request $r, ServicoRecalculoStock $recalculo): JsonResponse
    {
        $this->exigir('armazem_recalcular');
        $d = $r->validate(['produto_id' => ['nullable', 'integer'], 'aplicar' => ['nullable', 'boolean']]);
        $res = $recalculo->recalcular(isset($d['produto_id']) ? (int) $d['produto_id'] : null, (bool) ($d['aplicar'] ?? false));
        $n = $res['resumo']['movimentos_alterados'];

        return RespostaApi::sucesso($res, $res['aplicado'] ? "Recálculo aplicado: {$n} movimento(s) revalorizado(s)." : "Simulação: {$n} movimento(s) seriam revalorizados.");
    }

    public function sessao(int $sessao): JsonResponse
    {
        $this->exigir('inventario_sessoes_view', 'inventario_contagem_view', 'inventario_revisao_view');
        $s = SessaoInventario::query()->findOrFail($sessao);
        $linhas = LinhaSessaoInventario::query()->where('sessao_inventario_id', $s->id)->join('produtos as p', 'p.id', '=', 'linhas_sessao_inventario.produto_id')
            ->orderBy('p.codigo')->get(['linhas_sessao_inventario.*', 'p.codigo', 'p.nome', 'p.custo_medio']);
        // contagem cega: na contagem não se mostra a quantidade do sistema
        if ($s->estado === 'EM_CONTAGEM') {
            $linhas->each(fn ($l) => $l->makeHidden(['quantidade_sistema', 'diferenca']));
        }

        return RespostaApi::sucesso($s->toArray() + ['linhas' => $linhas], 'Inventário.');
    }

    public function abrirSessao(Request $r): JsonResponse
    {
        $this->exigir('inventario_iniciar');
        $d = $r->validate(['armazem_id' => ['required', 'integer'], 'data' => ['required', 'date'], 'descricao' => ['nullable', 'string', 'max:500']]);
        Armazem::query()->findOrFail($d['armazem_id']);

        return RespostaApi::criado($this->inventario->abrir((int) $d['armazem_id'], $d['data'], $d['descricao'] ?? null), 'Inventário aberto: o armazém fica sem movimentos até à conclusão.');
    }

    public function contar(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('inventario_count');
        $d = $r->validate(['linhas' => ['required', 'array', 'min:1', 'max:10000'], 'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade_contada' => ['nullable', 'numeric', 'min:0'],
            'linhas.*.observacoes' => ['nullable', 'string', 'max:500']]);

        return RespostaApi::sucesso(['gravadas' => $this->inventario->contar(SessaoInventario::query()->findOrFail($sessao), $d['linhas'])], 'Contagem gravada.');
    }

    public function concluirContagem(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('inventario_count');
        $d = $r->validate(['por_contar_como_zero' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->inventario->concluirContagem(SessaoInventario::query()->findOrFail($sessao), (bool) ($d['por_contar_como_zero'] ?? false)), 'Contagem concluída: em revisão.');
    }

    public function rever(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('inventario_rever');
        $d = $r->validate(['linhas' => ['required', 'array', 'max:10000'], 'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.custo_personalizado' => ['nullable', 'numeric', 'min:0'],
            'linhas.*.justificacao' => ['nullable', 'string', 'max:500']]);
        $this->inventario->rever(SessaoInventario::query()->findOrFail($sessao), $d['linhas']);

        return RespostaApi::sucesso(null, 'Revisão gravada.');
    }

    public function voltarContagem(int $sessao): JsonResponse
    {
        $this->exigir('inventario_rever');

        return RespostaApi::sucesso($this->inventario->voltarContagem(SessaoInventario::query()->findOrFail($sessao)), 'Inventário de volta à contagem.');
    }

    public function aprovar(int $sessao): JsonResponse
    {
        $this->exigir('inventario_manage');

        return RespostaApi::sucesso($this->inventario->aprovar(SessaoInventario::query()->findOrFail($sessao)), 'Inventário aprovado e stock regularizado.');
    }

    public function reabrir(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('inventario_manage');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return RespostaApi::sucesso($this->inventario->reabrir(SessaoInventario::query()->findOrFail($sessao), $d['motivo']), 'Inventário reaberto (regularização estornada).');
    }

    public function anular(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('inventario_manage');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return RespostaApi::sucesso($this->inventario->anular(SessaoInventario::query()->findOrFail($sessao), $d['motivo']), 'Inventário anulado.');
    }
}
