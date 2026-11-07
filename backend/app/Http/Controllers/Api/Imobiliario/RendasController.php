<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\RendaArrendamento;
use App\Services\Imobiliario\CatalogoContratos;
use App\Services\Imobiliario\ServicoFacturacaoRendas;
use App\Services\Imobiliario\ServicoPagamentoRendas;
use App\Services\Imobiliario\ServicoRendas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rendas do período (Onda 2, ecrã imo_rendas). Ver: imo_rendas_view. Gerar e recalcular: imo_renda_gerar; aprovar e anular: imo_renda_aprovar;
 * facturar (lado senhorio): imo_renda_facturar; pagar e desligar o pagamento (lado inquilino): imo_renda_pagar; debitar um encargo ao
 * inquilino: imo_sub_debitar. Quem aprova não gera nem factura, e quem factura não paga (pares de segregação do catálogo).
 *  GET  /imobiliario/rendas?periodo=AAAA-MM&posicao=SENHORIO|INQUILINO   rendas cujo período cobre o mês, com conta corrente (só leitura)
 *  POST /imobiliario/rendas/gerar-tudo|aprovar|facturar|pagar
 *  POST /imobiliario/rendas/{id}/anular|recalcular|extras|desligar-pagamento|retencao
 */
final class RendasController extends Controller
{
    private const PERIODO = ['nullable', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])$/'];

    public function __construct(
        private readonly ServicoRendas $rendas,
        private readonly ServicoFacturacaoRendas $facturacao,
        private readonly ServicoPagamentoRendas $pagamento,
    ) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('imo_rendas_view', 'imo_contratos_view', 'imo_sub_view');
        $d = $r->validate(['periodo' => self::PERIODO, 'posicao' => ['nullable', Rule::in(array_keys(CatalogoContratos::LADOS))]], ['periodo.regex' => 'O período tem de ter o formato AAAA-MM.']);

        return RespostaApi::sucesso(new ImobiliarioResource($this->rendas->doPeriodo($d['periodo'] ?? now()->format('Y-m'), $d['posicao'] ?? 'SENHORIO')), 'Rendas do período.');
    }

    public function gerarTudo(Request $r): JsonResponse
    {
        $this->exigir('imo_renda_gerar');
        $d = $r->validate(['ate' => ['nullable', 'date_format:Y-m-d']]);
        $res = $this->rendas->gerarTudo($d['ate'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($res), "{$res['criadas']} renda(s) prevista(s) criada(s).".($res['problemas'] ? ' '.count($res['problemas']).' contrato(s) com problema.' : ''));
    }

    public function aprovar(Request $r): JsonResponse
    {
        $this->exigir('imo_renda_aprovar');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer', 'min:1']], ['ids.required' => 'Marque as rendas a aprovar.']);
        $res = $this->rendas->aprovar($d['ids']);

        return RespostaApi::sucesso(new ImobiliarioResource($res), "{$res['aprovadas']} renda(s) aprovada(s).");
    }

    public function anular(Request $r, int $renda): JsonResponse
    {
        $this->exigir('imo_renda_aprovar');
        $d = $r->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $x = $this->rendas->anular(RendaArrendamento::query()->findOrFail($renda), $d['motivo'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($this->um($x)), 'Renda anulada.');
    }

    public function recalcular(Request $r, int $renda): JsonResponse
    {
        $this->exigir('imo_renda_gerar');
        $d = $r->validate(['desconto' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'notas' => ['nullable', 'string', 'max:1000']]);
        $x = $this->rendas->recalcular(RendaArrendamento::query()->findOrFail($renda), $d);

        return RespostaApi::sucesso(new ImobiliarioResource($this->um($x)), 'Renda recalculada. Se estava aprovada, tem de ser aprovada de novo.');
    }

    public function extra(Request $r, int $renda): JsonResponse
    {
        $this->exigir('imo_sub_debitar');
        $d = $r->validate(['designacao' => ['required', 'string', 'max:150'], 'valor' => ['required', 'numeric', 'min:0', 'max:9999999999999999']],
            ['designacao.required' => 'Indique a designação do encargo.', 'valor.required' => 'Indique o valor a debitar.']);
        $x = $this->rendas->adicionarExtra(RendaArrendamento::query()->findOrFail($renda), $d['designacao'], $d['valor']);

        return RespostaApi::sucesso(new ImobiliarioResource($this->um($x)), 'Encargo debitado na renda. Aprove-a de novo antes de facturar.');
    }

    public function facturar(Request $r): JsonResponse
    {
        $this->exigir('imo_renda_facturar');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer', 'min:1'], 'data' => ['nullable', 'date_format:Y-m-d'],
            'conta_disponibilidade' => ['nullable', 'string', 'max:20'], 'meio_pagamento' => ['nullable', 'string', 'max:20']], ['ids.required' => 'Marque as rendas a facturar.']);
        $res = $this->facturacao->facturarVarias($d['ids'], $d);

        return RespostaApi::sucesso(new ImobiliarioResource($res), count($res['feitas']).' factura(s) emitida(s).'.($res['falhas'] ? ' '.count($res['falhas']).' falhou(aram).' : ''));
    }

    public function retencao(int $renda): JsonResponse
    {
        $this->exigir('imo_renda_facturar');
        $x = $this->facturacao->registarRetencao($renda);

        return RespostaApi::sucesso(new ImobiliarioResource($this->um($x)), 'Imposto Predial retido registado como crédito de imposto.');
    }

    public function pagar(Request $r): JsonResponse
    {
        $this->exigir('imo_renda_pagar');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer', 'min:1'], 'data' => ['nullable', 'date_format:Y-m-d'],
            'referencia' => ['nullable', 'string', 'max:60'], 'conta_financeira' => ['nullable', 'string', 'max:20']], ['ids.required' => 'Marque as rendas a pagar.']);
        $res = $this->pagamento->pagarVarias($d['ids'], $d);

        return RespostaApi::sucesso(new ImobiliarioResource($res), count($res['feitas']).' pagamento(s) criado(s) na Tesouraria, por conferir e integrar.'.($res['falhas'] ? ' '.count($res['falhas']).' falhou(aram).' : ''));
    }

    public function desligarPagamento(Request $r, int $renda): JsonResponse
    {
        $this->exigir('imo_renda_pagar');
        $d = $r->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $x = $this->pagamento->desligar($renda, $d['motivo'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($this->um($x)), 'Pagamento desligado: a renda voltou a aprovada.');
    }

    /** @return array<string, mixed> */
    private function um(RendaArrendamento $r): array
    {
        return $this->rendas->enriquecer(collect([$r]))[0];
    }
}
