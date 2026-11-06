<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Prestacao\AnularRequest;
use App\Http\Requests\POS\Prestacao\LiquidacoesRequest;
use App\Http\Requests\POS\Prestacao\PendentesRequest;
use App\Http\Requests\POS\Prestacao\RegistarRequest;
use App\Http\Requests\POS\Prestacao\RelatoriosRequest;
use App\Models\LiquidacaoPOS;
use App\Models\SessaoCaixa;
use App\Models\SessaoPOS;
use App\Services\POS\ServicoPrestacaoContasPOS;
use App\Services\POS\ServicoRelatoriosPOS;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/** /api/pos — prestação de contas (liquidações das contas transitórias) e relatórios do POS. */
final class PrestacaoContasController extends Controller
{
    private const VER = ['pos_prestacao_view', 'pos_prestacao', 'pos_prestar', 'pos_prestacao_anular'];

    private const RELATORIOS = ['pos_relatorios_view', 'pos_relatorios'];

    public function __construct(
        private readonly ServicoPrestacaoContasPOS $prestacao,
        private readonly ServicoRelatoriosPOS $relatorios,
    ) {}

    // ───────────── Prestação de contas ─────────────

    /** Sessões fechadas por regularizar (PENDENTE/PARCIAL), com os itens, e as folhas de caixa abertas (renderPrestacao, pos_prestacao.js:558-609). */
    public function pendentes(PendentesRequest $r): JsonResponse
    {
        $f = $r->validated();
        $sessoes = SessaoPOS::query()->where('estado', 'FECHADA')->whereIn('estado_liquidacao', ['PENDENTE', 'PARCIAL'])
            ->when($f['terminal_pos_id'] ?? null, fn ($q, $v) => $q->where('terminal_pos_id', $v))
            ->orderByDesc('fechado_em')->orderByDesc('id')->get();
        $liquidacoes = LiquidacaoPOS::query()->whereIn('sessao_pos_id', $sessoes->pluck('id'))->where('estado', 'REGISTADO')->get()->groupBy('sessao_pos_id');

        return RespostaApi::sucesso([
            'sessoes' => $sessoes->map(fn ($s) => $this->resumo($s) + ['itens' => $this->prestacao->itens($s, $liquidacoes[$s->id] ?? collect())])->all(),
            'folhas_caixa_abertas' => SessaoCaixa::query()->where('estado', 'ABERTA')->orderBy('codigo_conta')->get(['id', 'codigo_conta', 'operador', 'data_abertura']),
        ], 'Prestação de contas por regularizar.');
    }

    public function itens(int $sessao): JsonResponse
    {
        $this->exigir(...self::VER);
        $s = SessaoPOS::query()->findOrFail($sessao);

        return RespostaApi::sucesso($this->resumo($s) + ['itens' => $this->prestacao->itens($s),
            'liquidacoes' => LiquidacaoPOS::query()->where('sessao_pos_id', $s->id)->orderBy('id')->get()], 'Prestação de contas da sessão.');
    }

    public function registar(RegistarRequest $r, int $sessao): JsonResponse
    {
        $d = $r->validated();
        $res = $this->prestacao->registar(SessaoPOS::query()->findOrFail($sessao), $d['chave_item'], $d);

        return RespostaApi::criado($res['liquidacao'], $res['aviso'] ?? 'Prestação de contas registada.');
    }

    public function registarTransferencias(int $sessao): JsonResponse
    {
        $this->exigir('pos_prestar');
        $res = $this->prestacao->registarTransferencias(SessaoPOS::query()->findOrFail($sessao));
        $n = count($res['registadas']);

        return RespostaApi::sucesso($res, $res['erros'] ? "{$n} transferência(s) registada(s); ".count($res['erros']).' com erro.' : "{$n} transferência(s) registada(s).");
    }

    /** Histórico de liquidações (movimentos de prestação de contas, pos_prestacao.js:593-608). */
    public function liquidacoes(LiquidacoesRequest $r): JsonResponse
    {
        $f = $r->validated();
        $q = LiquidacaoPOS::query();
        foreach (['sessao_pos_id', 'estado', 'natureza_registo', 'alvo'] as $c) {
            $q->when($f[$c] ?? null, fn ($q, $v) => $q->where($c, $v));
        }
        $q->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('data', '>=', $v))->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('data', '<=', $v));

        return RespostaApi::paginado($q->orderByDesc('criado_em')->orderByDesc('id')->paginate($f['por_pagina'] ?? 50, ['*'], 'pagina', $f['pagina'] ?? 1), null, 'Liquidações POS.');
    }

    public function liquidacao(int $liquidacao): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(LiquidacaoPOS::query()->findOrFail($liquidacao), 'Liquidação POS.');
    }

    public function anular(AnularRequest $r, int $liquidacao): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->prestacao->anular(LiquidacaoPOS::query()->findOrFail($liquidacao), $d['motivo']), 'Liquidação anulada.');
    }

    // ───────────── Relatórios ─────────────

    public function relatorios(RelatoriosRequest $r): JsonResponse
    {
        $f = $r->validated();

        return RespostaApi::sucesso($this->relatorios->painel($f), 'Relatórios do POS.');
    }

    private function resumo(SessaoPOS $s): array
    {
        return $s->only(['id', 'codigo_sessao', 'numero_z', 'terminal_pos_id', 'codigo_terminal', 'nome_terminal', 'operador_id', 'nome_operador', 'fechado_em',
            'estado_contabilizacao', 'estado_desvio', 'estado_liquidacao', 'desvio', 'fechos_tpa']);
    }
}
