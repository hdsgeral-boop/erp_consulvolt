<?php

namespace App\Services\CRM;

use Illuminate\Support\Facades\DB;

/**
 * Pós-carga do CRM (ETL): os JSON do legado usam chaves em inglês e os nomes antigos das colunas (product_id, sale_id,
 * doc_type, doc_number, etapa_id, modelo_id); passam às chaves do sistema novo, que são as únicas lidas pelos serviços
 * CRM. O tipo de documento das vendas ligadas passa ao código (FT, FR, OR, PF, NE…), com o texto em
 * `tipo_documento_original`. Idempotente: chaves já traduzidas ficam como estão.
 */
final class ServicoMigracaoCRM
{
    private const ITEM = ['product_id' => 'produto_id'];

    private const VENDA = ['sale_id' => 'venda_id', 'doc_type' => 'tipo_documento', 'doc_number' => 'numero_documento'];

    private const HISTORICO = ['etapa_id' => 'etapa_codigo'];

    private const TAREFA = ['modelo_id' => 'modelo_email_crm_id'];

    /** Mapa de sales.doc_type (normalizacoes.mjs), indexado pelo texto dobrado. */
    private const TIPOS_DOCUMENTO = ['FACTURA' => 'FT', 'FATURA' => 'FT', 'FT' => 'FT', 'FACTURA RECIBO' => 'FR', 'FATURA RECIBO' => 'FR', 'FR' => 'FR',
        'NOTA DE CREDITO' => 'NC', 'NC' => 'NC', 'PROFORMA' => 'PF', 'FACTURA PROFORMA' => 'PF', 'FATURA PROFORMA' => 'PF', 'PF' => 'PF',
        'ORCAMENTO' => 'OR', 'OR' => 'OR', 'ENCOMENDA' => 'NE', 'NOTA DE ENCOMENDA' => 'NE', 'NE' => 'NE'];

    /** @return array{oportunidades: int, funis: int, sequencias: int} */
    public function normalizar(): array
    {
        $r = ['oportunidades' => 0, 'funis' => 0, 'sequencias' => 0];
        foreach (DB::table('oportunidades_venda_crm')->get(['id', 'itens', 'vendas', 'historico']) as $o) {
            $lista = fn (?string $j, array $mapa, ?callable $extra = null) => $j === null ? null
                : json_encode(array_map(fn ($x) => $extra ? $extra(self::traduzir($x, $mapa)) : self::traduzir($x, $mapa), json_decode($j, true) ?: []), JSON_UNESCAPED_UNICODE);
            DB::table('oportunidades_venda_crm')->where('id', $o->id)->update([
                'itens' => $lista($o->itens, self::ITEM), 'historico' => $lista($o->historico, self::HISTORICO),
                'vendas' => $lista($o->vendas, self::VENDA, function ($v) {
                    $t = (string) ($v['tipo_documento'] ?? '');
                    $codigo = self::TIPOS_DOCUMENTO[RegrasCRM::dobrar($t)] ?? null;
                    if ($codigo && $codigo !== $t) {
                        $v['tipo_documento_original'] ??= $t;
                        $v['tipo_documento'] = $codigo;
                    }

                    return $v;
                }),
            ]);
            $r['oportunidades']++;
        }
        foreach (DB::table('funis_vendas_crm')->get(['id', 'etapas']) as $f) {
            $etapas = array_map(function ($e) {
                if (is_array($e) && isset($e['tarefas']) && is_array($e['tarefas'])) {
                    $e['tarefas'] = array_map(fn ($t) => self::traduzir($t, self::TAREFA), $e['tarefas']);
                }

                return $e;
            }, json_decode((string) $f->etapas, true) ?: []);
            DB::table('funis_vendas_crm')->where('id', $f->id)->update(['etapas' => json_encode($etapas, JSON_UNESCAPED_UNICODE)]);
            $r['funis']++;
        }
        foreach (DB::table('sequencias_campanhas_crm')->whereNotNull('passos')->get(['id', 'passos']) as $s) {
            $passos = array_map(fn ($p) => self::traduzir($p, self::TAREFA), json_decode((string) $s->passos, true) ?: []);
            DB::table('sequencias_campanhas_crm')->where('id', $s->id)->update(['passos' => json_encode($passos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $r['sequencias']++;
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
