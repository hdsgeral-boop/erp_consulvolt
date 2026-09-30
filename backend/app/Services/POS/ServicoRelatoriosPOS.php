<?php

namespace App\Services\POS;

use App\Support\Tenancy\ContextoEmpresa;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Relatórios do POS (separador Relatórios, js/pos_gestao.js:1093-1133) e bloco POS do relatório de gestão «POS e Serviços»
 * (modules/gestao/relatorios_gestao.js:531-560). Face ao legado:
 *   - filtros por período (data de emissão da venda / data de fecho do Z), terminal e operador da sessão — o legado não
 *     tinha filtros e somava tudo;
 *   - vendas por produto, por terminal e por operador (novos); por operador usa quem registou a venda (pos_operador),
 *     que no legado era sempre quem abriu a sessão;
 *   - diferenças talão TPA × sistema listadas (o legado só avisava no fecho);
 *   - indicadores de estado (sessões abertas, desvios por deliberar, sessões por integrar / por prestar contas) são do
 *     momento, sem filtro de período, como no legado; "sessões abertas" passa a vir da base (o legado lia uma cache local).
 * Só contam facturas-recibo POS não anuladas.
 */
final class ServicoRelatoriosPOS
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * @param  array{data_inicio?: ?string, data_fim?: ?string, terminal_pos_id?: ?int, operador_id?: ?int}  $f
     * @return array<string, mixed>
     */
    public function painel(array $f): array
    {
        $vendas = $this->vendas($f);
        $tot = (clone $vendas)->selectRaw('COUNT(*) AS n, COALESCE(SUM(v.total_bruto), 0) AS total')->first();
        $total = number_format((float) $tot->total, 2, '.', '');
        $n = (int) $tot->n;
        $fechadas = $this->sessoes($f)->where('s.estado', 'FECHADA')
            ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('s.fechado_em', '>=', $v))
            ->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('s.fechado_em', '<', self::diaSeguinte($v)));
        $desvios = (clone $fechadas)->selectRaw('COALESCE(SUM(s.desvio), 0) AS d')->value('d');
        $estado = $this->sessoes($f)->selectRaw("
            COUNT(*) FILTER (WHERE s.estado = 'ABERTA') AS abertas,
            COUNT(*) FILTER (WHERE s.estado = 'FECHADA' AND s.estado_desvio = 'PENDENTE') AS desvios_por_deliberar,
            COUNT(*) FILTER (WHERE s.estado = 'FECHADA' AND s.estado_contabilizacao = 'PENDENTE') AS por_integrar,
            COUNT(*) FILTER (WHERE s.estado = 'FECHADA' AND s.estado_liquidacao IN ('PENDENTE', 'PARCIAL')) AS por_prestar")->first();

        $zs = (clone $fechadas)->orderByDesc('s.fechado_em')->orderByDesc('s.id')->limit(200)->get(['s.id', 's.numero_z', 's.codigo_sessao', 's.terminal_pos_id',
            's.codigo_terminal', 's.nome_terminal', 's.operador_id', 's.nome_operador', 's.aberto_em', 's.fechado_em', 's.numero_vendas', 's.total_vendas',
            's.numerario_esperado', 's.numerario_contado', 's.desvio', 's.estado_desvio', 's.estado_contabilizacao', 's.estado_liquidacao', 's.fechos_tpa']);
        $diferencasTpa = [];
        foreach ($zs as $z) {
            foreach (json_decode((string) $z->fechos_tpa, true) ?: [] as $t) {
                $dif = number_format((float) ($t['diferenca'] ?? 0), 2, '.', '');
                if (bccomp($dif, '0', 2) !== 0) {
                    $diferencasTpa[] = ['sessao_pos_id' => $z->id, 'numero_z' => $z->numero_z, 'meio_id' => $t['meio_id'] ?? null, 'nome' => $t['nome'] ?? null,
                        'codigo_tpa' => $t['codigo_tpa'] ?? null, 'valor_sistema' => $t['valor_sistema'] ?? null, 'valor_talao' => $t['valor_talao'] ?? null, 'diferenca' => $dif,
                        'referencia_lote' => $t['referencia_lote'] ?? null];
                }
            }
            unset($z->fechos_tpa);
        }

        return [
            'filtros' => array_filter($f, fn ($v) => $v !== null),
            'kpis' => [
                'facturacao' => $total, 'documentos' => $n, 'ticket_medio' => $n ? bcdiv($total, (string) $n, 2) : '0.00',
                'desvios' => number_format((float) $desvios, 2, '.', ''), 'sessoes_abertas' => (int) $estado->abertas,
                'desvios_por_deliberar' => (int) $estado->desvios_por_deliberar, 'sessoes_por_integrar' => (int) $estado->por_integrar,
                'sessoes_por_prestar' => (int) $estado->por_prestar, 'diferencas_tpa' => count($diferencasTpa),
            ],
            'por_meio' => $this->porMeio($f),
            'zs' => $zs->all(),
            'diferencas_tpa' => $diferencasTpa,
            'por_produto' => $this->porProduto($f),
            'por_terminal' => (clone $vendas)->groupBy('s.terminal_pos_id', 's.codigo_terminal', 's.nome_terminal')
                ->selectRaw('s.terminal_pos_id, s.codigo_terminal, s.nome_terminal, COUNT(*) AS documentos, SUM(v.total_bruto) AS total')
                ->orderByDesc('total')->get()->map(fn ($r) => (array) $r + ['total' => number_format((float) $r->total, 2, '.', '')])->all(),
            'por_operador' => (clone $vendas)->groupBy(DB::raw('COALESCE(v.pos_operador, s.nome_operador)'))
                ->selectRaw('COALESCE(v.pos_operador, s.nome_operador) AS operador, COUNT(*) AS documentos, SUM(v.total_bruto) AS total')
                ->orderByDesc('total')->get()->map(fn ($r) => (array) $r + ['total' => number_format((float) $r->total, 2, '.', '')])->all(),
        ];
    }

    /**
     * Bloco POS do relatório de gestão «POS e Serviços» (relatorios_gestao.js:531-560): sessões abertas no período, vendas
     * POS emitidas no período. Os indicadores de lavandaria e hotelaria ficam a null até esses módulos os fornecerem.
     *
     * @return array{kpis: list<array<string, mixed>>}
     */
    public function indicadoresServicos(string $inicio, string $fim): array
    {
        $vendas = $this->vendas(['data_inicio' => $inicio, 'data_fim' => $fim])->selectRaw('COUNT(*) AS n, COALESCE(SUM(v.total_bruto), 0) AS total')->first();
        $sessoes = $this->sessoes([])->where('s.aberto_em', '>=', $inicio)->where('s.aberto_em', '<', self::diaSeguinte($fim))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(s.desvio), 0) AS desvios')->first();
        $total = number_format((float) $vendas->total, 2, '.', '');
        $n = (int) $vendas->n;
        $k = fn ($id, $rotulo, $valor, $formato, $sentido, $ajuda = '') => compact('id', 'rotulo', 'valor', 'formato', 'sentido', 'ajuda');

        return ['kpis' => [
            $k('pos_vendas', 'Vendas POS (c/ IVA)', $total, 'kz', 'sobe', 'Facturas-recibo emitidas nos terminais.'),
            $k('pos_tickets', 'Nº de vendas POS', $n, 'num', 'sobe'),
            $k('pos_ticket', 'Ticket médio POS', $n ? bcdiv($total, (string) $n, 2) : null, 'kz', 'sobe'),
            $k('pos_sessoes', 'Sessões de caixa POS', (int) $sessoes->n, 'num', 'neutro'),
            $k('pos_desvios', 'Desvios de caixa POS', number_format((float) $sessoes->desvios, 2, '.', ''), 'kz', 'neutro', 'Soma dos desvios no fecho (contado − esperado).'),
            // ganchos para os módulos de lavandaria e hotelaria (outros serviços): preenchidos quando existirem
            $k('lav_ordens', 'Ordens de lavandaria', null, 'num', 'sobe', 'Recebidas no período.'),
            $k('lav_valor', 'Valor das ordens', null, 'kz', 'sobe', 'Serviços + extras não cancelados.'),
            $k('lav_prazo', 'Entregas no prazo', null, 'pct', 'sobe', 'Entregues até à data prometida ÷ entregues.'),
            $k('hot_estadias', 'Estadias', null, 'num', 'sobe', 'Check-in no período.'),
            $k('hot_ocupacao', 'Taxa de ocupação', null, 'pct', 'sobe', 'Noites vendidas ÷ (quartos × dias).'),
            $k('hot_adr', 'Preço médio por noite (ADR)', null, 'kz', 'sobe', 'Receita de alojamento sem IVA ÷ noites vendidas.'),
            $k('hot_revpar', 'Receita por quarto disponível (RevPAR)', null, 'kz', 'sobe', 'Receita ÷ (quartos × dias).'),
        ]];
    }

    /** Totais por meio de pagamento das vendas (pos_pagamentos; sem detalhe, o meio da venda com o total — como o legado). */
    private function porMeio(array $f): array
    {
        $detalhe = $this->vendas($f)->whereRaw("jsonb_typeof(v.pos_pagamentos) = 'array'")
            ->crossJoin(DB::raw("LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(v.pos_pagamentos) = 'array' THEN v.pos_pagamentos ELSE '[]'::jsonb END) AS p"))
            ->groupBy(DB::raw("p->>'tipo'"), DB::raw("p->>'nome'"))
            ->selectRaw("p->>'tipo' AS tipo, COALESCE(p->>'nome', p->>'tipo') AS nome, COUNT(*) AS quantidade, SUM((p->>'valor')::numeric) AS valor")->get();
        $semDetalhe = $this->vendas($f)->where(fn ($q) => $q->whereNull('v.pos_pagamentos')->orWhereRaw("jsonb_typeof(v.pos_pagamentos) <> 'array'"))
            ->groupBy('v.meio_pagamento')->selectRaw("NULL AS tipo, COALESCE(v.meio_pagamento, 'Sem detalhe') AS nome, COUNT(*) AS quantidade, SUM(v.total_bruto) AS valor")->get();

        return $detalhe->concat($semDetalhe)->map(fn ($r) => ['tipo' => $r->tipo, 'nome' => $r->nome, 'quantidade' => (int) $r->quantidade,
            'valor' => number_format((float) $r->valor, 2, '.', '')])->sortByDesc(fn ($r) => (float) $r['valor'])->values()->all();
    }

    private function porProduto(array $f): array
    {
        return $this->vendas($f)->join('itens_venda as i', 'i.venda_id', '=', 'v.id')->leftJoin('produtos as p', 'p.id', '=', 'i.produto_id')
            ->groupBy('i.produto_id', 'p.codigo', 'p.nome')
            ->selectRaw('i.produto_id, p.codigo, COALESCE(p.nome, MAX(i.descricao)) AS nome, SUM(i.quantidade) AS quantidade,
                SUM(COALESCE(i.total_linha, 0)) AS total_liquido, SUM(COALESCE(i.total, i.quantidade * i.preco_unitario)) AS total_bruto')
            ->orderByDesc('total_bruto')->get()
            ->map(fn ($r) => ['produto_id' => $r->produto_id, 'codigo' => $r->codigo, 'nome' => $r->nome, 'quantidade' => (string) (float) $r->quantidade,
                'total_liquido' => number_format((float) $r->total_liquido, 2, '.', ''), 'total_bruto' => number_format((float) $r->total_bruto, 2, '.', '')])->all();
    }

    /** Facturas-recibo POS não anuladas, com a sessão, filtradas. */
    private function vendas(array $f): Builder
    {
        return DB::table('vendas as v')->join('sessoes_pos as s', 's.id', '=', 'v.sessao_pos_id')
            ->where('v.empresa_id', $this->contexto->obrigatorio())->where('v.tipo_documento', 'FR')
            ->where(fn ($q) => $q->whereNull('v.estado')->orWhere('v.estado', '<>', 'ANULADO'))
            ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('v.data_emissao', '>=', $v))
            ->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('v.data_emissao', '<', self::diaSeguinte($v)))
            ->when($f['terminal_pos_id'] ?? null, fn ($q, $v) => $q->where('s.terminal_pos_id', $v))
            ->when($f['operador_id'] ?? null, fn ($q, $v) => $q->where('s.operador_id', $v));
    }

    private static function diaSeguinte(string $data): string
    {
        return Carbon::parse(substr($data, 0, 10))->addDay()->toDateString();
    }

    private function sessoes(array $f): Builder
    {
        return DB::table('sessoes_pos as s')->where('s.empresa_id', $this->contexto->obrigatorio())
            ->when($f['terminal_pos_id'] ?? null, fn ($q, $v) => $q->where('s.terminal_pos_id', $v))
            ->when($f['operador_id'] ?? null, fn ($q, $v) => $q->where('s.operador_id', $v));
    }
}
