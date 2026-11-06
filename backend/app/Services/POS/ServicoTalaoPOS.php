<?php

namespace App\Services\POS;

use App\Models\MeioPagamento;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Vendas\ServicoQrAgt;

/**
 * Elementos do talão do POS que não vão no JSON da venda (printPOSThermalReceipt, js/ui_sales.js:8000-8166): QR de consulta
 * da AGT (o mesmo URL que o legado codificava e que o servidor já gera nas facturas — ServicoQrAgt), série electrónica,
 * cliente (nome e NIF, «999999999» por omissão) e coordenadas bancárias (meios de pagamento activos com IBAN, como o
 * legado). O QR só existe para documentos selados no regime de facturação electrónica: sem regime não há QR nem série.
 * O operador de caixa que regista a venda já vem em `pos_operador` (o legado imprimia «CAIXA POS», BUG-01).
 */
final class ServicoTalaoPOS
{
    public const NIF_CONSUMIDOR_FINAL = '999999999';

    public function __construct(private readonly ServicoQrAgt $qr) {}

    /**
     * @return array{venda_id: int, numero_documento: string, fe_regime: bool, serie: ?string, qr_svg: ?string, url_consulta: ?string,
     *               fe_estado: ?string, fe_erros: list<mixed>, cliente_nome: string, cliente_nif: string, operador: ?string,
     *               coordenadas_bancarias: list<array{nome: string, iban: string, swift: ?string}>}
     */
    public function dados(Venda $v): array
    {
        $regime = (bool) $v->fe_regime;
        $url = $svg = null;
        if ($regime) {
            try {
                $url = $this->qr->urlDoDocumento($v);
                $svg = $this->qr->imagem($v, 'svg')['conteudo'];
            } catch (\Throwable) {
                $url = $svg = null;   // o talão imprime-se na mesma, sem QR
            }
        }
        $cliente = $v->cliente_id ? Terceiro::query()->find($v->cliente_id, ['id', 'nome', 'nif']) : null;
        $nif = trim((string) ($cliente?->nif ?? ''));

        return [
            'venda_id' => $v->id,
            'numero_documento' => (string) $v->numero_documento,
            'fe_regime' => $regime,
            'serie' => $regime && $v->fe_serie ? trim(($v->fe_tipo ?: 'FR').' '.$v->fe_serie) : null,
            'qr_svg' => $svg,
            'url_consulta' => $url,
            'fe_estado' => $v->fe_estado,
            'fe_erros' => array_values((array) ($v->fe_erros ?? [])),
            'cliente_nome' => (string) ($cliente?->nome ?? 'Consumidor Final'),
            'cliente_nif' => $nif !== '' ? $nif : self::NIF_CONSUMIDOR_FINAL,
            'operador' => $v->pos_operador,
            'coordenadas_bancarias' => $this->coordenadas(),
        ];
    }

    /** @return list<array{nome: string, iban: string, swift: ?string}> */
    public function coordenadas(): array
    {
        return MeioPagamento::query()->where('ativo', true)->whereNotNull('iban')->where('iban', '<>', '')->orderBy('id')->get(['nome', 'iban', 'swift'])
            ->map(fn (MeioPagamento $m) => ['nome' => (string) $m->nome, 'iban' => (string) $m->iban, 'swift' => $m->swift ?: null])->all();
    }
}
