<?php

namespace App\Services\POS;

use Illuminate\Support\Facades\DB;

/**
 * Pós-carga do POS (ETL): os JSON do legado (meios de pagamento, totais do Z, talões TPA, deliberações e pagamentos das
 * vendas) vêm com chaves em inglês; passam às chaves do sistema novo, que é a única forma lida pelos serviços POS.
 * Os identificadores dos meios (pm_*) mantêm-se: sessões, liquidações e vendas continuam a encontrá-los.
 */
final class ServicoMigracaoPOS
{
    private const MEIO = ['id' => 'id', 'kind' => 'tipo', 'name' => 'nome', 'active' => 'ativo', 'transit_account' => 'conta_transitoria',
        'settlement_account' => 'conta_liquidacao', 'tpa_code' => 'codigo_tpa', 'commission_pct' => 'comissao_pct', 'commission_account' => 'conta_comissao',
        'commission_deducted' => 'comissao_deduzida', 'copied_from' => 'copiado_de'];

    private const TOTAL = ['pm_id' => 'meio_id', 'kind' => 'tipo', 'name' => 'nome', 'transit_account' => 'conta_transitoria', 'tpa_code' => 'codigo_tpa',
        'amount' => 'valor', 'count' => 'quantidade'];

    private const TRANSFERENCIA = ['sale_id' => 'venda_id', 'doc_number' => 'numero_documento', 'amount' => 'valor', 'reference' => 'referencia', 'pm_id' => 'meio_id'];

    private const FECHO_TPA = ['pm_id' => 'meio_id', 'name' => 'nome', 'tpa_code' => 'codigo_tpa', 'system_amount' => 'valor_sistema', 'system_count' => 'operacoes_sistema',
        'receipt_amount' => 'valor_talao', 'receipt_count' => 'operacoes_talao', 'batch_ref' => 'referencia_lote', 'difference' => 'diferenca'];

    private const DELIBERACAO = ['decision' => 'decisao', 'note' => 'nota', 'amount' => 'valor', 'date' => 'data', 'by' => 'por', 'at' => 'em', 'account' => 'conta',
        'transit_account' => 'conta_transitoria', 'lan_number' => 'numero_lan', 'journal_id' => 'diario_id', 'cancelled_by' => 'anulado_por', 'cancelled_at' => 'anulado_em',
        'reason' => 'motivo'];

    private const PAGAMENTO = ['pm_id' => 'meio_id', 'kind' => 'tipo', 'name' => 'nome', 'amount' => 'valor', 'received' => 'recebido', 'reference' => 'referencia',
        'transit_account' => 'conta_transitoria', 'settlement_account' => 'conta_liquidacao', 'tpa_code' => 'codigo_tpa'];

    /** @return array{terminais: int, sessoes: int, vendas: int} */
    public function normalizar(): array
    {
        $r = ['terminais' => 0, 'sessoes' => 0, 'vendas' => 0];
        foreach (DB::table('terminais_pos')->whereNotNull('meios_pagamento')->get(['id', 'meios_pagamento']) as $t) {
            $meios = array_map(function ($m) {
                $m = self::traduzir($m, self::MEIO);
                foreach (['conta_transitoria', 'conta_liquidacao', 'conta_comissao', 'codigo_tpa'] as $c) {
                    if (array_key_exists($c, $m) && trim((string) $m[$c]) === '') {
                        $m[$c] = null;   // o legado gravava "" nas contas não preenchidas
                    }
                }

                return $m;
            }, json_decode($t->meios_pagamento, true) ?: []);
            DB::table('terminais_pos')->where('id', $t->id)->update(['meios_pagamento' => json_encode($meios)]);
            $r['terminais']++;
        }
        foreach (DB::table('sessoes_pos')->get(['id', 'totais_por_metodo', 'transferencias', 'fechos_tpa', 'deliberacao', 'deliberacao_cancelada']) as $s) {
            $lista = fn (?string $j, array $mapa) => $j === null ? null : json_encode(array_map(fn ($x) => self::traduzir($x, $mapa), json_decode($j, true) ?: []));
            $objeto = fn (?string $j) => $j === null ? null : json_encode(self::traduzir(json_decode($j, true) ?: [], self::DELIBERACAO) + ['automatica' => false]);
            DB::table('sessoes_pos')->where('id', $s->id)->update([
                'totais_por_metodo' => $lista($s->totais_por_metodo, self::TOTAL), 'transferencias' => $lista($s->transferencias, self::TRANSFERENCIA),
                'fechos_tpa' => $lista($s->fechos_tpa, self::FECHO_TPA), 'deliberacao' => $objeto($s->deliberacao), 'deliberacao_cancelada' => $objeto($s->deliberacao_cancelada),
            ]);
            $r['sessoes']++;
        }
        foreach (DB::table('vendas')->whereNotNull('pos_pagamentos')->get(['id', 'pos_pagamentos']) as $v) {
            DB::table('vendas')->where('id', $v->id)->update(['pos_pagamentos' => json_encode(array_map(fn ($p) => self::traduzir($p, self::PAGAMENTO), json_decode($v->pos_pagamentos, true) ?: []))]);
            $r['vendas']++;
        }

        return $r;
    }

    /** Traduz as chaves conhecidas; as já traduzidas ou desconhecidas ficam como estão (idempotente). */
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
