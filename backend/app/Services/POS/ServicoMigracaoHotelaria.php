<?php

namespace App\Services\POS;

use Illuminate\Support\Facades\DB;

/**
 * Pós-carga da hotelaria (ETL): os JSON das estadias do legado (hotel_stays.items e .history, js/hotelaria.js:110-113, 374)
 * vêm com chaves em inglês; passam às chaves portuguesas, a única forma lida pelos serviços da hotelaria
 * (App\Services\POS\Hotelaria). Os ids dos produtos dos consumos mantêm-se (o ETL preserva os ids do legado).
 *   - consumo: product_id → produto_id, name → descricao, price → preco_unitario (com IVA), quantity → quantidade, tax_rate → taxa_imposto;
 *   - histórico: at → em, by → por, text → texto.
 * Idempotente: as chaves já traduzidas ou desconhecidas ficam como estão.
 */
final class ServicoMigracaoHotelaria
{
    private const CONSUMO = ['product_id' => 'produto_id', 'name' => 'descricao', 'price' => 'preco_unitario', 'quantity' => 'quantidade', 'tax_rate' => 'taxa_imposto'];

    private const HISTORICO = ['at' => 'em', 'by' => 'por', 'text' => 'texto'];

    /** @return array{estadias: int} */
    public function normalizar(): array
    {
        $n = 0;
        foreach (DB::table('estadias_hotel')->get(['id', 'itens', 'historico_alteracoes']) as $e) {
            $lista = fn (?string $j, array $mapa) => $j === null ? null : json_encode(array_map(fn ($x) => self::traduzir($x, $mapa), json_decode($j, true) ?: []));
            DB::table('estadias_hotel')->where('id', $e->id)->update([
                'itens' => $lista($e->itens, self::CONSUMO) ?? '[]', 'historico_alteracoes' => $lista($e->historico_alteracoes, self::HISTORICO),
            ]);
            $n++;
        }

        return ['estadias' => $n];
    }

    private static function traduzir(mixed $x, array $mapa): mixed
    {
        if (! is_array($x)) {
            return $x;
        }
        $saida = [];
        foreach ($x as $k => $v) {
            $saida[$mapa[$k] ?? $k] = $v;
        }

        return $saida;
    }
}
