<?php

namespace App\Services\Gestao\Paineis;

use App\Models\Utilizador;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores do POS no painel de Vendas (F6, Q18; legado: sem KPIs POS): vendas do dia (líquidas de notas de crédito sobre
 * facturas-recibo POS), sessões abertas, desvios por deliberar, sessões por integrar e por prestar, e as vendas de hoje por meio de
 * pagamento. Cada grupo só aparece a quem pode ver o ecrã correspondente (o painel já exige uma das vistas de Vendas/POS).
 */
final class IndicadoresPOS
{
    public function __construct(private readonly ServicoPermissoes $permissoes, private readonly ContextoEmpresa $contexto) {}

    /** @return array{kpis: list<array<string, mixed>>, tabelas: list<array<string, mixed>>} */
    public function construir(): array
    {
        $kpis = $tabelas = [];
        $empresa = $this->contexto->obrigatorio();
        $hoje = now()->toDateString();
        $n = fn (string $sql, array $p = []) => (int) DB::selectOne($sql, array_merge([$empresa], $p))->n;

        if ($this->pode(['pos', 'pos_view', 'pos_relatorios_view'])) {
            $vendas = DB::selectOne("SELECT COALESCE(SUM(total_bruto), 0) AS t, COUNT(*) AS n FROM vendas WHERE empresa_id = ? AND sessao_pos_id IS NOT NULL
                AND tipo_documento = 'FR' AND COALESCE(estado, '') <> 'ANULADO' AND data_emissao >= ? AND data_emissao < ?::date + 1", [$empresa, $hoje, $hoje]);
            $nc = DB::selectOne('SELECT COALESCE(SUM(valor), 0) AS t FROM ncs_sessao_pos WHERE empresa_id = ? AND data = ?', [$empresa, $hoje])->t;
            $kpis[] = Indicadores::kpi('pos_vendas_hoje', 'POS — Vendas de Hoje', bcsub(Indicadores::dinheiro($vendas->t), Indicadores::dinheiro($nc), 2), 'kz', "{$vendas->n} venda(s), líquidas de devoluções");
            $kpis[] = Indicadores::kpi('pos_sessoes_abertas', 'POS — Sessões Abertas', $n("SELECT COUNT(*) AS n FROM sessoes_pos WHERE empresa_id = ? AND estado = 'ABERTA'"), 'num');
            $porMeio = DB::select("SELECT COALESCE(p->>'nome', p->>'tipo') AS meio, COUNT(*) AS operacoes, SUM((p->>'valor')::numeric) AS total
                FROM vendas v CROSS JOIN LATERAL jsonb_array_elements(COALESCE(v.pos_pagamentos, '[]'::jsonb)) p
                WHERE v.empresa_id = ? AND v.sessao_pos_id IS NOT NULL AND v.tipo_documento = 'FR' AND COALESCE(v.estado, '') <> 'ANULADO' AND v.data_emissao >= ? AND v.data_emissao < ?::date + 1
                GROUP BY 1 ORDER BY total DESC", [$empresa, $hoje, $hoje]);
            if ($porMeio) {
                $tabelas[] = Indicadores::tabela('pos_por_meio', 'POS — Vendas de Hoje por Meio de Pagamento', [['meio', 'Meio'], ['operacoes', 'Operações', 'num'], ['total', 'Valor', 'kz']],
                    array_map(fn ($r) => ['meio' => $r->meio, 'operacoes' => (int) $r->operacoes, 'total' => Indicadores::dinheiro($r->total)], $porMeio));
            }
        }
        if ($this->pode(['pos_desvios', 'pos_view'])) {
            $kpis[] = Indicadores::kpi('pos_desvios', 'POS — Desvios por Deliberar', $n("SELECT COUNT(*) AS n FROM sessoes_pos WHERE empresa_id = ? AND estado_desvio = 'PENDENTE'"), 'num', 'Diferenças de caixa acima da tolerância', null, []);
        }
        if ($this->pode(['pos_integracao'])) {
            $kpis[] = Indicadores::kpi('pos_por_integrar', 'POS — Sessões por Integrar', $n("SELECT COUNT(*) AS n FROM sessoes_pos WHERE empresa_id = ? AND estado = 'FECHADA' AND estado_contabilizacao = 'PENDENTE'"), 'num', 'Fechadas e ainda não contabilizadas');
        }
        if ($this->pode(['pos_prestacao'])) {
            $kpis[] = Indicadores::kpi('pos_por_prestar', 'POS — Sessões por Liquidar', $n("SELECT COUNT(*) AS n FROM sessoes_pos WHERE empresa_id = ? AND estado_contabilizacao = 'CONTABILIZADA' AND estado_liquidacao IN ('PENDENTE', 'PARCIAL')"), 'num', 'Prestação de contas em falta');
        }

        return ['kpis' => $kpis, 'tabelas' => $tabelas];
    }

    /** @param list<string> $vistas */
    private function pode(array $vistas): bool
    {
        /** @var Utilizador|null $u */
        $u = Auth::user();
        if (! $u) {
            return false;
        }
        foreach ($vistas as $v) {
            if ($this->permissoes->podeVer($u, $v, $this->contexto->id())) {
                return true;
            }
        }

        return false;
    }
}
