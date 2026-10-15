<?php

namespace App\Http\Controllers\Api\Tesouraria;

use App\Http\Controllers\Controller;
use App\Models\CartaPagamentoBancario;
use App\Models\DocumentoTesouraria;
use App\Models\ModeloCartaPagamento;
use App\Services\Tesouraria\ServicoCartasPagamento;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/tesouraria/cartas — Tesouraria › Cartas de Pagamento (ecrã teso_cartas; Fase D, ADR-071).
 * A carta nasce de um pagamento gravado (conta 42/43 com IBAN) e não gera lançamentos.
 * Permissões (catálogo): teso_cartas_view (consultar), teso_carta_emitir (emitir/anular), teso_carta_modelo (texto padrão).
 * OWASP A01: a permissão verifica-se antes da validação; os registos são resolvidos dentro da empresa activa (PertenceEmpresa → 404).
 */
final class CartasPagamentoController extends Controller
{
    private const CONSULTA = ['teso_cartas_view', 'teso_carta_emitir'];

    public function __construct(private readonly ServicoCartasPagamento $cartas) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::CONSULTA);
        $f = $r->validate(['estado' => ['nullable', 'in:EMITIDA,ANULADA'], 'tipo' => ['nullable', 'in:'.implode(',', array_keys(ServicoCartasPagamento::TIPOS))],
            'ano' => ['nullable', 'integer', 'between:1900,2999'], 'documento_tesouraria_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'],
            'por_pagina' => ['nullable', 'integer', 'between:1,100']]);

        return RespostaApi::paginado($this->cartas->listar($f), null, 'Cartas de pagamento.');
    }

    public function show(int $carta): JsonResponse
    {
        $this->exigir(...self::CONSULTA);

        return RespostaApi::sucesso($this->cartas->detalhe(CartaPagamentoBancario::query()->findOrFail($carta)), 'Carta de pagamento.');
    }

    public function anular(Request $r, int $carta): JsonResponse
    {
        $this->exigir('teso_carta_emitir');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:3', 'max:1000']]);
        $c = $this->cartas->anular(CartaPagamentoBancario::query()->findOrFail($carta), $d['motivo']);

        return RespostaApi::sucesso($this->cartas->detalhe($c), "Carta {$c->numero} anulada.");
    }

    /** Pagamentos (não anulados) sobre contas 42/43 que podem originar cartas. */
    public function documentos(Request $r): JsonResponse
    {
        $this->exigir('teso_carta_emitir');
        $f = $r->validate(['q' => ['nullable', 'string', 'max:100'], 'data_de' => ['nullable', 'date'], 'data_ate' => ['nullable', 'date'],
            'por_pagina' => ['nullable', 'integer', 'between:1,100']]);

        return RespostaApi::paginado($this->cartas->documentosElegiveis($f), null, 'Pagamentos que podem emitir carta.');
    }

    public function elegibilidade(int $documento): JsonResponse
    {
        $this->exigir('teso_carta_emitir');

        return RespostaApi::sucesso($this->cartas->elegibilidade(DocumentoTesouraria::query()->findOrFail($documento)), 'Elegibilidade do pagamento.');
    }

    public function emitir(Request $r, int $documento): JsonResponse
    {
        $this->exigir('teso_carta_emitir');
        $d = $r->validate([
            'beneficiarios' => ['required', 'array', 'min:1', 'max:5000'],
            'beneficiarios.*.terceiro_id' => ['nullable', 'integer'], 'beneficiarios.*.colaborador_id' => ['nullable', 'integer'],
            'beneficiarios.*.conta_bancaria_id' => ['nullable', 'integer'],
            'modelo_id' => ['nullable', 'integer'], 'assunto' => ['nullable', 'string', 'max:100'], 'corpo' => ['nullable', 'string', 'max:10000'],
            'nome_assinatura' => ['nullable', 'string', 'max:255'], 'cargo_assinatura' => ['nullable', 'string', 'max:100'], 'local' => ['nullable', 'string', 'max:100'],
            'data' => ['nullable', 'date'], 'confirmar_repetidos' => ['nullable', 'boolean'],
        ]);
        $c = $this->cartas->emitir(DocumentoTesouraria::query()->findOrFail($documento), $d);

        return RespostaApi::criado($this->cartas->detalhe($c), "Carta {$c->numero} emitida.");
    }

    // ------------------------------------------------------------------ modelos de texto

    public function modelos(): JsonResponse
    {
        $this->exigir('teso_cartas_view', 'teso_carta_emitir', 'teso_carta_modelo');

        return RespostaApi::sucesso($this->cartas->modelos(), 'Modelos de texto das cartas.');
    }

    public function criarModelo(Request $r): JsonResponse
    {
        $this->exigir('teso_carta_modelo');
        $d = $r->validate(['tipo' => ['required', 'in:'.implode(',', array_keys(ServicoCartasPagamento::TIPOS))]] + $this->regrasModelo());

        return RespostaApi::criado($this->cartas->gravarModelo($d), 'Modelo de texto criado.');
    }

    public function atualizarModelo(Request $r, int $modelo): JsonResponse
    {
        $this->exigir('teso_carta_modelo');
        $d = $r->validate($this->regrasModelo());

        return RespostaApi::sucesso($this->cartas->gravarModelo($d, ModeloCartaPagamento::query()->findOrFail($modelo)), 'Modelo de texto gravado. As cartas já emitidas não mudam.');
    }

    public function predefinirModelo(int $modelo): JsonResponse
    {
        $this->exigir('teso_carta_modelo');

        return RespostaApi::sucesso($this->cartas->predefinirModelo(ModeloCartaPagamento::query()->findOrFail($modelo)), 'Modelo predefinido.');
    }

    public function eliminarModelo(int $modelo): JsonResponse
    {
        $this->exigir('teso_carta_modelo');
        $this->cartas->eliminarModelo(ModeloCartaPagamento::query()->findOrFail($modelo));

        return RespostaApi::sucesso(null, 'Modelo de texto eliminado.');
    }

    /** @return array<string, list<string>> */
    private function regrasModelo(): array
    {
        return ['nome' => ['required', 'string', 'max:100'], 'assunto' => ['required', 'string', 'max:50'], 'corpo' => ['required', 'string', 'max:10000'],
            'nome_assinatura' => ['nullable', 'string', 'max:255'], 'cargo_assinatura' => ['nullable', 'string', 'max:100'], 'local' => ['nullable', 'string', 'max:100'],
            'predefinido' => ['nullable', 'boolean']];
    }
}
