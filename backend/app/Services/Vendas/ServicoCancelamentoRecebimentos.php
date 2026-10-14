<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\DocumentoTesouraria;
use App\Models\ItemDocumentoTesouraria;
use App\Models\ItemReciboVenda;
use App\Models\MovimentoCaixa;
use App\Models\ReciboVenda;
use App\Models\Venda;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «Factura paga: cancelar o recebimento antes da Nota de Crédito» (js/shared/anulacao_documentos.js do legado novo:
 * recebimentosDaFactura, cancelarRecebimentos, exigirRecebimentoCancelado; usado em ui_sales.js:1758 e :2763).
 *
 * Numa só transacção, cancela os recibos e os documentos de tesouraria que liquidam a factura: ficam ANULADOS (nunca
 * apagados), com motivo, autor e data. Diferença em relação ao legado, que recusava os já contabilizados: aqui o recibo
 * contabilizado é descontabilizado (estorno, ServicoContabilizacaoVendas::descontabilizarRecibo) e o documento de
 * tesouraria integrado é desintegrado (estorno, ServicoDocumentosTesouraria::desintegrar) antes de ser anulado — os
 * serviços existentes, com as mesmas regras (exercício aberto, reconciliação bancária, prestação de contas do POS…).
 * Se alguma peça falhar, nada fica cancelado.
 *
 * Fica de fora (recusa com explicação):
 *   - a factura-recibo (FR): o recebimento faz parte do próprio documento e do mesmo lançamento (D disponibilidade /
 *     C proveitos); cancelá-lo exigiria refazer a contabilização da FR;
 *   - adiantamentos alocados à factura (cada alocação tem lançamento próprio);
 *   - recebimentos na Folha de Caixa (corrigem-se na sessão de caixa).
 */
final class ServicoCancelamentoRecebimentos
{
    public function __construct(
        private readonly ServicoRecibosVenda $recibos,
        private readonly ServicoContabilizacaoVendas $contabilizacao,
        private readonly ServicoDocumentosTesouraria $tesouraria,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * O que seria cancelado e o que impede o cancelamento (nada é gravado).
     *
     * @return array<string, mixed>
     */
    public function previsualizar(Venda $venda): array
    {
        $recibos = $this->recibosDaFactura($venda);
        $tesouraria = $this->tesourariaDaFactura($venda);
        $caixa = MovimentoCaixa::query()->where('venda_id', $venda->id)->orderBy('id')->get(['id', 'sessao_caixa_id', 'data_documento', 'valor', 'tipo']);
        $bloqueios = [];

        if (! in_array($venda->tipo_documento, ['FT', 'FR'], true)) {
            $bloqueios[] = 'Só as facturas têm recebimentos a cancelar.';
        }
        if ($venda->estado === 'ANULADO') {
            $bloqueios[] = "O documento {$venda->numero_documento} está anulado.";
        }
        if ($venda->tipo_documento === 'FR') {
            $bloqueios[] = 'Numa factura-recibo o recebimento faz parte do próprio documento e do mesmo lançamento: não se cancela à parte. '
                .'Para a corrigir, fale com o responsável da contabilidade (estorno da factura-recibo e nova emissão).';
        }
        foreach ($recibos as $r) {
            if ($r->eAdiantamento()) {
                $bloqueios[] = "O adiantamento {$r->numero_recibo} está alocado a esta factura (a alocação tem lançamento próprio): não se cancela daqui.";
            }
        }
        if ($caixa->isNotEmpty()) {
            $bloqueios[] = 'Há recebimentos registados na Folha de Caixa (sessão '.$caixa->pluck('sessao_caixa_id')->unique()->implode(', ')
                .'): corrija-os em Tesouraria › Folha de Caixa.';
        }

        $porRecibo = $recibos->sum(fn ($r) => (float) $r->montante_factura);
        $porTesouraria = $tesouraria->where('estado', 'INTEGRADO')->sum(fn ($d) => (float) $d->valor_factura);
        $pago = (float) ($venda->valor_pago ?? 0);
        if (! $bloqueios && $pago > 0.01 && $porRecibo + $porTesouraria + 0.01 < $pago) {
            $bloqueios[] = 'Parte do valor pago ('.number_format($pago - $porRecibo - $porTesouraria, 2, ',', ' ')
                .' Kz) não vem de recibos nem de documentos de tesouraria (por exemplo, do POS ou da lavandaria): corrija-o na origem.';
        }

        return [
            'venda' => ['id' => $venda->id, 'numero_documento' => $venda->numero_documento, 'tipo_documento' => $venda->tipo_documento,
                'estado' => $venda->estado, 'total_bruto' => (string) $venda->total_bruto, 'valor_pago' => number_format($pago, 2, '.', '')],
            'recibos' => $recibos->map(fn (ReciboVenda $r) => [
                'id' => $r->id, 'numero' => $r->numero_recibo, 'data' => $r->data?->toDateString(), 'montante_total' => (string) $r->montante_total,
                'montante_factura' => number_format((float) $r->montante_factura, 2, '.', ''), 'contabilizado' => (bool) $r->contabilizado,
                'outras_facturas' => $this->outrasFacturasDoRecibo($r, $venda),
            ])->values(),
            'tesouraria' => $tesouraria->map(fn (DocumentoTesouraria $d) => [
                'id' => $d->id, 'numero' => $d->numero_documento ?: ($d->referencia ?: "TES-{$d->id}"), 'data' => $d->data_documento?->toDateString(),
                'valor_total' => (string) $d->valor_total, 'valor_factura' => number_format((float) $d->valor_factura, 2, '.', ''),
                'estado' => $d->estado, 'integrado' => $d->estado === 'INTEGRADO', 'outras_facturas' => $this->outrasFacturasDaTesouraria($d, $venda),
            ])->values(),
            'caixa' => $caixa->map(fn ($m) => ['id' => $m->id, 'sessao_caixa_id' => $m->sessao_caixa_id, 'data' => $m->data_documento?->toDateString(),
                'valor' => (string) $m->valor])->values(),
            'tem_recebimentos' => $recibos->isNotEmpty() || $tesouraria->isNotEmpty() || $caixa->isNotEmpty() || $pago > 0.01,
            'com_estorno' => $recibos->contains('contabilizado', true) || $tesouraria->contains('estado', 'INTEGRADO'),
            'bloqueios' => $bloqueios,
            'pode_cancelar' => ! $bloqueios && ($recibos->isNotEmpty() || $tesouraria->isNotEmpty()),
        ];
    }

    /**
     * Cancela os recibos e os documentos de tesouraria da factura; a factura volta a pendente (pago recalculado).
     *
     * @return array{recibos: list<string>, tesouraria: list<string>, venda: Venda}
     */
    public function cancelar(Venda $venda, string $motivo): array
    {
        return DB::transaction(function () use ($venda, $motivo) {
            $venda = Venda::query()->lockForUpdate()->findOrFail($venda->id);
            $p = $this->previsualizar($venda);
            if ($p['bloqueios']) {
                throw new ErroNegocio(implode(' ', $p['bloqueios']), 'RECEBIMENTO_NAO_CANCELAVEL', 422, ['bloqueios' => $p['bloqueios']]);
            }
            if (! $p['pode_cancelar']) {
                throw new ErroNegocio("A factura {$venda->numero_documento} não tem recibos nem documentos de tesouraria por cancelar.", 'SEM_RECEBIMENTOS', 422);
            }
            $texto = "Cancelamento do recebimento para Nota de Crédito da factura {$venda->numero_documento}: {$motivo}";
            $autor = Auth::user()?->nome_utilizador;
            $feitos = ['recibos' => [], 'tesouraria' => []];

            foreach ($this->recibosDaFactura($venda) as $r) {
                $r = ReciboVenda::query()->lockForUpdate()->findOrFail($r->id);
                if ($r->contabilizado) {
                    $r = $this->contabilizacao->descontabilizarRecibo($r, mb_substr($texto, 0, 500));
                }
                $this->recibos->anular($r, mb_substr($texto, 0, 500));
                $feitos['recibos'][] = (string) $r->numero_recibo;
            }
            foreach ($this->tesourariaDaFactura($venda) as $d) {
                $d = DocumentoTesouraria::query()->findOrFail($d->id);   // sem o atributo calculado valor_factura
                if ($d->estado === 'INTEGRADO') {
                    $d = $this->tesouraria->desintegrar($d, mb_substr($texto, 0, 500));
                }
                $d = $this->tesouraria->anular($d, mb_substr($texto, 0, 500));
                $d->forceFill(['anulado_por' => $autor !== null ? mb_substr($autor, 0, 100) : null])->save();
                $feitos['tesouraria'][] = (string) ($d->numero_documento ?: ($d->referencia ?: "TES-{$d->id}"));
            }

            $venda->refresh();
            if (bccomp((string) ($venda->valor_pago ?? '0'), '0.01', 2) > 0) {   // defesa: algo pago por outra via
                throw new ErroNegocio("Depois do cancelamento a factura {$venda->numero_documento} continua com {$venda->valor_pago} pagos por outra via: nada foi cancelado.",
                    'RECEBIMENTO_NAO_CANCELAVEL', 422);
            }
            $this->auditoria->registar('Vendas/Facturação', 'Cancelar recebimentos da factura',
                "{$venda->numero_documento}: ".count($feitos['recibos']).' recibo(s) ['.implode(', ', $feitos['recibos']).'], '
                .count($feitos['tesouraria']).' documento(s) de tesouraria ['.implode(', ', $feitos['tesouraria']).']. Motivo: '.$motivo,
                'vendas', $venda->id, ['valor_pago' => $p['venda']['valor_pago']], ['valor_pago' => (string) $venda->valor_pago]);

            return $feitos + ['venda' => $venda];
        });
    }

    /** Recibos não anulados com linha nesta factura; montante_factura = o que liquidam DESTA factura. */
    private function recibosDaFactura(Venda $venda)
    {
        $porRecibo = ItemReciboVenda::query()->where('venda_id', $venda->id)->groupBy('recibo_venda_id')
            ->selectRaw('recibo_venda_id, sum(montante_pago) as montante')->pluck('montante', 'recibo_venda_id');

        return ReciboVenda::query()->whereIn('id', $porRecibo->keys())->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->orderBy('id')->get()->each(fn ($r) => $r->setAttribute('montante_factura', (string) $porRecibo[$r->id]));
    }

    /** Documentos de tesouraria (por integrar ou integrados) com linha ligada a esta factura. */
    private function tesourariaDaFactura(Venda $venda)
    {
        $porDoc = ItemDocumentoTesouraria::query()->where('venda_id', $venda->id)->groupBy('documento_tesouraria_id')
            ->selectRaw("documento_tesouraria_id, sum(CASE WHEN tipo_dc = 'C' THEN valor ELSE -valor END) as valor")->pluck('valor', 'documento_tesouraria_id');

        return DocumentoTesouraria::query()->whereIn('id', $porDoc->keys())->whereIn('estado', ['PENDENTE', 'INTEGRADO'])
            ->orderBy('id')->get()->each(fn ($d) => $d->setAttribute('valor_factura', (string) $porDoc[$d->id]));
    }

    /** @return list<string> outras facturas que voltam a pendentes por o recibo ser anulado */
    private function outrasFacturasDoRecibo(ReciboVenda $r, Venda $venda): array
    {
        return Venda::query()->whereIn('id', ItemReciboVenda::query()->where('recibo_venda_id', $r->id)->where('venda_id', '<>', $venda->id)->select('venda_id'))
            ->orderBy('id')->pluck('numero_documento')->map(fn ($n) => (string) $n)->all();
    }

    /** @return list<string> */
    private function outrasFacturasDaTesouraria(DocumentoTesouraria $d, Venda $venda): array
    {
        return Venda::query()->whereIn('id', ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $d->id)->whereNotNull('venda_id')
            ->where('venda_id', '<>', $venda->id)->select('venda_id'))->orderBy('id')->pluck('numero_documento')->map(fn ($n) => (string) $n)->all();
    }
}
