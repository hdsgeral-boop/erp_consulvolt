<?php

namespace App\Services\Logistica;

use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Models\StockArmazem;
use Illuminate\Support\Facades\DB;

/**
 * Acerto do stock migrado (decisão do utilizador 2026-09-30, ADR-042): os saldos POR ARMAZÉM do legado são a
 * verdade; o histórico de movimentos passa a bater com eles através de um movimento «saldo inicial de migração» por
 * diferença; o stock total do produto passa a ser a soma dos armazéns; o custo médio inicial é o último custo de
 * recepção conhecido (o legado nunca calculou custo médio). As diferenças ficam num relatório de validação.
 */
final class ServicoMigracaoStock
{
    public const REFERENCIA = 'Saldo inicial de migração (acerto ao saldo por armazém do legado)';

    /** @return array{sem_tipo: int, acertos: int, totais_corrigidos: int, com_custo: int, sem_custo: int} */
    public function acertar(): array
    {
        $r = ['sem_tipo' => 0, 'acertos' => 0, 'totais_corrigidos' => 0, 'com_custo' => 0, 'sem_custo' => 0];
        // 1. saídas de guia gravadas pelo legado sem tipo (defeito do saveGuiaSaida)
        $r['sem_tipo'] = MovimentoInventario::query()->whereNull('tipo')->where('referencia', 'ilike', 'Guia Sa%')->update(['tipo' => 'SAIDA']);
        // 2. sentido e valor nos movimentos migrados
        MovimentoInventario::query()->whereNull('sentido')->whereIn('tipo', ['ENTRADA', 'SAIDA'])
            ->update(['sentido' => DB::raw("CASE WHEN tipo = 'SAIDA' THEN 'S' ELSE 'E' END"), 'valor' => DB::raw('ROUND(quantidade * COALESCE(preco_unitario, 0), 2)')]);
        // 3. custo médio inicial: último custo de recepção (ou de qualquer entrada) com preço
        foreach (Produto::query()->where('movimenta_stock', true)->get() as $p) {
            $custo = MovimentoInventario::query()->where('produto_id', $p->id)->where('sentido', 'E')->where('preco_unitario', '>', 0)
                ->orderByRaw("CASE WHEN referencia ILIKE 'Rece%' THEN 0 ELSE 1 END")->orderByDesc('data')->orderByDesc('id')->value('preco_unitario');
            $custo ? $r['com_custo']++ : $r['sem_custo']++;
            $p->forceFill(['custo_medio' => $custo ?? 0])->saveQuietly();
        }
        // 4. saldo do legado por armazém × soma dos movimentos → movimento de acerto
        foreach (StockArmazem::query()->get() as $s) {
            $calc = (string) MovimentoInventario::query()->where('armazem_id', $s->armazem_id)->where('produto_id', $s->produto_id)
                ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'S' THEN -quantidade WHEN sentido = 'E' THEN quantidade ELSE 0 END), 0) AS q")->value('q');
            $dif = bcsub((string) $s->quantidade_stock, $calc, 3);
            if (bccomp($dif, '0', 3) === 0) {
                continue;
            }
            $custo = (string) (Produto::query()->whereKey($s->produto_id)->value('custo_medio') ?? 0);
            $q = ltrim($dif, '-');
            MovimentoInventario::create(['produto_id' => $s->produto_id, 'armazem_id' => $s->armazem_id, 'tipo' => 'AJUSTE', 'sentido' => bccomp($dif, '0', 3) > 0 ? 'E' : 'S',
                'quantidade' => $q, 'data' => now(), 'referencia' => self::REFERENCIA, 'preco_unitario' => number_format((float) $custo, 2, '.', ''),
                'valor' => number_format(round((float) $q * (float) $custo, 2), 2, '.', ''), 'custo_medio_apos' => $custo, 'documento_tipo' => 'MIGRACAO', 'criado_por' => 'migração']);
            $r['acertos']++;
        }
        // 5. stock total do produto = soma dos armazéns
        foreach (Produto::query()->where('movimenta_stock', true)->get() as $p) {
            $soma = (string) StockArmazem::query()->where('produto_id', $p->id)->sum('quantidade_stock');
            if (bccomp($soma, (string) ($p->quantidade_stock ?? 0), 3) !== 0) {
                $p->forceFill(['quantidade_stock' => $soma])->saveQuietly();
                $r['totais_corrigidos']++;
            }
        }

        return $r;
    }
}
