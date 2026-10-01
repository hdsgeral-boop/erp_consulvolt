<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Models\EstadiaHotel;
use App\Models\PedidoLavandaria;
use App\Services\Gestao\Relatorios\PeriodosGestao;
use App\Services\POS\Lavandaria\RegrasLavandaria;
use App\Services\POS\ServicoRelatoriosPOS;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * POS e Serviços (relatorios_gestao.js:531-571): pontos de venda, lavandaria e hotelaria.
 *   - POS: indicadores do módulo (ServicoRelatoriosPOS::indicadoresServicos, ADR-048) e vendas por meio de pagamento
 *     (totais por método das sessões abertas no período, como o legado);
 *   - lavandaria: ordens não anuladas recebidas no período; valor = serviços + taxas não cancelados pelas regras do módulo
 *     (RegrasLavandaria::totais, ADR-049); entregas no prazo = entregues até à data prometida ÷ entregues;
 *   - hotelaria: estadias não anuladas com entrada no período; noites = quantidade final (ou contratada) das estadias à
 *     diária; ocupação = noites ÷ (quartos × dias); ADR e RevPAR sobre a receita de alojamento sem IVA.
 * Correcções na hotelaria:
 *   - a receita conta cada factura uma única vez: com factura única no check-out, várias estadias partilham a mesma venda e
 *     o legado somava-a uma vez por estadia;
 *   - a receita de alojamento é a das linhas dos quartos (sem IVA); o legado somava o total da factura, com os consumos.
 */
final class ModuloServicos extends ModuloGestao
{
    public function __construct(ContextoEmpresa $contexto, private readonly ServicoRelatoriosPOS $pos)
    {
        parent::__construct($contexto);
    }

    public function id(): string
    {
        return 'servicos';
    }

    public function nome(): string
    {
        return 'POS e Serviços';
    }

    public function descricao(): string
    {
        return 'Pontos de venda, lavandaria e hotelaria.';
    }

    public function calcular(array $p): array
    {
        $e = $this->empresa();
        $kpis = [];
        foreach ($this->pos->indicadoresServicos($p['inicio'], $p['fim'])['kpis'] as $k) {
            if (str_starts_with($k['id'], 'pos_')) {
                $kpis[] = self::k($k['id'], $k['rotulo'], $k['valor'], $k['formato'], $k['sentido'], (string) ($k['ajuda'] ?? ''));
            }
        }
        $dias = PeriodosGestao::dias($p);
        $fimExcl = date('Y-m-d', strtotime($p['fim'].' +1 day'));

        // Lavandaria
        $ordens = PedidoLavandaria::query()->where('estado', '<>', 'ANULADA')->where('recebido_em', '>=', $p['inicio'])->where('recebido_em', '<', $fimExcl)
            ->get(['id', 'itens', 'extras', 'entregue_em', 'data_prometida']);
        $valorOrdens = $ordens->reduce(fn ($s, $o) => bcadd($s, RegrasLavandaria::totais($o->itens ?? [], $o->extras ?? [], '0.00', '0.00')['total'], 2), '0.00');
        $entregues = $ordens->filter(fn ($o) => $o->entregue_em !== null);
        $noPrazo = $entregues->filter(fn ($o) => $o->data_prometida && $o->entregue_em->toDateString() <= $o->data_prometida->toDateString())->count();
        array_push($kpis,
            self::k('lav_ordens', 'Ordens de lavandaria', $ordens->count(), 'num', 'sobe', 'Recebidas no período.'),
            self::k('lav_valor', 'Valor das ordens', $valorOrdens, 'kz', 'sobe', 'Serviços + extras não cancelados.'),
            self::k('lav_prazo', 'Entregas no prazo', self::pct($noPrazo, $entregues->count()), 'pct', 'sobe', 'Entregues até à data prometida ÷ entregues.'),
        );

        // Hotelaria
        $estadias = EstadiaHotel::query()->where('estado', '<>', EstadiaHotel::ANULADA)->where('entrada_em', '>=', $p['inicio'])->where('entrada_em', '<', $fimExcl)
            ->get(['id', 'modo', 'quantidade', 'quantidade_final', 'venda_id']);
        $noites = $estadias->filter(fn ($x) => strtoupper((string) $x->modo) !== 'HORA')->sum(fn ($x) => (float) ($x->quantidade_final ?: $x->quantidade));
        $idsVendas = DB::table('vendas_estadias_hotel')->where('empresa_id', $e)->whereIn('estadia_hotel_id', $estadias->pluck('id'))->pluck('venda_id')
            ->merge($estadias->pluck('venda_id'))->filter()->unique()->values();
        $receita = $idsVendas->isEmpty() ? '0.00' : self::dinheiro(DB::table('itens_venda as i')->join('vendas as v', 'v.id', '=', 'i.venda_id')
            ->join('produtos as pr', 'pr.id', '=', 'i.produto_id')->where('v.empresa_id', $e)->whereIn('v.id', $idsVendas)->whereRaw(self::sqlValido('v.estado'))
            ->where('pr.e_quarto', true)->sum(DB::raw('COALESCE(i.total_linha, 0)')));
        $quartos = DB::table('produtos')->where('empresa_id', $e)->whereNull('eliminado_em')->where('e_quarto', true)->count();
        array_push($kpis,
            self::k('hot_estadias', 'Estadias', $estadias->count(), 'num', 'sobe', 'Check-in no período.'),
            self::k('hot_ocupacao', 'Taxa de ocupação', self::pct($noites, $quartos * $dias), 'pct', 'sobe', 'Noites vendidas ÷ (quartos × dias).'),
            self::k('hot_adr', 'Preço médio por noite (ADR)', ($d = self::div($receita, $noites)) === null ? null : self::dinheiro($d), 'kz', 'sobe', 'Receita de alojamento sem IVA ÷ noites vendidas.'),
            self::k('hot_revpar', 'Receita por quarto disponível (RevPAR)', ($d = self::div($receita, $quartos * $dias)) === null ? null : self::dinheiro($d), 'kz', 'sobe', 'Receita ÷ (quartos × dias).'),
        );

        // Vendas POS por meio de pagamento (totais por método das sessões abertas no período)
        $meios = DB::table('sessoes_pos as s')->where('s.empresa_id', $e)->where('s.aberto_em', '>=', $p['inicio'])->where('s.aberto_em', '<', $fimExcl)
            ->crossJoin(DB::raw("LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(s.totais_por_metodo) = 'array' THEN s.totais_por_metodo ELSE '[]'::jsonb END) AS m"))
            ->groupBy(DB::raw("COALESCE(m->>'nome', m->>'tipo', '—')"))
            ->selectRaw("COALESCE(m->>'nome', m->>'tipo', '—') AS meio, SUM(COALESCE(NULLIF(m->>'quantidade', '')::numeric, 0)) AS n, SUM(COALESCE(NULLIF(m->>'valor', '')::numeric, 0)) AS valor")
            ->orderByDesc('valor')->get()->map(fn ($r) => ['meio' => $r->meio, 'n' => (int) $r->n, 'valor' => self::dinheiro($r->valor)])->all();

        return [
            'kpis' => $kpis,
            'tabelas' => [self::tabela('pos_meios', 'Vendas POS por meio de pagamento', 'meio', [['meio', 'Meio'], ['n', 'Nº', 'num'], ['valor', 'Valor', 'kz']], $meios)],
            'graficos' => [],
            'notas' => [],
        ];
    }
}
