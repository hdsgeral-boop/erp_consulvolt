<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\Venda;
use Illuminate\Support\Facades\DB;

/**
 * Consulta e reimpressão das vendas de uma sessão (F1b; o legado não tinha) e regra do atalho para a nota de crédito (Q17):
 *   - NC sobre uma factura-recibo de sessão ainda aberta ou fechada mas não integrada: permitida (credita a conta do cliente, ADR-071);
 *   - NC sobre factura-recibo de sessão JÁ INTEGRADA na contabilidade: bloqueada (NC_SESSAO_INTEGRADA). O estorno na transitória
 *     que a permitiria (com permissão contabilística) é da F6; até lá, para a emitir descontabilize antes a sessão.
 */
final class ServicoConsultaVendasPOS
{
    /** @return list<array<string, mixed>> */
    public function listar(SessaoPOS $s, ?string $q = null): array
    {
        $vendas = Venda::query()->where('sessao_pos_id', $s->id)->when($q !== null && $q !== '', fn ($w) => $w->where('numero_documento', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], mb_substr($q, 0, 60)).'%'))
            ->orderByDesc('id')->limit(500)->get();
        $nomes = Terceiro::query()->whereIn('id', $vendas->pluck('cliente_id')->filter()->unique())->pluck('nome', 'id');
        $ncs = DB::table('vendas_documentos_relacionados as r')->join('vendas as nc', 'nc.id', '=', 'r.venda_id')
            ->whereIn('r.venda_relacionada_id', $vendas->pluck('id'))->where('nc.tipo_documento', 'NC')->where('nc.estado', '<>', 'ANULADO')
            ->get(['r.venda_relacionada_id as origem', 'nc.id', 'nc.numero_documento', 'nc.total_bruto'])->groupBy('origem');
        $bloqueio = self::motivoBloqueioNc($s);

        return $vendas->map(function (Venda $v) use ($nomes, $ncs, $bloqueio) {
            $lista = ($ncs[$v->id] ?? collect())->map(fn ($n) => ['id' => $n->id, 'numero_documento' => $n->numero_documento, 'total_bruto' => number_format((float) $n->total_bruto, 2, '.', '')])->values()->all();
            $creditado = array_reduce($lista, fn ($c, $n) => bcadd($c, $n['total_bruto'], 2), '0.00');
            $saldo = bcsub((string) $v->total_bruto, $creditado, 2);

            return ['id' => $v->id, 'numero_documento' => $v->numero_documento, 'tipo_documento' => $v->tipo_documento, 'estado' => $v->estado, 'criado_em' => $v->criado_em?->toIso8601String(),
                'cliente_id' => $v->cliente_id, 'cliente_nome' => $nomes[$v->cliente_id] ?? 'Consumidor Final', 'total_bruto' => number_format((float) $v->total_bruto, 2, '.', ''),
                'pos_pagamentos' => $v->pos_pagamentos ?? [], 'pos_troco' => $v->pos_troco, 'pos_operador' => $v->pos_operador, 'nome_tabela' => $v->nome_tabela,
                'notas_credito' => $lista, 'creditado' => $creditado, 'saldo_creditavel' => $saldo,
                'nc_permitida' => $v->tipo_documento === 'FR' && $v->estado !== 'ANULADO' && bccomp($saldo, '0', 2) > 0 && $bloqueio === null,
                'nc_motivo_bloqueio' => $v->tipo_documento !== 'FR' ? 'Só as facturas-recibo têm atalho de nota de crédito.' : ($bloqueio['mensagem'] ?? (bccomp($saldo, '0', 2) <= 0 ? 'A factura-recibo já está totalmente creditada.' : null))];
        })->all();
    }

    /** Venda com as linhas, para a reimpressão do talão. */
    public function detalhe(SessaoPOS $s, int $venda): Venda
    {
        return Venda::query()->where('sessao_pos_id', $s->id)->findOrFail($venda)->load('itensVenda');
    }

    /** @return ?array{codigo: string, mensagem: string} */
    public static function motivoBloqueioNc(SessaoPOS $s): ?array
    {
        return $s->estado_contabilizacao === 'CONTABILIZADA'
            ? ['codigo' => 'NC_SESSAO_INTEGRADA', 'mensagem' => "A sessão {$s->codigo_sessao} já está integrada na contabilidade: a nota de crédito exige o estorno na conta transitória (a tratar na fase de integração). Descontabilize a sessão primeiro."]
            : null;
    }

    /** Chamado pela validação da nota de crédito (ServicoDocumentosVenda): recusa NC sobre factura-recibo de sessão integrada. */
    public static function exigirNcPermitida(Venda $origem): void
    {
        if (! $origem->sessao_pos_id) {
            return;
        }
        $s = SessaoPOS::query()->find($origem->sessao_pos_id);
        $b = $s ? self::motivoBloqueioNc($s) : null;
        if ($b) {
            throw new ErroNegocio($b['mensagem'], $b['codigo'], 422, ['venda_id' => $origem->id, 'sessao_pos_id' => $s->id]);
        }
    }
}
