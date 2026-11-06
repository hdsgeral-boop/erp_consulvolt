<?php

namespace App\Services\Vendas;

use App\Models\PagamentoLavandaria;
use App\Models\Venda;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recibos da lavandaria (RC-LAV/<terminal>/<ano>/<n>, natureza ADIANTAMENTO ou PAGAMENTO) no SAF-T de facturação, secção Payments
 * (F6/Q16; ADR-049 deixara-os de fora). A factura-recibo da lavandaria já sai em SalesInvoices e não é recibo.
 *   - N.º SAF-T derivado do n.º do recibo, sem o alterar: «RG LAV<terminal><ano>/<n>» (padrão `^[^ ]+ [^/ ]+/[0-9]+$` do XSD);
 *   - a repartição pelas facturas segue a do próprio sistema (ServicoCaixaLavandaria::actualizarFaturas: recebimentos da ordem
 *     aplicados às facturas da ordem das mais antigas para as mais recentes); o que excede o facturado é adiantamento e sai numa
 *     linha sem SourceDocumentID; só as FT (conta corrente) são referenciadas — a FR já nasce paga;
 *   - um registo por recibo, um PaymentMethod por meio usado (valor líquido de troco); recibos anulados não saem.
 */
final class ServicoSaftRecibosLavandaria
{
    /** @var array<string, string> */
    private const MECANISMOS = ['NUMERARIO' => 'NU', 'TPA' => 'CD', 'TRANSFERENCIA' => 'TB', 'CONTA_CORRENTE' => 'OU'];

    /** @return Collection<int, PagamentoLavandaria> */
    public function consulta(string $inicio, string $fim): Collection
    {
        return PagamentoLavandaria::query()->whereIn('natureza_registo', ['ADIANTAMENTO', 'PAGAMENTO'])->whereBetween('data', [$inicio, $fim])
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))->orderBy('data')->orderBy('id')->get();
    }

    /** N.º do recibo no SAF-T: RC-LAV/T01/2026/00001 → «RG LAVT012026/1». */
    public static function numeroSaft(string $numeroRecibo): string
    {
        if (preg_match('#^RC-LAV/(.+)/(\d{4})/(\d+)$#', $numeroRecibo, $m)) {
            $serie = 'LAV'.preg_replace('/[^A-Za-z0-9]/', '', $m[1]).$m[2];

            return 'RG '.substr($serie, 0, 30).'/'.(ltrim($m[3], '0') ?: '0');
        }

        return RegrasNumeracaoAgt::numeroValido($numeroRecibo) ? $numeroRecibo : 'RG LAV/'.(preg_match('/(\d+)\s*$/', $numeroRecibo, $m) ? (ltrim($m[1], '0') ?: '0') : '0');
    }

    /**
     * Registos normalizados para o XML (a mesma forma que os recibos das Vendas).
     *
     * @return list<array<string, mixed>>
     */
    public function registos(string $inicio, string $fim): array
    {
        $recibos = $this->consulta($inicio, $fim);
        if ($recibos->isEmpty()) {
            return [];
        }
        $porPedido = $this->repartir($recibos->pluck('pedido_lavandaria_id')->unique()->all());
        $saida = [];
        foreach ($recibos as $r) {
            $metodos = [];
            foreach ($r->pos_pagamentos ?? [] as $p) {
                $valor = number_format((float) ($p['valor'] ?? 0), 2, '.', '');
                if (bccomp($valor, '0', 2) > 0) {
                    $metodos[] = [self::MECANISMOS[$p['tipo'] ?? ''] ?? 'OU', $valor];
                }
            }
            $montante = number_format((float) $r->montante, 2, '.', '');
            if (! $metodos) {
                $metodos[] = ['NU', $montante];
            }
            $linhas = [];
            foreach ($porPedido[$r->pedido_lavandaria_id][$r->id] ?? [['origem' => null, 'data' => null, 'credito' => $montante]] as $l) {
                $linhas[] = $l;
            }
            $saida[] = ['ref' => self::numeroSaft((string) $r->numero_recibo), 'original' => (string) $r->numero_recibo, 'data' => $r->data, 'criado' => $r->criado_em ?? $r->data,
                'cliente_id' => $r->cliente_id, 'metodos' => $metodos, 'montante' => $montante, 'linhas' => $linhas];
        }

        return $saida;
    }

    /**
     * Repartição de cada recibo pelas facturas da ordem (duas passagens ordenadas, como actualizarFaturas).
     *
     * @param  list<int>  $pedidos
     * @return array<int, array<int, list<array{origem: ?string, data: ?string, credito: string}>>> pedido → recibo → linhas
     */
    private function repartir(array $pedidos): array
    {
        $todos = PagamentoLavandaria::query()->whereIn('pedido_lavandaria_id', $pedidos)->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->orderBy('id')->get(['id', 'pedido_lavandaria_id', 'montante', 'natureza_registo'])->groupBy('pedido_lavandaria_id');
        $ligacoes = DB::table('pedidos_lavandaria_faturas')->whereIn('pedido_lavandaria_id', $pedidos)->get(['pedido_lavandaria_id', 'venda_id'])->groupBy('pedido_lavandaria_id');
        $saida = [];
        foreach ($pedidos as $pedido) {
            $ids = ($ligacoes[$pedido] ?? collect())->pluck('venda_id');
            $faturas = Venda::query()->where(fn ($q) => $q->where('pedido_lavandaria_id', $pedido)->orWhereIn('id', $ids))->whereIn('tipo_documento', ['FT', 'FR'])
                ->where('estado', '<>', 'ANULADO')->orderBy('id')->get(['id', 'tipo_documento', 'numero_documento', 'numero_documento_saft', 'saft_source_billing', 'fe_selado_em', 'data_emissao', 'total_bruto'])->values();
            $f = 0;
            $restoFatura = $faturas->isNotEmpty() ? number_format((float) $faturas[0]->total_bruto, 2, '.', '') : '0.00';
            foreach ($todos[$pedido] ?? [] as $p) {
                $porAlocar = number_format((float) $p->montante, 2, '.', '');
                $linhas = [];
                while (bccomp($porAlocar, '0', 2) > 0 && $f < $faturas->count()) {
                    $parte = bccomp($porAlocar, $restoFatura, 2) < 0 ? $porAlocar : $restoFatura;
                    if ($faturas[$f]->tipo_documento === 'FT' && bccomp($parte, '0', 2) > 0) {
                        $linhas[] = ['origem' => ServicoDocumentosImportadosSaft::numeroSaft($faturas[$f]), 'data' => $faturas[$f]->data_emissao?->toDateString(), 'credito' => $parte];
                    } elseif (bccomp($parte, '0', 2) > 0) {
                        $linhas[] = ['origem' => null, 'data' => null, 'credito' => $parte];   // já paga (FR): não se referencia
                    }
                    $porAlocar = bcsub($porAlocar, $parte, 2);
                    $restoFatura = bcsub($restoFatura, $parte, 2);
                    if (bccomp($restoFatura, '0', 2) <= 0) {
                        $f++;
                        $restoFatura = $f < $faturas->count() ? number_format((float) $faturas[$f]->total_bruto, 2, '.', '') : '0.00';
                    }
                }
                if (bccomp($porAlocar, '0', 2) > 0) {   // adiantamento: sem factura ainda
                    $linhas[] = ['origem' => null, 'data' => null, 'credito' => $porAlocar];
                }
                if (in_array($p->natureza_registo, ['ADIANTAMENTO', 'PAGAMENTO'], true)) {
                    $saida[$pedido][$p->id] = $this->fundir($linhas);
                }
            }
        }

        return $saida;
    }

    /**
     * Junta linhas consecutivas sem origem e da mesma origem.
     *
     * @param  list<array{origem: ?string, data: ?string, credito: string}>  $linhas
     * @return list<array{origem: ?string, data: ?string, credito: string}>
     */
    private function fundir(array $linhas): array
    {
        $r = [];
        foreach ($linhas as $l) {
            $k = $l['origem'] ?? '';
            if (isset($r[$k])) {
                $r[$k]['credito'] = bcadd($r[$k]['credito'], $l['credito'], 2);
            } else {
                $r[$k] = $l;
            }
        }

        return array_values($r);
    }
}
