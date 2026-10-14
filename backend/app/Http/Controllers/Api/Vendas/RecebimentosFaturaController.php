<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Models\Venda;
use App\Services\Vendas\ServicoCancelamentoRecebimentos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * /api/vendas/documentos/{id}/recebimentos — «Factura paga: cancelar o recebimento antes da Nota de Crédito»
 * (AnularDocs.exigirRecebimentoCancelado / cancelarRecebimentos do legado novo).
 *
 * Permissões: vendas_fat_del (no legado novo, «Anular documentos de venda (a factura só com NC)» cobre cancelarRecebimentos)
 * e, para cada peça cancelada, a permissão de a anular no seu ecrã: recibos → vendas_recibos (+ vendas_fat_unpost se
 * contabilizado); tesouraria → teso_doc_eliminar (+ teso_desintegrar se integrado). Assim o atalho não dá mais poderes
 * do que fazer o mesmo à mão.
 */
final class RecebimentosFaturaController extends Controller
{
    public function __construct(private readonly ServicoCancelamentoRecebimentos $servico) {}

    /** GET — o que seria cancelado e o que o impede (nada é gravado). */
    public function show(int $venda): JsonResponse
    {
        $this->exigir('vendas_faturacao_view');
        $p = $this->servico->previsualizar(Venda::query()->findOrFail($venda));

        return RespostaApi::sucesso($p + ['permissoes_em_falta' => $this->permissoesEmFalta($p)], 'Recebimentos da factura obtidos.');
    }

    /** POST {motivo} — cancela os recibos e os documentos de tesouraria da factura (ficam ANULADOS, com estorno se contabilizados). */
    public function cancelar(Request $request, int $venda): JsonResponse
    {
        $this->exigir('vendas_fat_del');
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:400']], [], ['motivo' => 'motivo']);
        $v = Venda::query()->findOrFail($venda);
        if ($falta = $this->permissoesEmFalta($this->servico->previsualizar($v))) {
            throw new AccessDeniedHttpException('Sem permissão para: '.implode(', ', $falta).'.');
        }
        $r = $this->servico->cancelar($v, trim($d['motivo']));

        return RespostaApi::sucesso([
            'recibos' => $r['recibos'], 'tesouraria' => $r['tesouraria'],
            'venda' => ['id' => $r['venda']->id, 'numero_documento' => $r['venda']->numero_documento, 'estado' => $r['venda']->estado,
                'valor_pago' => (string) $r['venda']->valor_pago, 'valor_pendente' => (string) $r['venda']->valor_pendente],
        ], 'Recebimento cancelado ('.count($r['recibos']).' recibo(s), '.count($r['tesouraria']).' na tesouraria). Já pode emitir a nota de crédito.');
    }

    /**
     * @param  array<string, mixed>  $p  pré-visualização
     * @return list<string> descrição das permissões que faltam
     */
    private function permissoesEmFalta(array $p): array
    {
        $recibos = collect($p['recibos']);
        $teso = collect($p['tesouraria']);
        $precisa = array_filter([
            'vendas_fat_del' => 'anular documentos de venda',
            'vendas_recibos' => $recibos->isNotEmpty() ? 'anular recibos' : null,
            'vendas_fat_unpost' => $recibos->contains('contabilizado', true) ? 'descontabilizar recibos' : null,
            'teso_doc_eliminar' => $teso->isNotEmpty() ? 'anular documentos de tesouraria' : null,
            'teso_desintegrar' => $teso->contains('integrado', true) ? 'desintegrar documentos de tesouraria' : null,
        ]);

        return array_values(array_filter($precisa, fn ($_, $chave) => ! Gate::allows($chave), ARRAY_FILTER_USE_BOTH));
    }
}
