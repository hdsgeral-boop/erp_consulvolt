<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Services\Gestao\Relatorios\PeriodosGestao;
use Illuminate\Support\Facades\DB;

/**
 * Stock (relatorios_gestao.js:324-367): movimentos, rotação e cobertura do inventário.
 * Regras do legado: artigos de stock = produtos que movimentam stock; valor do inventário = Σ max(0, quantidade) × custo médio
 * (situação actual, igual nos dois períodos); movimentos de ENTRADA e SAÍDA do período valorizados a |quantidade| × custo
 * médio actual (os ajustes não contam); rotação anualizada = saídas ÷ inventário × 365 ÷ dias; cobertura = inventário ÷
 * consumo médio diário; artigos sem movimento = com stock e sem movimentos no período.
 * A quantidade é o total do produto, que é a soma dos armazéns (ADR-042).
 * Correcção: sem custo médio, o artigo vale 0; o legado caía no preço de venda (unit_price), valorizando o inventário a
 * preços de venda.
 */
final class ModuloStock extends ModuloGestao
{
    public function id(): string
    {
        return 'stock';
    }

    public function nome(): string
    {
        return 'Stock';
    }

    public function descricao(): string
    {
        return 'Movimentos, rotação e cobertura do inventário.';
    }

    public function calcular(array $p): array
    {
        $e = $this->empresa();
        $prod = DB::table('produtos')->where('empresa_id', $e)->whereNull('eliminado_em')->where('movimenta_stock', true)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(GREATEST(COALESCE(quantidade_stock, 0), 0) * COALESCE(custo_medio, 0)), 0) AS valor,
                COUNT(*) FILTER (WHERE COALESCE(quantidade_stock, 0) < 0) AS negativos')->first();
        $mov = fn () => DB::table('movimentos_inventario as m')->join('produtos as pr', 'pr.id', '=', 'm.produto_id')
            ->where('m.empresa_id', $e)->whereNull('pr.eliminado_em')->where('pr.movimenta_stock', true)->whereRaw('m.data::date BETWEEN ? AND ?', [$p['inicio'], $p['fim']]);
        $t = $mov()->selectRaw("COALESCE(SUM(CASE WHEN m.tipo = 'ENTRADA' THEN ABS(m.quantidade) * COALESCE(pr.custo_medio, 0) ELSE 0 END), 0) AS ent,
            COALESCE(SUM(CASE WHEN m.tipo = 'SAIDA' THEN ABS(m.quantidade) * COALESCE(pr.custo_medio, 0) ELSE 0 END), 0) AS sai")->first();
        $semMov = DB::table('produtos as pr')->where('pr.empresa_id', $e)->whereNull('pr.eliminado_em')->where('pr.movimenta_stock', true)->where('pr.quantidade_stock', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('movimentos_inventario as m')->whereColumn('m.produto_id', 'pr.id')->whereRaw('m.data::date BETWEEN ? AND ?', [$p['inicio'], $p['fim']]))
            ->count();
        $top = $mov()->where('m.tipo', 'SAIDA')->groupBy('m.produto_id')
            ->selectRaw('MAX(pr.nome) AS nome, SUM(ABS(m.quantidade)) AS qtd, SUM(ABS(m.quantidade) * COALESCE(pr.custo_medio, 0)) AS valor')
            ->orderByDesc('valor')->limit(10)->get()->map(fn ($r) => ['nome' => $r->nome, 'qtd' => round((float) $r->qtd, 3), 'valor' => self::dinheiro($r->valor)])->all();
        $valor = self::dinheiro($prod->valor);
        $saidas = self::dinheiro($t->sai);
        $dias = PeriodosGestao::dias($p);

        return [
            'kpis' => [
                self::k('valor', 'Valor do inventário (actual)', $valor, 'kz', 'neutro', 'Quantidade em armazém × custo médio ponderado.', ['actual' => true]),
                self::k('artigos', 'Artigos de stock', (int) $prod->n, 'num', 'neutro', '', ['actual' => true]),
                self::k('entradas', 'Entradas (ao custo)', $t->ent, 'kz', 'neutro', 'Movimentos de ENTRADA × custo médio.'),
                self::k('saidas', 'Saídas / consumos (ao custo)', $saidas, 'kz', 'neutro', 'Movimentos de SAÍDA × custo médio.'),
                self::k('rotacao', 'Rotação (anualizada)', ($d = self::div($saidas, $valor)) === null ? null : $d * (365 / $dias), 'num', 'sobe',
                    'Custo das saídas ÷ valor do inventário, anualizado. Mais alto = stock gira mais depressa.', ['casas' => 1]),
                self::k('cobertura', 'Cobertura de stock', self::div($valor, (float) $saidas / $dias), 'dias', 'desce', 'Valor do inventário ÷ consumo médio diário.'),
                self::k('sem_mov', 'Artigos sem movimento', $semMov, 'num', 'desce', 'Artigos com stock e sem movimentos no período (possível obsolescência).'),
                self::k('negativos', 'Artigos com stock negativo', (int) $prod->negativos, 'num', 'desce', 'Indica saídas sem entrada registada.', ['actual' => true]),
            ],
            'tabelas' => [self::tabela('top_consumo', 'Top 10 artigos por consumo', 'nome', [['nome', 'Artigo'], ['qtd', 'Quantidade saída', 'num'], ['valor', 'Valor ao custo', 'kz']], $top)],
            'graficos' => [],
            'notas' => ['O valor do inventário é a situação actual (a base de dados não guarda o histórico de saldos): é igual nos dois períodos.'],
        ];
    }
}
