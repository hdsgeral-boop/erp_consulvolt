<?php

namespace App\Services\Imobiliario;

use App\Models\ContratoArrendamento;
use App\Models\DocumentoTesouraria;
use App\Models\RendaArrendamento;
use App\Models\Venda;
use Illuminate\Support\Collection;

/**
 * Conta corrente do contrato e mora (contaCorrente do legado, CT:988-1038). Lado senhorio: o recebido é o que a factura da renda já
 * recebeu em Vendas (`valor_pago`); lado inquilino: o pago é o documento de tesouraria integrado. A dívida é o que falta do total a
 * receber (já depois da retenção do Imposto Predial) e os juros de mora são os de CalculadoraMora, à taxa do contrato ou do ano do
 * vencimento. Correcções (P3): o pago do inquilino só conta o documento integrado (o legado contava o pendente e a «dívida» era
 * sempre 0) e os juros só se calculam do lado em que a empresa cobra. Tudo em lote: nunca uma consulta por renda.
 */
final class ServicoContaCorrente
{
    /** @var array<int, string> taxa de mora por ano */
    private array $moraDoAno = [];

    public function __construct(private readonly ServicoTaxasImobiliario $taxas) {}

    private static function naoNegativo(string $v): string
    {
        return bccomp($v, '0', 2) < 0 ? '0.00' : $v;
    }

    private function taxaDeMora(int $ano): string
    {
        return $this->moraDoAno[$ano] ??= number_format((float) ($this->taxas->doAno($ano)['taxas']['mora_mensal'] ?? 0), 4, '.', '');
    }

    /**
     * Pago, dívida, atraso e juros de cada renda (por id da renda).
     *
     * @param  Collection<int, RendaArrendamento>  $rendas
     * @param  Collection<int, ContratoArrendamento>  $contratos  indexados por id
     * @return array<int, array<string, mixed>>
     */
    public function linhas(Collection $rendas, Collection $contratos, ?string $hoje = null): array
    {
        $hoje ??= now()->toDateString();
        $vendas = Venda::query()->whereIn('id', $rendas->pluck('venda_id')->filter()->unique())->get(['id', 'numero_documento', 'valor_pago', 'estado'])->keyBy('id');
        $docs = DocumentoTesouraria::query()->whereIn('id', $rendas->pluck('documento_tesouraria_id')->filter()->unique())->get(['id', 'numero_documento', 'estado', 'valor_total'])->keyBy('id');
        $saida = [];
        foreach ($rendas as $r) {
            $esperado = number_format((float) $r->total_receber, 2, '.', '');
            $pago = '0.00';
            $documento = null;
            $docEstado = null;
            $divida = '0.00';
            if ($r->posicao === 'SENHORIO') {
                $v = $r->venda_id ? ($vendas[$r->venda_id] ?? null) : null;
                $documento = $v?->numero_documento ?? $r->numero_documento;
                $pago = $v ? number_format((float) $v->valor_pago, 2, '.', '') : '0.00';
                $divida = $r->estado === 'FACTURADA' ? self::naoNegativo(bcsub($esperado, $pago, 2)) : '0.00';
            } else {
                $d = $r->documento_tesouraria_id ? ($docs[$r->documento_tesouraria_id] ?? null) : null;
                $docEstado = $d?->estado;
                $documento = $d ? ($d->numero_documento ?: 'Tesouraria #'.$d->id) : null;
                $pago = $d && $d->estado === 'INTEGRADO' ? number_format((float) $d->valor_total, 2, '.', '') : '0.00';
                $divida = $r->estado === 'PAGA' && $d && $d->estado !== 'ANULADO' ? self::naoNegativo(bcsub($esperado, number_format((float) $d->valor_total, 2, '.', ''), 2)) : '0.00';
            }
            $emDocumento = $r->emDocumento();
            $atraso = $emDocumento ? CalculadoraMora::diasDeAtraso($r->vencimento->format('Y-m-d'), $hoje, $divida) : 0;
            $juros = '0.00';
            if ($r->posicao === 'SENHORIO' && $atraso > 0) {
                $c = $contratos[$r->contrato_id] ?? null;
                $taxa = $c?->mora_mensal !== null ? number_format((float) $c->mora_mensal, 4, '.', '') : $this->taxaDeMora((int) $r->vencimento->format('Y'));
                $juros = CalculadoraMora::juros($divida, $taxa, $atraso);
            }
            $saida[$r->id] = ['pago' => (float) $pago, 'divida' => (float) $divida, 'atraso' => $atraso, 'juros' => (float) $juros, 'documento' => $documento, 'documento_estado' => $docEstado];
        }

        return $saida;
    }

    /**
     * Conta corrente de um contrato: uma linha por renda não anulada e os totais.
     *
     * @return array{linhas: list<array<string, mixed>>, totais: array<string, float>}
     */
    public function doContrato(ContratoArrendamento $c, ?string $hoje = null): array
    {
        $rendas = RendaArrendamento::query()->where('contrato_id', $c->id)->where('estado', '<>', 'ANULADA')->orderBy('periodo')->get();
        $cc = $this->linhas($rendas, collect([$c->id => $c]), $hoje);
        $linhas = [];
        $t = ['facturado' => '0.00', 'esperado' => '0.00', 'recebido' => '0.00', 'em_divida' => '0.00', 'juros' => '0.00', 'ip_retido' => '0.00', 'selo_senhorio' => '0.00'];
        foreach ($rendas as $r) {
            $l = $cc[$r->id];
            $linhas[] = $r->attributesToArray() + $l;
            $f = fn (float|int|string $v) => number_format((float) $v, 2, '.', '');
            if ($r->emDocumento()) {
                $t['facturado'] = bcadd($t['facturado'], $f($r->total_facturar), 2);
                $t['esperado'] = bcadd($t['esperado'], $f($r->total_receber), 2);
                $t['em_divida'] = bcadd($t['em_divida'], $f($l['divida']), 2);
                if ($r->ip_retido) {
                    $t['ip_retido'] = bcadd($t['ip_retido'], $f($r->ip_valor), 2);
                }
            }
            $t['recebido'] = bcadd($t['recebido'], $f($l['pago']), 2);
            $t['juros'] = bcadd($t['juros'], $f($l['juros']), 2);
            if ($r->selo_encargo === 'SENHORIO') {
                $t['selo_senhorio'] = bcadd($t['selo_senhorio'], $f($r->selo_valor), 2);
            }
        }
        $emPoder = bcsub(bcsub(number_format((float) $c->caucao_recebida, 2, '.', ''), number_format((float) $c->caucao_devolvida, 2, '.', ''), 2), number_format((float) $c->caucao_retida, 2, '.', ''), 2);

        return ['linhas' => $linhas, 'totais' => array_map('floatval', $t) + ['caucao_em_poder' => (float) $emPoder]];
    }
}
