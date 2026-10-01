<?php

namespace App\Services\Gestao\Paineis;

use Illuminate\Support\Facades\DB;

/**
 * Activos fixos (CONSTRUTORES.ativos, ui_painel_modulos.js:520-558): activos em uso (estado ACTIVO), valor de aquisição,
 * amortização acumulada, valor líquido contabilístico, amortizações contabilizadas do ano (até ao mês), manutenções planeadas
 * e activos sem nenhuma quota; amortizações contabilizadas (12 meses), valor líquido por categoria (8) e principais activos.
 *
 * O valor líquido de cada activo é max(0, aquisição − acumulada), como no legado. Activos eliminados não contam. Filtros por
 * unidade de negócio e centro de custo pelos campos do activo.
 */
final class PainelAtivos implements Painel
{
    public function __construct(private readonly ConsultasPaineis $consultas) {}

    public function filtraDimensoes(): bool
    {
        return true;
    }

    public function construir(PeriodoPainel $p, array $f): array
    {
        $empresa = $this->consultas->empresa();
        [$fs, $fp] = ConsultasPaineis::filtroDimensoes('a', $f);
        $base = "FROM ativos_imobilizados a WHERE a.empresa_id = ? AND a.eliminado_em IS NULL{$fs}";
        $pb = array_merge([$empresa], $fp);
        $vlc = 'GREATEST(0, COALESCE(a.valor_aquisicao, 0) - COALESCE(a.amortizacao_acumulada, 0))';
        $tot = DB::selectOne("SELECT COUNT(*) FILTER (WHERE a.estado = 'ACTIVO') AS activos, COUNT(*) FILTER (WHERE a.estado <> 'ACTIVO') AS outros,
                COALESCE(SUM(a.valor_aquisicao) FILTER (WHERE a.estado = 'ACTIVO'), 0) AS aquisicao, COALESCE(SUM(a.amortizacao_acumulada) FILTER (WHERE a.estado = 'ACTIVO'), 0) AS acumulada,
                COUNT(*) FILTER (WHERE a.estado = 'ACTIVO' AND NOT EXISTS (SELECT 1 FROM amortizacoes_ativos q WHERE q.ativo_imobilizado_id = a.id)) AS sem_amortizacao
            {$base}", $pb);
        $amort = DB::select("SELECT to_char(q.data, 'YYYY-MM') AS mes, SUM(q.valor) AS total FROM amortizacoes_ativos q JOIN ativos_imobilizados a ON a.id = q.ativo_imobilizado_id
            WHERE q.empresa_id = ? AND q.contabilizado AND q.data BETWEEN ? AND ?{$fs} GROUP BY 1", array_merge([$empresa, $p->inicioJanela, $p->fimMes], $fp));
        $porMes = array_column(array_map(fn ($r) => [$r->mes, $r->total], $amort), 1, 0);
        $manut = (int) DB::selectOne("SELECT COUNT(*) AS n FROM registos_manutencao_ativos m JOIN ativos_imobilizados a ON a.id = m.ativo_imobilizado_id
            WHERE m.empresa_id = ? AND COALESCE(m.estado, 'PLANEADA') = 'PLANEADA'{$fs}", array_merge([$empresa], $fp))->n;
        $porCat = DB::select("SELECT COALESCE(MAX(c.nome), 'Sem categoria') AS categoria, SUM({$vlc}) AS liquido FROM ativos_imobilizados a LEFT JOIN categorias_ativos c ON c.id = a.categoria_ativo_id
            WHERE a.empresa_id = ? AND a.eliminado_em IS NULL AND a.estado = 'ACTIVO'{$fs} GROUP BY a.categoria_ativo_id ORDER BY liquido DESC LIMIT 8", $pb);
        $top = DB::select("SELECT a.id, a.codigo, a.descricao, a.valor_aquisicao, a.amortizacao_acumulada, {$vlc} AS liquido {$base} AND a.estado = 'ACTIVO' ORDER BY liquido DESC, a.codigo LIMIT 8", $pb);
        $aquisicao = Indicadores::dinheiro($tot->aquisicao);
        $acumulada = Indicadores::dinheiro($tot->acumulada);

        return [
            'kpis' => [
                Indicadores::kpi('ativos_em_uso', 'Activos em Uso', (int) $tot->activos, 'num', ((int) $tot->outros).' abatidos/inactivos'),
                Indicadores::kpi('valor_aquisicao', 'Valor de Aquisição', $aquisicao),
                Indicadores::kpi('amortizacao_acumulada', 'Amortização Acumulada', $acumulada),
                Indicadores::kpi('valor_liquido', 'Valor Líquido Contabilístico', bcsub($aquisicao, $acumulada, 2)),
                Indicadores::kpi('amortizacoes_ano', "Amortizações {$p->ano}", $p->somaAno($porMes), 'kz', 'Contabilizadas'),
                Indicadores::kpi('manutencoes_planeadas', 'Manutenções Planeadas', $manut, 'num'),
                Indicadores::kpi('sem_amortizacao', 'Activos sem Amortização', (int) $tot->sem_amortizacao, 'num'),
            ],
            'graficos' => [
                Indicadores::grafico('amortizacoes', 'Amortizações Contabilizadas (12 meses)', 'barras', $p->rotulos(), [Indicadores::serie('amortizacoes', 'Amortizações', $p->serie($porMes))]),
                Indicadores::grafico('liquido_categoria', 'Valor Líquido por Categoria', 'circular', array_map(fn ($r) => $r->categoria, $porCat),
                    [Indicadores::serie('liquido', 'VLC', array_map(fn ($r) => Indicadores::dinheiro($r->liquido), $porCat))]),
            ],
            'tabelas' => [
                Indicadores::tabela('principais_ativos', 'Principais Activos (Valor Líquido)', [['codigo', 'Código'], ['descricao', 'Descrição'], ['valor_aquisicao', 'Aquisição', 'kz'],
                    ['amortizacao_acumulada', 'Amort. Acumulada', 'kz'], ['liquido', 'Valor Líquido', 'kz']],
                    array_map(fn ($r) => ['id' => $r->id, 'codigo' => $r->codigo, 'descricao' => $r->descricao, 'valor_aquisicao' => Indicadores::dinheiro($r->valor_aquisicao),
                        'amortizacao_acumulada' => Indicadores::dinheiro($r->amortizacao_acumulada), 'liquido' => Indicadores::dinheiro($r->liquido)], $top)),
            ],
            'atalhos' => [['rotulo' => 'Listagem de Activos', 'vista' => 'activos'], ['rotulo' => 'Processar Amortizações', 'vista' => 'activos_amortizacoes'], ['rotulo' => 'Manutenções', 'vista' => 'activos_manutencao']],
        ];
    }
}
