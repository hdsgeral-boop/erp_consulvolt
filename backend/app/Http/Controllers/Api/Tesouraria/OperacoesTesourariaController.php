<?php

namespace App\Http\Controllers\Api\Tesouraria;

use App\Http\Controllers\Controller;
use App\Models\ConferenciaCaixa;
use App\Models\LinhaExtratoBancario;
use App\Models\MovimentoCaixa;
use App\Models\ReconciliacaoBancaria;
use App\Models\SessaoCaixa;
use App\Services\Tesouraria\ServicoCaixa;
use App\Services\Tesouraria\ServicoConferenciaCaixa;
use App\Services\Tesouraria\ServicoConfigTesouraria;
use App\Services\Tesouraria\ServicoReconciliacaoBancaria;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/tesouraria — reconciliação bancária, folha de caixa, conferência de caixa e contas de tesouraria. */
final class OperacoesTesourariaController extends Controller
{
    public function __construct(
        private readonly ServicoReconciliacaoBancaria $reconciliacao,
        private readonly ServicoCaixa $caixa,
        private readonly ServicoConferenciaCaixa $conferencia,
        private readonly ServicoConfigTesouraria $config,
    ) {}

    // ─────────── Reconciliação bancária ───────────

    public function extrato(Request $r): JsonResponse
    {
        $this->exigir('teso_gestao_conciliacao_view');
        $f = $r->validate(['codigo_conta' => ['required', 'string', 'max:20'], 'estado' => ['nullable', Rule::in(['PENDENTE', 'CONCILIADO', 'ANULADO'])],
            'data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d']]);
        $linhas = LinhaExtratoBancario::query()->where('codigo_conta', $f['codigo_conta'])->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('data', '>=', $v))->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('data', '<=', $v))
            ->orderBy('data')->orderBy('id')->limit(2000)->get();

        return RespostaApi::sucesso($linhas, 'Linhas de extracto.');
    }

    public function importarExtrato(Request $r): JsonResponse
    {
        $this->exigir('teso_conc_importar');
        $d = $r->validate(['codigo_conta' => ['required', 'string', 'max:20'], 'ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt']]);

        return RespostaApi::criado($this->reconciliacao->importar($d['codigo_conta'], $r->file('ficheiro')->getRealPath()), 'Extracto importado.');
    }

    public function sugestoes(Request $r): JsonResponse
    {
        $this->exigir('teso_gestao_conciliacao_view');
        $f = $r->validate(['codigo_conta' => ['required', 'string', 'max:20'], 'data_inicio' => ['nullable', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d'], 'tolerancia_dias' => ['nullable', 'integer', 'min:0', 'max:5']]);

        return RespostaApi::sucesso($this->reconciliacao->sugerir($f['codigo_conta'], $f['data_inicio'] ?? null, $f['data_fim'] ?? null, (int) ($f['tolerancia_dias'] ?? 1)),
            'Sugestões de correspondência (por confirmar).');
    }

    public function confirmar(Request $r): JsonResponse
    {
        $this->exigir('teso_conc_confirmar');
        $d = $r->validate(['codigo_conta' => ['required', 'string', 'max:20'], 'tipo' => ['nullable', Rule::in(['AUTOMATICA', 'MANUAL'])],
            'grupos' => ['required', 'array', 'min:1', 'max:1000'], 'grupos.*.extrato' => ['required', 'array', 'min:1'], 'grupos.*.extrato.*' => ['integer'],
            'grupos.*.lancamentos' => ['required', 'array', 'min:1'], 'grupos.*.lancamentos.*' => ['integer']]);
        $rec = $this->reconciliacao->confirmar($d['codigo_conta'], $d['grupos'], $d['tipo'] ?? 'MANUAL');

        return RespostaApi::criado($rec, "Reconciliação {$rec->reconciliacao_codigo} confirmada.");
    }

    public function anularReconciliacao(Request $r, string $codigo): JsonResponse
    {
        $this->exigir('teso_conc_anular');

        return RespostaApi::sucesso($this->reconciliacao->anular($codigo, $this->motivo($r)), 'Reconciliação anulada.');
    }

    public function anularLinhaExtrato(int $id): JsonResponse
    {
        $this->exigir('teso_conc_anular');

        return RespostaApi::sucesso($this->reconciliacao->anularLinhaExtrato(LinhaExtratoBancario::query()->findOrFail($id)), 'Linha de extracto anulada.');
    }

    public function reconciliacoes(Request $r): JsonResponse
    {
        $this->exigir('teso_gestao_conciliacao_view', 'teso_contab_historico_view');

        return RespostaApi::sucesso(ReconciliacaoBancaria::query()->where('estado', 'CONCILIADO_BANCO')->orderByDesc('data')->limit(500)->get(), 'Reconciliações bancárias.');
    }

    public function mapa(Request $r): JsonResponse
    {
        $this->exigir('teso_gestao_conciliacao_view');
        $f = $r->validate(['codigo_conta' => ['required', 'string', 'max:20'], 'data' => ['required', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso($this->reconciliacao->mapa($f['codigo_conta'], $f['data']), 'Mapa de reconciliação.');
    }

    // ─────────── Folha de caixa ───────────

    public function sessoes(Request $r): JsonResponse
    {
        $this->exigir('teso_folha_caixa_view');

        return RespostaApi::sucesso(SessaoCaixa::query()->when($r->string('codigo_conta')->toString(), fn ($q, $v) => $q->where('codigo_conta', $v))
            ->orderByDesc('data_abertura')->orderByDesc('id')->limit(200)->get(), 'Sessões de caixa.');
    }

    public function sessao(int $id): JsonResponse
    {
        $this->exigir('teso_folha_caixa_view');

        return RespostaApi::sucesso($this->sessaoArray(SessaoCaixa::query()->findOrFail($id)), 'Sessão de caixa.');
    }

    public function abrirSessao(Request $r): JsonResponse
    {
        $this->exigir('teso_caixa_operar');
        $d = $r->validate(['codigo_conta' => ['required', 'string', 'max:20'], 'data' => ['required', 'date_format:Y-m-d'], 'saldo_abertura' => ['nullable', 'numeric', 'min:0']]);
        $res = $this->caixa->abrir($d['codigo_conta'], $d['data'], isset($d['saldo_abertura']) ? (string) $d['saldo_abertura'] : null);

        return RespostaApi::criado($this->sessaoArray($res['sessao']) + ['aviso' => $res['aviso']], 'Sessão de caixa aberta.');
    }

    public function registarMovimento(Request $r, int $id): JsonResponse
    {
        $this->exigir('teso_caixa_operar');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $daEmpresa = fn (string $t) => Rule::exists($t, 'id')->where('empresa_id', $empresa);
        $d = $r->validate(['tipo' => ['required', Rule::in(['REC', 'PAG'])], 'data_documento' => ['required', 'date_format:Y-m-d'],
            'conta_contrapartida' => ['required', 'string', 'max:20'], 'valor' => ['required', 'numeric', 'gt:0'], 'descricao' => ['required', 'string', 'min:3', 'max:1000'],
            'terceiro_id' => ['nullable', 'integer', $daEmpresa('terceiros')], 'numero_documento' => ['nullable', 'string', 'max:100'], 'referencia' => ['nullable', 'string', 'max:100'],
            'venda_id' => ['nullable', 'integer', $daEmpresa('vendas')], 'fatura_compra_id' => ['nullable', 'integer', $daEmpresa('faturas_compra')],
            'unidade_negocio_id' => ['nullable', 'integer', $daEmpresa('unidades_negocio')], 'centro_custo_id' => ['nullable', 'integer', $daEmpresa('centros_custo')]]);
        $this->caixa->registarMovimento(SessaoCaixa::query()->findOrFail($id), $d);

        return RespostaApi::criado($this->sessaoArray(SessaoCaixa::query()->findOrFail($id)), 'Movimento registado.');
    }

    public function removerMovimento(int $id, int $movimento): JsonResponse
    {
        $this->exigir('teso_caixa_operar');
        $this->caixa->removerMovimento(MovimentoCaixa::query()->where('sessao_caixa_id', $id)->findOrFail($movimento));

        return RespostaApi::sucesso($this->sessaoArray(SessaoCaixa::query()->findOrFail($id)), 'Movimento removido.');
    }

    public function fecharSessao(Request $r, int $id): JsonResponse
    {
        $this->exigir('teso_caixa_fechar');
        $d = $r->validate(['saldo_fisico' => ['required', 'numeric', 'min:0'], 'data' => ['required', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso($this->sessaoArray($this->caixa->fechar(SessaoCaixa::query()->findOrFail($id), (string) $d['saldo_fisico'], $d['data'])), 'Sessão fechada.');
    }

    public function contabilizarSessao(int $id): JsonResponse
    {
        $this->exigir('teso_caixa_contabilizar');

        return RespostaApi::sucesso($this->sessaoArray($this->caixa->contabilizar(SessaoCaixa::query()->findOrFail($id))), 'Sessão contabilizada.');
    }

    public function descontabilizarSessao(Request $r, int $id): JsonResponse
    {
        $this->exigir('teso_caixa_contabilizar');

        return RespostaApi::sucesso($this->sessaoArray($this->caixa->descontabilizar(SessaoCaixa::query()->findOrFail($id), $this->motivo($r))), 'Sessão descontabilizada (estorno).');
    }

    public function eliminarSessao(int $id): JsonResponse
    {
        $this->exigir('teso_caixa_eliminar');
        $this->caixa->eliminar(SessaoCaixa::query()->findOrFail($id));

        return RespostaApi::sucesso(null, 'Sessão eliminada.');
    }

    // ─────────── Conferência de caixa ───────────

    public function conferencias(): JsonResponse
    {
        $this->exigir('teso_gestao_conferencia_view');

        return RespostaApi::sucesso(ConferenciaCaixa::query()->orderByDesc('data_conferencia')->limit(200)->get(), 'Conferências de caixa.');
    }

    public function gravarConferencia(Request $r, ?int $id = null): JsonResponse
    {
        $this->exigir('teso_conf_registar');
        $d = $r->validate(['codigo_conta' => ['required', 'string', 'max:20'], 'data_conferencia' => ['required', 'date_format:Y-m-d'],
            'denominacoes' => ['required', 'array'], 'denominacoes.*' => ['integer', 'min:0'], 'saldo_externo' => ['nullable', 'numeric'],
            'justificacao' => ['nullable', 'string', 'max:4000']]);
        $c = $this->conferencia->gravar($d, $id ? ConferenciaCaixa::query()->findOrFail($id) : null);

        return $id ? RespostaApi::sucesso($c, 'Conferência actualizada.') : RespostaApi::criado($c, 'Conferência registada (rascunho).');
    }

    public function finalizarConferencia(Request $r, int $id): JsonResponse
    {
        $this->exigir('teso_conf_registar');
        $d = $r->validate(['regularizar' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->conferencia->finalizar(ConferenciaCaixa::query()->findOrFail($id), (bool) ($d['regularizar'] ?? false)), 'Conferência finalizada.');
    }

    public function assinarConferencia(int $id): JsonResponse
    {
        $this->exigir('teso_conf_assinar');

        return RespostaApi::sucesso($this->conferencia->assinar(ConferenciaCaixa::query()->findOrFail($id)), 'Conferência assinada.');
    }

    public function reabrirConferencia(Request $r, int $id): JsonResponse
    {
        $this->exigir('teso_conf_reabrir');

        return RespostaApi::sucesso($this->conferencia->reabrir(ConferenciaCaixa::query()->findOrFail($id), $this->motivo($r)), 'Conferência reaberta.');
    }

    // ─────────── Contas de tesouraria ───────────

    public function contas(): JsonResponse
    {
        $this->exigir('teso_gestao_pagamentos_view', 'teso_folha_caixa_view', 'teso_gestao_conferencia_view');

        return RespostaApi::sucesso($this->config->todas(), 'Contas de tesouraria.');
    }

    public function definirContas(Request $r): JsonResponse
    {
        $this->exigir('teso_integrar');
        $regras = ['contas' => ['required', 'array']];
        foreach (array_keys(ServicoConfigTesouraria::CHAVES) as $k) {
            $regras["contas.{$k}"] = ['nullable', 'string', 'max:20'];
        }
        $this->config->definir($r->validate($regras)['contas']);

        return RespostaApi::sucesso($this->config->todas(), 'Contas de tesouraria actualizadas.');
    }

    private function sessaoArray(SessaoCaixa $s): array
    {
        $s->refresh();

        return $s->toArray() + ['saldo_sistema' => $this->caixa->saldoSistema($s),
            'diferenca' => $s->saldo_fisico !== null && $s->saldo_fecho !== null ? bcsub((string) $s->saldo_fisico, (string) $s->saldo_fecho, 2) : null,
            'movimentos' => MovimentoCaixa::query()->where('sessao_caixa_id', $s->id)->orderBy('data_documento')->orderBy('id')->get()->toArray()];
    }

    private function motivo(Request $r): string
    {
        return $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo'])['motivo'];
    }
}
