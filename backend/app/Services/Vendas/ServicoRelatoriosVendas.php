<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores do ecrã «Relatórios de vendas» (paridade: renderRelatoriosVendasTab do legado, js/ui_sales.js), calculados em SQL.
 * Contam as facturas (FT) e facturas-recibo (FR) menos as notas de crédito (NC); os anulados não contam.
 * Valores oficiais em Kz (total_* do documento), devolvidos como texto decimal com 2 casas.
 */
final class ServicoRelatoriosVendas
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * @return array{periodo: array{inicio: string, fim: string}, documentos: int, liquido: string, imposto: string, bruto: string,
     *               notas_credito: string, a_receber: string, por_mes: list<array{mes: string, liquido: string, bruto: string, documentos: int}>,
     *               maiores_clientes: list<array{cliente_id: int|null, nome: string, nif: string|null, bruto: string, documentos: int}>,
     *               pendentes: list<array<string, mixed>>}
     */
    public function resumo(string $inicio, string $fim, int $topN = 10, int $nPendentes = 10): array
    {
        if ($inicio > $fim) {
            throw new ErroNegocio('A data de início não pode ser posterior à data de fim.', 'PERIODO_INVALIDO', 422);
        }
        $sinal = "CASE WHEN v.tipo_documento = 'NC' THEN -1 ELSE 1 END";

        $t = $this->base($inicio, $fim)->selectRaw(
            "COUNT(*) AS documentos,
             COALESCE(SUM({$sinal} * COALESCE(v.total_liquido, 0)), 0) AS liquido,
             COALESCE(SUM({$sinal} * COALESCE(v.total_imposto, 0)), 0) AS imposto,
             COALESCE(SUM({$sinal} * COALESCE(v.total_bruto, 0)), 0) AS bruto,
             COALESCE(SUM(CASE WHEN v.tipo_documento = 'NC' THEN COALESCE(v.total_bruto, 0) ELSE 0 END), 0) AS notas_credito,
             COALESCE(SUM(CASE WHEN v.tipo_documento = 'FT' THEN COALESCE(v.valor_pendente, 0) ELSE 0 END), 0) AS a_receber"
        )->first();

        $porMes = $this->base($inicio, $fim)
            ->selectRaw("to_char(v.data_emissao, 'YYYY-MM') AS mes, COUNT(*) AS documentos,
                COALESCE(SUM({$sinal} * COALESCE(v.total_liquido, 0)), 0) AS liquido, COALESCE(SUM({$sinal} * COALESCE(v.total_bruto, 0)), 0) AS bruto")
            ->groupByRaw("to_char(v.data_emissao, 'YYYY-MM')")->orderBy('mes')->get()
            ->map(fn ($m) => ['mes' => $m->mes, 'liquido' => $this->dec($m->liquido), 'bruto' => $this->dec($m->bruto), 'documentos' => (int) $m->documentos])
            ->values()->all();

        // Maiores clientes: só FT/FR (como no legado), ordenados pelo facturado com IVA.
        $clientes = $this->base($inicio, $fim)->whereIn('v.tipo_documento', ['FT', 'FR'])
            ->leftJoin('terceiros as t', 't.id', '=', 'v.cliente_id')
            ->selectRaw('v.cliente_id, MAX(t.nome) AS nome, MAX(t.nif) AS nif, COUNT(*) AS documentos, COALESCE(SUM(COALESCE(v.total_bruto, 0)), 0) AS bruto')
            ->groupBy('v.cliente_id')->orderByDesc('bruto')->orderBy('v.cliente_id')->limit($topN)->get()
            ->map(fn ($c) => ['cliente_id' => $c->cliente_id !== null ? (int) $c->cliente_id : null,
                'nome' => trim((string) $c->nome) !== '' ? trim((string) $c->nome) : ($c->cliente_id !== null ? "#{$c->cliente_id}" : 'Consumidor final'),
                'nif' => $c->nif, 'bruto' => $this->dec($c->bruto), 'documentos' => (int) $c->documentos])
            ->values()->all();

        $pendentes = $this->base($inicio, $fim)->where('v.tipo_documento', 'FT')->where('v.valor_pendente', '>', 0)
            ->leftJoin('terceiros as t', 't.id', '=', 'v.cliente_id')
            ->select(['v.id', 'v.numero_documento', 'v.data_emissao', 'v.data_vencimento', 'v.cliente_id', 't.nome as cliente_nome', 'v.total_bruto', 'v.valor_pendente'])
            ->orderByDesc('v.data_emissao')->orderByDesc('v.id')->limit($nPendentes)->get()
            ->map(fn ($p) => ['id' => (int) $p->id, 'numero_documento' => $p->numero_documento, 'data_emissao' => substr((string) $p->data_emissao, 0, 10),
                'data_vencimento' => $p->data_vencimento, 'cliente' => $p->cliente_id !== null ? ['id' => (int) $p->cliente_id, 'nome' => $p->cliente_nome] : null,
                'total_bruto' => $this->dec($p->total_bruto), 'valor_pendente' => $this->dec($p->valor_pendente)])
            ->values()->all();

        return [
            'periodo' => ['inicio' => $inicio, 'fim' => $fim],
            'documentos' => (int) $t->documentos,
            'liquido' => $this->dec($t->liquido),
            'imposto' => $this->dec($t->imposto),
            'bruto' => $this->dec($t->bruto),
            'notas_credito' => $this->dec($t->notas_credito),
            'a_receber' => $this->dec($t->a_receber),
            'por_mes' => $porMes,
            'maiores_clientes' => $clientes,
            'pendentes' => $pendentes,
        ];
    }

    /** FT/FR/NC não anulados do período (data_emissao é timestamptz: fim inclusivo até às 23:59:59). Âmbito da empresa activa. */
    private function base(string $inicio, string $fim): Builder
    {
        return DB::table('vendas as v')->where('v.empresa_id', $this->contexto->obrigatorio())
            ->whereIn('v.tipo_documento', Venda::FISCAIS)
            ->where('v.data_emissao', '>=', $inicio)->where('v.data_emissao', '<', date('Y-m-d', strtotime("{$fim} +1 day")))
            ->where(fn ($q) => $q->whereNull('v.estado')->orWhere('v.estado', '<>', 'ANULADO'));
    }

    private function dec(mixed $v): string
    {
        return number_format(round((float) $v, 2), 2, '.', '');
    }
}
