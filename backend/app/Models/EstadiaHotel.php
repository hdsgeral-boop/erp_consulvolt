<?php

namespace App\Models;

use App\Models\Base\EstadiaHotelBase;
use App\Services\Vendas\CalculadoraDocumento;

/**
 * estadias_hotel — /api/pos/hotelaria/estadias (ADR-050).
 * Consumos (itens): [{produto_id, descricao, preco_unitario (com IVA), quantidade, taxa_imposto}];
 * histórico: [{em, por, texto}] — chaves do legado traduzidas por ServicoMigracaoHotelaria.
 * A estrutura está em EstadiaHotelBase (gerado).
 */
class EstadiaHotel extends EstadiaHotelBase
{
    public const ABERTA = 'ABERTA';

    public const FECHADA = 'FECHADA';

    public const ANULADA = 'ANULADA';

    public const MODOS = ['DIA', 'HORA'];

    /** Alojamento (quantidade × preço com IVA), como o painel do legado (js/hotelaria.js:390). */
    public function totalAlojamento(?string $quantidade = null): string
    {
        return CalculadoraDocumento::arredondar(bcmul($quantidade ?? (string) $this->quantidade, (string) $this->preco_unitario, 8));
    }

    /** Soma dos consumos com IVA (totalConsumos, js/hotelaria.js:31). */
    public function totalConsumos(): string
    {
        return array_reduce($this->itens ?? [], fn ($t, $i) => bcadd($t, CalculadoraDocumento::arredondar(bcmul((string) ($i['quantidade'] ?? 0),
            (string) ($i['preco_unitario'] ?? 0), 8)), 2), '0.00');
    }

    /** Acrescenta uma entrada ao histórico (não grava). */
    public function registar(string $texto, ?string $por): void
    {
        $this->historico_alteracoes = [...($this->historico_alteracoes ?? []), ['em' => now()->toIso8601String(), 'por' => $por, 'texto' => $texto]];
    }
}
