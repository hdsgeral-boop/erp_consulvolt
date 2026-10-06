<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendas\EmitirDocumentoRequest;
use App\Http\Resources\Vendas\VendaResource;
use App\Models\Venda;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Services\Vendas\ServicoDocumentosVenda;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** /api/vendas/documentos — ecrã "vendas_faturacao" do legado (js/ui_sales.js). */
final class DocumentoVendaController extends Controller
{
    public function __construct(
        private readonly ServicoDocumentosVenda $documentos,
        private readonly ServicoContabilizacaoVendas $contabilizacao,
    ) {}

    /** GET — documentos com filtros (tipo, cliente, estado, período, contabilizado, pesquisa por n.º). */
    public function index(Request $request): JsonResponse
    {
        $this->exigir('vendas_faturacao_view');
        $f = $request->validate([
            'tipo_documento' => ['nullable', Rule::in(array_merge(Venda::FISCAIS, Venda::NAO_FISCAIS, ['ND']))],
            'tipos' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z]{2}(,[A-Z]{2})*$/'],   // separadores do legado (ex.: FT,FR,NC)
            'cliente_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'string', 'max:20'], 'contabilizado' => ['nullable', 'boolean'],
            'data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d'],
            'pesquisa' => ['nullable', 'string', 'max:100'], 'pendentes' => ['nullable', 'boolean'],
            'estado_fe' => ['nullable', Rule::in(self::ESTADOS_FE)],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        $pagina = Venda::query()->with('cliente:id,nome,nif,endereco')
            ->when($f['tipo_documento'] ?? null, fn ($q, $v) => $q->where('tipo_documento', $v))
            ->when($f['cliente_id'] ?? null, fn ($q, $v) => $q->where('cliente_id', $v))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when(isset($f['contabilizado']), fn ($q) => $q->where('contabilizado', (bool) $f['contabilizado']))
            ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('data_emissao', '>=', $v))
            ->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('data_emissao', '<', date('Y-m-d', strtotime("{$v} +1 day"))))
            ->when($f['tipos'] ?? null, fn ($q, $v) => $q->whereIn('tipo_documento', explode(',', $v)))
            ->when($f['pesquisa'] ?? null, function ($q, $v) {   // n.º do documento ou nome do cliente (pesquisa do legado)
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%';
                $q->where(fn ($x) => $x->where('numero_documento', 'ilike', $termo)->orWhereHas('cliente', fn ($c) => $c->where('nome', 'ilike', $termo)));
            })
            ->when(! empty($f['pendentes']), fn ($q) => $q->whereIn('tipo_documento', ['FT'])->where('valor_pendente', '>', 0)
                ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO')))
            ->when($f['estado_fe'] ?? null, fn ($q, $v) => self::filtrarEstadoFe($q, $v))
            ->orderByDesc('data_emissao')->orderByDesc('id')
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));

        return RespostaApi::paginado($pagina, VendaResource::class);
    }

    /** Estados AGT do filtro: os mesmos contadores de ServicoEnvioAgt::resumo() (ecrã «Facturação electrónica»). */
    public const ESTADOS_FE = ['COM_ERROS_LOCAIS', 'POR_ENVIAR', 'ENVIADO', 'VALIDO', 'INVALIDO', 'REJEITADO', 'ERRO'];

    /**
     * Filtro por estado AGT com a regra de ServicoEnvioAgt::resumo()/estadoEnvio(): fora do regime não há estado;
     * fe_estado ≠ PRONTO = erros locais; PRONTO sem estado de envio = por enviar.
     */
    private static function filtrarEstadoFe($q, string $estado)
    {
        $q->where('fe_regime', true);
        if ($estado === 'COM_ERROS_LOCAIS') {
            return $q->where(fn ($q) => $q->whereNull('fe_estado')->orWhere('fe_estado', '<>', 'PRONTO'));
        }
        $q->where('fe_estado', 'PRONTO');

        return $estado === 'POR_ENVIAR'
            ? $q->where(fn ($q) => $q->whereRaw("fe_envio->>'estado' IS NULL")->orWhereRaw("fe_envio->>'estado' = 'POR_ENVIAR'"))
            : $q->whereRaw("fe_envio->>'estado' = ?", [$estado]);
    }

    public function show(int $venda): JsonResponse
    {
        $this->exigir('vendas_faturacao_view');

        return RespostaApi::sucesso($this->detalhe(Venda::query()->findOrFail($venda)), 'Documento obtido com sucesso.');
    }

    public function store(EmitirDocumentoRequest $request): JsonResponse
    {
        $this->exigir('vendas_fat_emitir');
        // decisão 8: no ecrã de emissão, um preço diferente do da ficha exige vendas_alterar_preco (verificado no serviço)
        $venda = $this->documentos->emitir($request->validated(), null, true);

        return RespostaApi::criado($this->detalhe($venda), "Documento {$venda->numero_documento} emitido com sucesso.");
    }

    /** POST /faturar-guias — uma factura a partir de várias guias de remessa do mesmo cliente (M-18). */
    public function faturarGuias(Request $request): JsonResponse
    {
        $this->exigir('vendas_fat_emitir');
        $d = $request->validate([
            'guias' => ['required', 'array', 'min:1', 'max:100'], 'guias.*' => ['integer'],
            'data_emissao' => ['nullable', 'date_format:Y-m-d'], 'observacoes' => ['nullable', 'string', 'max:4000'],
            'modo_pagamento' => ['nullable', Rule::in(['PRONTO', 'PRAZO', 'MARCOS'])], 'plano_pagamentos' => ['nullable', 'array', 'max:60'],
            'plano_pagamentos.*.percentagem' => ['required', 'numeric', 'gt:0', 'max:100'], 'plano_pagamentos.*.data' => ['nullable', 'date_format:Y-m-d'],
            'plano_pagamentos.*.descricao' => ['nullable', 'string', 'max:200'],
        ], [], ['guias' => 'guias de remessa']);
        $nova = $this->documentos->faturarGuias($d['guias'], $d);

        return RespostaApi::criado($this->detalhe($nova), "Factura {$nova->numero_documento} emitida a partir de ".count(array_unique($d['guias'])).' guia(s).');
    }

    /** POST /contabilizar — documentos seleccionados, cada um na sua transacção (M-06, postSelectedSales). */
    public function contabilizarLote(Request $request): JsonResponse
    {
        $this->exigir('vendas_fat_contabilizar');
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);
        $r = $this->contabilizacao->lote('documentos', true, $d['ids']);

        return RespostaApi::sucesso($r, "{$r['ok']} documento(s) contabilizado(s); {$r['erros']} com erro.");
    }

    /** POST /descontabilizar — estorno dos documentos seleccionados (unpostSelectedSales), com motivo. */
    public function descontabilizarLote(Request $request): JsonResponse
    {
        $this->exigir('vendas_fat_descontab');
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo']);
        $r = $this->contabilizacao->lote('documentos', false, $d['ids'], $d['motivo']);

        return RespostaApi::sucesso($r, "{$r['ok']} documento(s) descontabilizado(s); {$r['erros']} com erro.");
    }

    /** POST /{id}/converter — OR/PF → NE/FT, NE → GR/FT, GR → FT/GD, FT/FR → NC. */
    public function converter(Request $request, int $venda): JsonResponse
    {
        $this->exigir('vendas_fat_emitir');
        $d = $request->validate([
            'tipo_destino' => ['required', Rule::in(['NE', 'FT', 'NC', 'GR', 'GD'])],
            'data_emissao' => ['nullable', 'date_format:Y-m-d'],
            'motivo_nota_credito' => ['required_if:tipo_destino,NC', 'nullable', 'string', 'max:200'],
            'ignorar_validade' => ['nullable', 'boolean'], 'observacoes' => ['nullable', 'string', 'max:4000'],
            'armazem_id' => ['nullable', 'integer'], 'devolucao_mercadoria' => ['nullable', 'boolean'],
        ]);
        $nova = $this->documentos->converter(Venda::query()->findOrFail($venda), $d['tipo_destino'], $d);

        return RespostaApi::criado($this->detalhe($nova), "Documento {$nova->numero_documento} criado a partir da conversão.");
    }

    /** POST /{id}/anular — só documentos não fiscais e não convertidos. */
    public function anular(int $venda): JsonResponse
    {
        $this->exigir('vendas_fat_del');
        $v = $this->documentos->anular(Venda::query()->findOrFail($venda));

        return RespostaApi::sucesso($this->detalhe($v), "Documento {$v->numero_documento} anulado.");
    }

    public function contabilizar(int $venda): JsonResponse
    {
        $this->exigir('vendas_fat_contabilizar');
        $v = $this->contabilizacao->contabilizar(Venda::query()->findOrFail($venda));

        return RespostaApi::sucesso($this->detalhe($v), "Documento {$v->numero_documento} contabilizado (lançamento {$v->numero_lan_contabilizacao}).");
    }

    /** POST /{id}/descontabilizar — estorno com rasto (ADR-016). */
    public function descontabilizar(Request $request, int $venda): JsonResponse
    {
        $this->exigir('vendas_fat_descontab');
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo']);
        $v = $this->contabilizacao->descontabilizar(Venda::query()->findOrFail($venda), $d['motivo']);

        return RespostaApi::sucesso($this->detalhe($v), "Documento {$v->numero_documento} descontabilizado (estorno registado).");
    }

    private function detalhe(Venda $venda): array
    {
        $venda->load(['cliente:id,nome,nif,endereco', 'itensVenda' => fn ($q) => $q->orderBy('id')]);
        $relacionados = DB::table('vendas_documentos_relacionados as r')
            ->join('vendas as o', fn ($j) => $j->on('o.id', '=', DB::raw('CASE WHEN r.venda_id = '.(int) $venda->id.' THEN r.venda_relacionada_id ELSE r.venda_id END')))
            ->where('r.empresa_id', $venda->empresa_id)
            ->where(fn ($q) => $q->where('r.venda_id', $venda->id)->orWhere('r.venda_relacionada_id', $venda->id))
            ->get(['o.id', 'o.tipo_documento', 'o.numero_documento', 'o.total_bruto', 'o.estado',
                DB::raw('CASE WHEN r.venda_id = '.(int) $venda->id." THEN 'ORIGEM' ELSE 'DERIVADO' END as relacao")]);

        return (new VendaResource($venda))->resolve() + ['documentos_relacionados' => $relacionados];
    }
}
