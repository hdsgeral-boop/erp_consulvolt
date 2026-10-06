<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendas\CriarReciboRequest;
use App\Http\Resources\Vendas\ReciboVendaResource;
use App\Models\ReciboVenda;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Services\Vendas\ServicoRecibosVenda;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** /api/vendas/recibos — recibos de clientes (no legado estavam na Tesouraria). */
final class ReciboVendaController extends Controller
{
    public function __construct(
        private readonly ServicoRecibosVenda $recibos,
        private readonly ServicoContabilizacaoVendas $contabilizacao,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->exigir('vendas_faturacao_view');
        $f = $request->validate([
            'cliente_id' => ['nullable', 'integer'], 'data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d'],
            'pesquisa' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1'],
        ]);
        $pagina = ReciboVenda::query()->with('cliente:id,nome,nif')
            ->when($f['cliente_id'] ?? null, fn ($q, $v) => $q->where('cliente_id', $v))
            ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('data', '>=', $v))
            ->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('data', '<=', $v))
            ->when($f['pesquisa'] ?? null, fn ($q, $v) => $q->where('numero_recibo', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->orderByDesc('data')->orderByDesc('id')
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));

        return RespostaApi::paginado($pagina, ReciboVendaResource::class);
    }

    public function show(int $recibo): JsonResponse
    {
        $this->exigir('vendas_faturacao_view');

        return RespostaApi::sucesso($this->detalhe(ReciboVenda::query()->findOrFail($recibo)), 'Recibo obtido com sucesso.');
    }

    /** POST — emite o recibo e, por omissão, contabiliza-o na mesma operação. */
    public function store(CriarReciboRequest $request): JsonResponse
    {
        $this->exigir('vendas_recibos');
        $d = $request->validated();
        $contabilizar = $d['contabilizar'] ?? true;
        if ($contabilizar) {
            $this->exigir('vendas_fat_contabilizar');
        }
        $adiantamento = ($d['tipo_recibo'] ?? 'NORMAL') === 'ADIANTAMENTO';
        $recibo = DB::transaction(function () use ($d, $contabilizar, $adiantamento) {
            $recibo = $adiantamento ? $this->recibos->criarAdiantamento($d) : $this->recibos->criar($d);

            return $contabilizar ? $this->contabilizacao->contabilizarRecibo($recibo) : $recibo;
        });

        return RespostaApi::criado($this->detalhe($recibo), ($adiantamento ? 'Recibo de adiantamento' : 'Recibo')." {$recibo->numero_recibo} emitido com sucesso.");
    }

    /** POST /{id}/alocar — aloca um recibo de adiantamento (contabilizado) a facturas do cliente (M-18). */
    public function alocar(Request $request, int $recibo): JsonResponse
    {
        $this->exigir('vendas_recibos');
        $this->exigir('vendas_fat_contabilizar');
        $d = $request->validate([
            'data' => ['nullable', 'date_format:Y-m-d'],
            'alocacoes' => ['required', 'array', 'min:1', 'max:200'],
            'alocacoes.*.venda_id' => ['required', 'integer'],
            'alocacoes.*.montante' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
        ], [], ['alocacoes' => 'facturas a liquidar']);
        $r = DB::transaction(function () use ($d, $recibo) {
            $r = ReciboVenda::query()->findOrFail($recibo);
            foreach ($this->recibos->alocarAdiantamento($r, $d['alocacoes'], $d['data'] ?? now()->toDateString()) as $item) {
                $this->contabilizacao->contabilizarAlocacao($r, $item);
            }

            return $r->refresh();
        });

        return RespostaApi::sucesso($this->detalhe($r), "Adiantamento {$r->numero_recibo} alocado (saldo por alocar: {$this->recibos->saldoAdiantamento($r)}).");
    }

    /** POST /contabilizar e /descontabilizar — recibos seleccionados, cada um na sua transacção (M-06). */
    public function contabilizarLote(Request $request): JsonResponse
    {
        $this->exigir('vendas_fat_contabilizar');
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);
        $r = $this->contabilizacao->lote('recibos', true, $d['ids']);

        return RespostaApi::sucesso($r, "{$r['ok']} recibo(s) contabilizado(s); {$r['erros']} com erro.");
    }

    public function descontabilizarLote(Request $request): JsonResponse
    {
        $this->exigir('vendas_fat_unpost');
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo']);
        $r = $this->contabilizacao->lote('recibos', false, $d['ids'], $d['motivo']);

        return RespostaApi::sucesso($r, "{$r['ok']} recibo(s) descontabilizado(s); {$r['erros']} com erro.");
    }

    public function anular(Request $request, int $recibo): JsonResponse
    {
        $this->exigir('vendas_recibos');
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo']);
        $r = $this->recibos->anular(ReciboVenda::query()->findOrFail($recibo), $d['motivo']);

        return RespostaApi::sucesso($this->detalhe($r), "Recibo {$r->numero_recibo} anulado.");
    }

    public function contabilizar(int $recibo): JsonResponse
    {
        $this->exigir('vendas_fat_contabilizar');
        $r = $this->contabilizacao->contabilizarRecibo(ReciboVenda::query()->findOrFail($recibo));

        return RespostaApi::sucesso($this->detalhe($r), "Recibo {$r->numero_recibo} contabilizado.");
    }

    public function descontabilizar(Request $request, int $recibo): JsonResponse
    {
        $this->exigir('vendas_fat_unpost');
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo']);
        $r = $this->contabilizacao->descontabilizarRecibo(ReciboVenda::query()->findOrFail($recibo), $d['motivo']);

        return RespostaApi::sucesso($this->detalhe($r), "Recibo {$r->numero_recibo} descontabilizado (estorno registado).");
    }

    private function detalhe(ReciboVenda $recibo): array
    {
        $dados = (new ReciboVendaResource($recibo->load(['cliente:id,nome,nif', 'itensReciboVenda.venda:id,numero_documento'])))->resolve();

        return $recibo->eAdiantamento() ? $dados + ['saldo_adiantamento' => $this->recibos->saldoAdiantamento($recibo)] : $dados;
    }
}
