<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\CartaPagamentoBancario;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\RH\ServicoPagamentoSalarios;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ordem de pagamento bancária, cartas de pagamento e pagamento dos salários pela tesouraria. */
final class PagamentoSalariosController extends Controller
{
    public function __construct(private readonly ServicoPagamentoSalarios $pagamentos) {}

    public function ordem(Request $r, int $id): JsonResponse
    {
        $this->exigir('rh_rel_banco_view', 'rh_rel_banco');
        $d = $r->validate(['grupo' => ['nullable', 'in:'.implode(',', ServicoPagamentoSalarios::GRUPOS)]]);

        return RespostaApi::sucesso($this->pagamentos->ordemPagamento(PeriodoProcessamentoSalarial::query()->findOrFail($id), $d['grupo'] ?? 'TODOS'),
            'Ordem de pagamento bancária.');
    }

    public function cartas(Request $r): JsonResponse
    {
        $this->exigir('rh_rel_banco_view', 'processamento_view');
        $d = $r->validate(['periodo_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso(CartaPagamentoBancario::query()->when($d['periodo_id'] ?? null, fn ($q, $p) => $q->where('periodo_processamento_salarial_id', $p))
            ->orderByDesc('id')->get(), 'Cartas de pagamento.');
    }

    public function carta(int $carta): JsonResponse
    {
        $this->exigir('rh_rel_banco_view', 'processamento_view');

        return RespostaApi::sucesso($this->pagamentos->carta(CartaPagamentoBancario::query()->findOrFail($carta)), 'Carta de pagamento.');
    }

    public function emitir(Request $r, int $id): JsonResponse
    {
        $this->exigir('processamento_integrate');
        $d = $r->validate(['codigo_conta_bancaria' => ['required', 'string', 'max:20'], 'data' => ['required', 'date'], 'nome_assinatura' => ['nullable', 'string', 'max:255'],
            'grupo' => ['nullable', 'in:'.implode(',', ServicoPagamentoSalarios::GRUPOS)], 'colaboradores' => ['nullable', 'array', 'max:10000'], 'colaboradores.*' => ['integer']]);
        $c = $this->pagamentos->emitirCarta(PeriodoProcessamentoSalarial::query()->findOrFail($id), $d);

        return RespostaApi::criado($this->pagamentos->carta($c), 'Carta de pagamento emitida.');
    }

    public function eliminar(int $carta): JsonResponse
    {
        $this->exigir('processamento_integrate');
        $this->pagamentos->eliminarCarta(CartaPagamentoBancario::query()->findOrFail($carta));

        return RespostaApi::sucesso(null, 'Carta de pagamento eliminada.');
    }

    public function pagar(int $carta): JsonResponse
    {
        $this->exigir('teso_doc_emitir');
        $doc = $this->pagamentos->gerarPagamento(CartaPagamentoBancario::query()->findOrFail($carta));

        return RespostaApi::criado($doc, "Pagamento {$doc->numero_documento} criado na Tesouraria (pendente de integração).");
    }
}
