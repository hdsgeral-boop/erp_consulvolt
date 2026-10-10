<?php

namespace App\Services\Migracao;

use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Models\StockArmazem;
use App\Models\Venda;
use App\Services\Logistica\ServicoMigracaoStock;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Vendas\ServicoEstadoVenda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Correcções de dados herdados do legado (decisões do utilizador 2026-10-10, ADR-070). Idempotentes: correm no fim da
 * migração e no comando erp:migracao:corrigir-dados (que tem --simular). Cada correcção fica gravada nos logs de
 * auditoria (acção CORRECAO_MIGRACAO) da empresa afectada — a «notificação salva», visível em Sistema › Validações.
 *
 *  1. Movimentos de stock num armazém de OUTRA empresa (erro fatal): no legado o POS sem armazém configurado usava o
 *     armazém 1 por omissão. O movimento pertence à empresa dona do produto/venda; se ela existir, passa para o armazém
 *     dela (o predefinido, senão o primeiro) e o histórico volta a bater com o saldo por armazém do legado (acerto de
 *     migração); se a empresa já não existir, ou não tiver armazéns, o movimento é eliminado.
 *  2. Notas de crédito emitidas sobre uma factura abatem a factura (pendente/estado por ServicoEstadoVenda, como nas
 *     NC emitidas no sistema novo) e a própria NC não fica pendente.
 */
final class ServicoCorrecoesPosMigracao
{
    public const ACCAO = 'CORRECAO_MIGRACAO';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoAuditoria $auditoria,
        private readonly ServicoEstadoVenda $estadoVenda,
    ) {}

    /** @return array{armazens: array<string, int>, notas_credito: array<string, int>} */
    public function aplicar(bool $acertarStock = true): array
    {
        return ['armazens' => $this->movimentosEmArmazemDeOutraEmpresa($acertarStock), 'notas_credito' => $this->abaterNotasCredito()];
    }

    /**
     * @param  bool  $acertarStock  false na migração: o acerto geral (ServicoMigracaoStock::acertar) corre a seguir
     * @return array{reatribuidos: int, eliminados: int, acertos: int}
     */
    public function movimentosEmArmazemDeOutraEmpresa(bool $acertarStock = true): array
    {
        $r = ['reatribuidos' => 0, 'eliminados' => 0, 'acertos' => 0];
        $linhas = DB::table('movimentos_inventario as m')->join('armazens as a', 'a.id', '=', 'm.armazem_id')
            ->leftJoin('produtos as p', 'p.id', '=', 'm.produto_id')
            ->whereColumn('a.empresa_id', '<>', 'm.empresa_id')
            ->orderBy('m.id')
            ->get(['m.*', 'a.empresa_id as empresa_armazem', 'a.nome as nome_armazem', 'p.empresa_id as empresa_produto']);
        $pares = [];
        foreach ($linhas as $m) {
            $dona = (int) ($m->empresa_produto ?? $m->empresa_id);
            $existe = DB::table('empresas')->where('id', $dona)->whereNull('eliminado_em')->exists();
            $destino = $existe ? DB::table('armazens')->where('empresa_id', $dona)->whereNull('eliminado_em')
                ->orderByRaw('predefinido IS TRUE DESC')->orderBy('id')->first(['id', 'nome']) : null;
            $anterior = ['empresa_id' => (int) $m->empresa_id, 'armazem_id' => (int) $m->armazem_id, 'armazem' => $m->nome_armazem,
                'empresa_do_armazem' => (int) $m->empresa_armazem, 'produto_id' => $m->produto_id, 'sentido' => $m->sentido,
                'quantidade' => $m->quantidade, 'data' => $m->data, 'referencia' => $m->referencia];
            if ($destino) {
                DB::table('movimentos_inventario')->where('id', $m->id)->update(['empresa_id' => $dona, 'armazem_id' => $destino->id, 'atualizado_em' => now()]);
                $this->registar($dona, 'Logística', 'movimentos_inventario', $m->id, $anterior, ['empresa_id' => $dona, 'armazem_id' => $destino->id, 'armazem' => $destino->nome],
                    "Movimento de stock «{$m->referencia}» estava no armazém «{$m->nome_armazem}» de outra empresa (#{$m->empresa_armazem}): passou para o armazém «{$destino->nome}» da empresa dona.");
                $pares[$dona][$destino->id.':'.$m->produto_id] = [$destino->id, (int) $m->produto_id];
                $r['reatribuidos']++;
            } else {
                DB::table('movimentos_inventario')->where('id', $m->id)->delete();
                $this->registar($existe ? $dona : (int) $m->empresa_armazem, 'Logística', 'movimentos_inventario', $m->id, $anterior, null,
                    "Movimento de stock «{$m->referencia}» no armazém «{$m->nome_armazem}» de outra empresa eliminado: "
                    .($existe ? 'a empresa dona não tem armazéns.' : "a empresa dona (#{$dona}) já não existe."));
                $r['eliminados']++;
            }
        }
        if ($acertarStock) {
            foreach ($pares as $empresa => $lista) {
                $r['acertos'] += $this->contexto->executarComo($empresa, fn () => $this->acertarPares(array_values($lista)));
            }
        }

        return $r;
    }

    /** @return array{facturas: int, notas: int} */
    public function abaterNotasCredito(): array
    {
        $r = ['facturas' => 0, 'notas' => 0];
        $facturas = DB::table('vendas as f')->join('vendas_documentos_relacionados as rel', 'rel.venda_relacionada_id', '=', 'f.id')
            ->join('vendas as nc', fn ($j) => $j->on('nc.id', '=', 'rel.venda_id')->where('nc.tipo_documento', 'NC'))
            ->whereIn('f.tipo_documento', ['FT', 'FR'])->where(fn ($q) => $q->whereNull('nc.estado')->orWhere('nc.estado', '<>', 'ANULADO'))
            ->where(fn ($q) => $q->whereNull('f.estado')->orWhere('f.estado', '<>', 'ANULADO'))
            ->distinct()->get(['f.id', 'f.empresa_id']);
        foreach ($facturas as $f) {
            $this->contexto->executarComo((int) $f->empresa_id, function () use ($f, &$r) {
                $venda = Venda::query()->findOrFail($f->id);
                $antes = ['estado' => $venda->estado, 'valor_pendente' => (string) $venda->valor_pendente];
                $this->estadoVenda->recalcular($venda);
                $venda->refresh();
                $depois = ['estado' => $venda->estado, 'valor_pendente' => (string) $venda->valor_pendente];
                if ($antes != $depois) {
                    $this->registar((int) $f->empresa_id, 'Vendas', 'vendas', $venda->id, $antes, $depois,
                        "Factura {$venda->numero_documento} abatida pelas notas de crédito emitidas sobre ela.");
                    $r['facturas']++;
                }
            });
        }
        $notas = DB::table('vendas')->where('tipo_documento', 'NC')->where('valor_pendente', '<>', 0)->orderBy('id')
            ->get(['id', 'empresa_id', 'numero_documento', 'valor_pendente']);
        foreach ($notas as $nc) {
            DB::table('vendas')->where('id', $nc->id)->update(['valor_pendente' => 0, 'atualizado_em' => now()]);
            $this->registar((int) $nc->empresa_id, 'Vendas', 'vendas', $nc->id, ['valor_pendente' => (string) $nc->valor_pendente], ['valor_pendente' => '0.00'],
                "Nota de crédito {$nc->numero_documento} sem valor pendente (a NC abate a factura, não fica a receber).");
            $r['notas']++;
        }

        return $r;
    }

    /**
     * Volta a pôr o histórico de movimentos igual ao saldo por armazém do legado nos pares (armazém, produto) afectados,
     * com um movimento de acerto de migração por diferença (a mesma regra de ServicoMigracaoStock::acertar).
     *
     * @param  list<array{0: int, 1: int}>  $pares
     */
    private function acertarPares(array $pares): int
    {
        $n = 0;
        foreach ($pares as [$armazem, $produto]) {
            $s = StockArmazem::query()->where('armazem_id', $armazem)->where('produto_id', $produto)->first();
            if (! $s) {
                continue;
            }
            $calc = (string) MovimentoInventario::query()->where('armazem_id', $armazem)->where('produto_id', $produto)
                ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'S' THEN -quantidade WHEN sentido = 'E' THEN quantidade ELSE 0 END), 0) AS q")->value('q');
            $dif = bcsub((string) $s->quantidade_stock, $calc, 3);
            if (bccomp($dif, '0', 3) === 0) {
                continue;
            }
            $custo = (string) (Produto::query()->whereKey($produto)->value('custo_medio') ?? 0);
            $q = ltrim($dif, '-');
            MovimentoInventario::create(['produto_id' => $produto, 'armazem_id' => $armazem, 'tipo' => 'AJUSTE', 'sentido' => bccomp($dif, '0', 3) > 0 ? 'E' : 'S',
                'quantidade' => $q, 'data' => now(), 'referencia' => ServicoMigracaoStock::REFERENCIA, 'preco_unitario' => number_format((float) $custo, 2, '.', ''),
                'valor' => number_format(round((float) $q * (float) $custo, 2), 2, '.', ''), 'custo_medio_apos' => $custo, 'documento_tipo' => 'MIGRACAO', 'criado_por' => 'migração']);
            $n++;
        }

        return $n;
    }

    /** @param  array<string, mixed>|null  $novos */
    private function registar(int $empresa, string $modulo, string $tabela, int $id, array $anteriores, ?array $novos, string $detalhes): void
    {
        $this->auditoria->registar($modulo, self::ACCAO, $detalhes, $tabela, $id, $anteriores, $novos, $empresa, null, 'sistema (correcção pós-migração)');
    }
}
