<?php

namespace App\Services\Gestao\Paineis;

use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Consultas agregadas partilhadas pelos painéis, sempre na empresa activa e calculadas no PostgreSQL (uma linha por mês ou
 * por conta, nunca as linhas de detalhe). Regras (ui_painel_modulos.js:28-29, 102-111, 117-128):
 *
 * Diário
 *   - proveitos = classe 6 (C − D); custos = classe 7 (D − C); saldos = D − C até ao fim do mês;
 *   - correcção: os lançamentos de apuramento (períodos 13 e 14) não entram nos proveitos e custos — o legado somava-os e o
 *     resultado de um exercício encerrado passava a zero (o apuramento salda as classes 6 e 7 para a 8); é a mesma regra dos
 *     mapas (FiltroMapas) e da DR;
 *   - a classe 9 nunca entra (não é 6/7 nem 3/4);
 *   - filtros opcionais por unidade de negócio e centro de custo; na holding, `origem_holding` restringe às linhas de
 *     agregação de uma empresa (sem eliminações nem conversão — construirConsolidado, ui_painel_modulos.js:834-835).
 *
 * Documentos de venda e de compra
 *   - facturação = FT + FR − NC não anuladas (sinalFaturacao/valido, ui_painel_modulos.js:28-29);
 *   - compras = facturas de fornecedor não anuladas (correcção: o legado somava também as anuladas);
 *   - base `iva = sem` (omissão) usa os totais sem IVA (vendas.total_liquido; faturas_compra.montante_total − total_imposto) e
 *     `iva = com` os totais com IVA (vendas.total_bruto; faturas_compra.montante_total), que eram os do legado. O IVA não é
 *     proveito nem custo: sem IVA, a facturação é comparável com a classe 6 e com os Relatórios de gestão.
 */
final class ConsultasPaineis
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    public function empresa(): int
    {
        return $this->contexto->obrigatorio();
    }

    /**
     * Condições comuns para uma tabela com unidade_negocio_id / centro_custo_id.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function filtroDimensoes(string $alias, array $f): array
    {
        $sql = '';
        $p = [];
        foreach (['unidade_negocio_id', 'centro_custo_id'] as $c) {
            if (! empty($f[$c])) {
                $sql .= " AND {$alias}.{$c} = ?";
                $p[] = (int) $f[$c];
            }
        }

        return [$sql, $p];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filtroDiario(array $f): array
    {
        [$sql, $p] = self::filtroDimensoes('l', $f);
        if (! empty($f['origem_holding'])) {
            $sql .= " AND l.empresa_origem_id = ? AND COALESCE(l.tipo_consolidacao, '') NOT IN ('ELIMINACAO', 'CONVERSAO')";
            $p[] = (int) $f['origem_holding'];
        }

        return [$sql, $p];
    }

    /**
     * Proveitos e custos por mês num intervalo (sem apuramento).
     *
     * @return array{proveitos: array<string, string>, custos: array<string, string>}
     */
    public function resultadoPorMes(string $inicio, string $fim, array $f): array
    {
        [$fs, $fp] = $this->filtroDiario($f);
        $linhas = DB::select("SELECT to_char(l.data_documento, 'YYYY-MM') AS mes,
                SUM(CASE WHEN l.codigo_conta LIKE '6%' THEN CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE -l.valor END ELSE 0 END) AS proveitos,
                SUM(CASE WHEN l.codigo_conta LIKE '7%' THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) AS custos
            FROM lancamentos_contabeis l
            WHERE l.empresa_id = ? AND l.data_documento BETWEEN ? AND ? AND (l.codigo_conta LIKE '6%' OR l.codigo_conta LIKE '7%')
              AND NOT (COALESCE(l.periodo_id, 0) IN (13, 14) AND NOT EXISTS (SELECT 1 FROM diarios_contabeis d_sal WHERE d_sal.id = l.diario_id AND d_sal.codigo = 'SAL')){$fs}
            GROUP BY 1", array_merge([$this->empresa(), $inicio, $fim], $fp));
        $r = ['proveitos' => [], 'custos' => []];
        foreach ($linhas as $l) {
            $r['proveitos'][$l->mes] = Indicadores::dinheiro($l->proveitos);
            $r['custos'][$l->mes] = Indicadores::dinheiro($l->custos);
        }

        return $r;
    }

    /**
     * Saldos D − C até uma data, por grupos de prefixos de conta.
     *
     * @param  array<string, list<string>>  $grupos  id => prefixos
     * @return array<string, string>
     */
    public function saldos(string $ate, array $grupos, array $f): array
    {
        [$fs, $fp] = $this->filtroDiario($f);
        $colunas = [];
        $pc = [];
        $todos = [];
        foreach ($grupos as $id => $prefixos) {
            $cond = implode(' OR ', array_fill(0, count($prefixos), 'l.codigo_conta LIKE ?'));
            $colunas[] = "SUM(CASE WHEN {$cond} THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) AS \"{$id}\"";
            foreach ($prefixos as $px) {
                $pc[] = "{$px}%";
                $todos[$px] = true;
            }
        }
        $filtroContas = implode(' OR ', array_fill(0, count($todos), 'l.codigo_conta LIKE ?'));
        $r = DB::selectOne('SELECT '.implode(', ', $colunas)." FROM lancamentos_contabeis l
            WHERE l.empresa_id = ? AND l.data_documento <= ? AND ({$filtroContas}){$fs}",
            array_merge($pc, [$this->empresa(), $ate], array_map(fn ($x) => "{$x}%", array_keys($todos)), $fp));

        return array_map(fn ($id) => Indicadores::dinheiro($r->{$id} ?? '0'), array_combine(array_keys($grupos), array_keys($grupos)));
    }

    /**
     * Movimento das disponibilidades (43 e 45) por mês na janela e saldo antes da janela.
     *
     * @return array{entradas: array<string, string>, saidas: array<string, string>, saldo_inicial: string}
     */
    public function disponibilidadesPorMes(PeriodoPainel $p, array $f): array
    {
        [$fs, $fp] = $this->filtroDiario($f);
        $base = "FROM lancamentos_contabeis l WHERE l.empresa_id = ? AND (l.codigo_conta LIKE '43%' OR l.codigo_conta LIKE '45%'){$fs}";
        $linhas = DB::select("SELECT to_char(l.data_documento, 'YYYY-MM') AS mes,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS entradas, SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS saidas
            {$base} AND l.data_documento BETWEEN ? AND ? GROUP BY 1", array_merge([$this->empresa()], $fp, [$p->inicioJanela, $p->fimMes]));
        $inicial = DB::selectOne("SELECT SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS s {$base} AND l.data_documento < ?",
            array_merge([$this->empresa()], $fp, [$p->inicioJanela]))->s;
        $r = ['entradas' => [], 'saidas' => [], 'saldo_inicial' => Indicadores::dinheiro($inicial)];
        foreach ($linhas as $l) {
            $r['entradas'][$l->mes] = Indicadores::dinheiro($l->entradas);
            $r['saidas'][$l->mes] = Indicadores::dinheiro($l->saidas);
        }

        return $r;
    }

    /** Expressão do valor de uma venda na base pedida (com sinal: NC negativa). */
    public static function valorVenda(string $alias, array $f, bool $comSinal = true): string
    {
        $coluna = ($f['iva'] ?? 'sem') === 'com' ? "{$alias}.total_bruto" : "{$alias}.total_liquido";

        return $comSinal ? "(CASE WHEN {$alias}.tipo_documento = 'NC' THEN -1 ELSE 1 END * COALESCE({$coluna}, 0))" : "COALESCE({$coluna}, 0)";
    }

    /** Condição das vendas que contam como facturação (FT, FR e NC não anuladas). */
    public static function condicaoFacturacao(string $alias): string
    {
        return "{$alias}.tipo_documento IN ('FT', 'FR', 'NC') AND COALESCE({$alias}.estado, '') NOT IN ('ANULADO', 'CANCELADO')";
    }

    /** Expressão do valor de uma factura de fornecedor na base pedida. */
    public static function valorCompra(string $alias, array $f): string
    {
        return ($f['iva'] ?? 'sem') === 'com' ? "COALESCE({$alias}.montante_total, 0)" : "(COALESCE({$alias}.montante_total, 0) - COALESCE({$alias}.total_imposto, 0))";
    }

    /**
     * Facturação por mês entre duas datas: total líquido de NC, n.º e valor das facturas (FT/FR) e das NC.
     *
     * @return array<string, array{total: string, faturas: int, valor_faturas: string, notas_credito: string}>
     */
    public function vendasPorMes(string $inicio, string $fim, array $f): array
    {
        [$fs, $fp] = self::filtroDimensoes('v', $f);
        $valor = self::valorVenda('v', $f);
        $bruto = self::valorVenda('v', $f, false);
        $r = [];
        foreach (DB::select("SELECT to_char(v.data_emissao, 'YYYY-MM') AS mes, SUM({$valor}) AS total,
                COUNT(*) FILTER (WHERE v.tipo_documento <> 'NC') AS faturas, COALESCE(SUM({$bruto}) FILTER (WHERE v.tipo_documento <> 'NC'), 0) AS valor_faturas,
                COALESCE(SUM({$bruto}) FILTER (WHERE v.tipo_documento = 'NC'), 0) AS notas_credito
            FROM vendas v WHERE v.empresa_id = ? AND ".self::condicaoFacturacao('v')." AND v.data_emissao >= ? AND v.data_emissao < ?{$fs}
            GROUP BY 1", array_merge([$this->empresa(), $inicio, self::diaSeguinte($fim)], $fp)) as $l) {
            $r[$l->mes] = ['total' => Indicadores::dinheiro($l->total), 'faturas' => (int) $l->faturas, 'valor_faturas' => Indicadores::dinheiro($l->valor_faturas),
                'notas_credito' => Indicadores::dinheiro($l->notas_credito)];
        }

        return $r;
    }

    /** @return array<string, string> compras por mês (AAAA-MM => valor) */
    public function comprasPorMes(string $inicio, string $fim, array $f): array
    {
        [$fs, $fp] = self::filtroDimensoes('c', $f);
        $valor = self::valorCompra('c', $f);

        return collect(DB::select("SELECT to_char(c.data, 'YYYY-MM') AS mes, SUM({$valor}) AS total FROM faturas_compra c
            WHERE c.empresa_id = ? AND c.anulado_em IS NULL AND c.data BETWEEN ? AND ?{$fs} GROUP BY 1", array_merge([$this->empresa(), $inicio, $fim], $fp)))
            ->mapWithKeys(fn ($l) => [$l->mes => Indicadores::dinheiro($l->total)])->all();
    }

    /** Colaboradores activos (não "não activo" e não reformados — ui_painel_modulos.js:149). */
    public function colaboradoresActivos(array $f): int
    {
        [$fs, $fp] = self::filtroDimensoes('c', $f);

        return (int) DB::selectOne("SELECT COUNT(*) AS n FROM colaboradores c WHERE c.empresa_id = ? AND c.eliminado_em IS NULL
            AND COALESCE(c.estado, '') <> 'INACTIVO' AND NOT COALESCE(c.reformado, false){$fs}", array_merge([$this->empresa()], $fp))->n;
    }

    /** Somas mensais de uma série: chave => valor, com chaves da janela. */
    public static function somar(array $a, array $b, bool $subtrair = false): array
    {
        $r = $a;
        foreach ($b as $k => $v) {
            $r[$k] = $subtrair ? bcsub($r[$k] ?? '0.00', (string) $v, 2) : bcadd($r[$k] ?? '0.00', (string) $v, 2);
        }

        return $r;
    }

    public static function diaSeguinte(string $data): string
    {
        return date('Y-m-d', strtotime("{$data} +1 day"));
    }
}
