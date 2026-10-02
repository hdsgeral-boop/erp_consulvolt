<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Models\SessaoInventario;
use App\Models\StockArmazem;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Movimentos de stock — ÚNICA via de alteração do stock (ADR-028, ADR-042). No legado cada ecrã escrevia à mão em
 * products.stock_qty, warehouse_stock e inventory_movements, sem transacção, com o sinal só no tipo e a ligação ao
 * documento em texto livre. Aqui cada movimento, na mesma transacção e com o produto bloqueado (FOR UPDATE):
 *   - grava o histórico com SENTIDO (E/S), valor, custo médio após o movimento e o documento de origem;
 *   - actualiza stock_armazem (por armazém) e produtos.quantidade_stock (total);
 *   - nas entradas recalcula o custo médio ponderado (no legado nunca era calculado: a «média» caía no preço de venda);
 *   - recusa movimentar um armazém com inventário em curso (no legado a diferença era calculada contra uma
 *     fotografia desactualizada) — excepto a própria regularização do inventário.
 * As saídas são valorizadas ao custo indicado (ex.: o da entrada que se estorna) ou ao custo médio.
 */
final class ServicoStock
{
    public const TIPOS = ['ENTRADA', 'SAIDA', 'TRANSFERENCIA', 'AJUSTE'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
        private readonly ServicoExercicios $exercicios,
    ) {}

    /** @param  array{documento_tipo?: string, documento_id?: int}  $doc */
    public function entrada(int $produtoId, int $armazemId, string $quantidade, string $custoUnitario, string $data, string $referencia, ?int $terceiroId = null, ?int $projetoId = null, array $doc = []): array
    {
        return $this->movimentar(['tipo' => 'ENTRADA', 'sentido' => 'E', 'produto_id' => $produtoId, 'armazem_id' => $armazemId, 'quantidade' => $quantidade, 'custo' => $custoUnitario,
            'data' => $data, 'referencia' => $referencia, 'terceiro_id' => $terceiroId, 'projeto_id' => $projetoId] + $doc);
    }

    /** @param  array{documento_tipo?: string, documento_id?: int}  $doc */
    public function saida(int $produtoId, int $armazemId, string $quantidade, ?string $custoUnitario, string $data, string $referencia, ?int $terceiroId = null, ?int $projetoId = null, bool $permitirNegativo = false, array $doc = []): array
    {
        return $this->movimentar(['tipo' => 'SAIDA', 'sentido' => 'S', 'produto_id' => $produtoId, 'armazem_id' => $armazemId, 'quantidade' => $quantidade, 'custo' => $custoUnitario,
            'data' => $data, 'referencia' => $referencia, 'terceiro_id' => $terceiroId, 'projeto_id' => $projetoId, 'permitir_negativo' => $permitirNegativo] + $doc);
    }

    /**
     * Ajuste de stock (regularização): entrada ou saída ao custo indicado ou ao custo médio.
     *
     * @param  array<string, mixed>  $extra  documento_tipo, documento_id, ignorar_inventario, permitir_negativo
     */
    public function ajustar(int $produtoId, int $armazemId, string $sentido, string $quantidade, ?string $custo, string $data, string $referencia, array $extra = []): array
    {
        return $this->movimentar(['tipo' => 'AJUSTE', 'sentido' => $sentido, 'produto_id' => $produtoId, 'armazem_id' => $armazemId, 'quantidade' => $quantidade,
            'custo' => $custo, 'data' => $data, 'referencia' => $referencia] + $extra);
    }

    /**
     * Transferência entre armazéns (não existia no legado): saída do armazém de origem e entrada no destino ao custo
     * médio, com o mesmo número TRF AAAA/NNNN. O total do produto e o custo médio não mudam.
     *
     * @param  list<array{produto_id: int, quantidade: string|float}>  $linhas
     * @return array{numero: string, movimentos: int}
     */
    public function transferir(int $origem, int $destino, array $linhas, string $data, ?string $observacoes = null): array
    {
        if ($origem === $destino) {
            throw new ErroNegocio('O armazém de destino tem de ser diferente do de origem.', 'MESMO_ARMAZEM', 422);
        }
        Armazem::query()->findOrFail($origem);
        Armazem::query()->findOrFail($destino);
        if (! $linhas) {
            throw new ErroNegocio('Indique os produtos a transferir.', 'SEM_LINHAS', 422);
        }

        return DB::transaction(function () use ($origem, $destino, $linhas, $data, $observacoes) {
            $ano = substr($data, 0, 4);
            $n = $this->numeracao->proximo($this->contexto->obrigatorio(), "logistica:transferencia:{$ano}", fn () => 0);
            $numero = sprintf('TRF %s/%04d', $ano, $n);
            $ref = "Transferência {$numero}".($observacoes ? " — {$observacoes}" : '');
            $mov = 0;
            foreach ($linhas as $l) {
                $q = number_format((float) $l['quantidade'], 3, '.', '');
                $s = $this->movimentar(['tipo' => 'TRANSFERENCIA', 'sentido' => 'S', 'produto_id' => (int) $l['produto_id'], 'armazem_id' => $origem, 'quantidade' => $q,
                    'custo' => null, 'data' => $data, 'referencia' => $ref, 'documento_tipo' => 'TRANSFERENCIA', 'documento_id' => $n, 'armazem_contraparte_id' => $destino]);
                $this->movimentar(['tipo' => 'TRANSFERENCIA', 'sentido' => 'E', 'produto_id' => (int) $l['produto_id'], 'armazem_id' => $destino, 'quantidade' => $q,
                    'custo' => $s['custo_unitario'], 'data' => $data, 'referencia' => $ref, 'documento_tipo' => 'TRANSFERENCIA', 'documento_id' => $n, 'armazem_contraparte_id' => $origem]);
                $mov += 2;
            }

            return ['numero' => $numero, 'movimentos' => $mov];
        });
    }

    /**
     * @param  array<string, mixed>  $m  tipo, sentido, produto_id, armazem_id, quantidade, custo?, data, referencia, terceiro_id?, projeto_id?,
     *                                   documento_tipo?, documento_id?, armazem_contraparte_id?, permitir_negativo?, ignorar_inventario?
     * @return array{movimento: MovimentoInventario, custo_unitario: string}
     */
    public function movimentar(array $m): array
    {
        $quantidade = number_format((float) $m['quantidade'], 3, '.', '');
        if (bccomp($quantidade, '0', 3) <= 0) {
            throw new ErroNegocio('A quantidade do movimento de stock tem de ser positiva.', 'QUANTIDADE_INVALIDA', 422);
        }
        if (! in_array($m['sentido'], ['E', 'S'], true) || ! in_array($m['tipo'], self::TIPOS, true)) {
            throw new ErroNegocio('Tipo ou sentido do movimento inválido.', 'MOVIMENTO_INVALIDO', 422);
        }
        $armazemId = (int) $m['armazem_id'];
        Armazem::query()->findOrFail($armazemId);
        if (empty($m['ignorar_inventario']) && SessaoInventario::query()->where('armazem_id', $armazemId)->whereIn('estado', ['EM_CONTAGEM', 'REVISAO'])->exists()) {
            throw new ErroNegocio('O armazém tem um inventário em curso: os movimentos ficam suspensos até à sua conclusão ou anulação.', 'ARMAZEM_EM_INVENTARIO', 422);
        }

        return DB::transaction(function () use ($m, $quantidade, $armazemId) {
            // M4/M5: nenhum movimento de stock (ajustes, guias, POS, inventário, compras) num exercício encerrado
            $this->exercicios->exigirAbertoNaTransacao($this->contexto->obrigatorio(), substr((string) $m['data'], 0, 10));
            $produto = Produto::query()->lockForUpdate()->findOrFail($m['produto_id']);
            if (! $produto->movimenta_stock) {
                throw new ErroNegocio("O produto {$produto->codigo} não movimenta stock.", 'PRODUTO_SEM_STOCK', 422);
            }
            $linha = StockArmazem::query()->where('armazem_id', $armazemId)->where('produto_id', $produto->id)->lockForUpdate()->first()
                ?? StockArmazem::create(['armazem_id' => $armazemId, 'produto_id' => $produto->id, 'quantidade_stock' => 0]);
            $total = (string) ($produto->quantidade_stock ?? '0');
            $noArmazem = (string) ($linha->quantidade_stock ?? '0');
            $custoMedio = (string) ($produto->custo_medio ?? '0');

            if ($m['sentido'] === 'E') {
                $custo = (string) ($m['custo'] ?? $custoMedio);
                $novoTotal = bcadd($total, $quantidade, 3);
                // custo médio ponderado sobre o stock existente positivo
                $base = bccomp($total, '0', 3) > 0 ? $total : '0';
                $custoMedio = bccomp(bcadd($base, $quantidade, 3), '0', 3) > 0
                    ? bcdiv(bcadd(bcmul($base, $custoMedio, 8), bcmul($quantidade, $custo, 8), 8), bcadd($base, $quantidade, 3), 6) : $custo;
                $novoArmazem = bcadd($noArmazem, $quantidade, 3);
            } else {
                $custo = (string) ($m['custo'] ?? $custoMedio);
                if (empty($m['permitir_negativo']) && bccomp($noArmazem, $quantidade, 3) < 0) {
                    throw new ErroNegocio("Stock insuficiente de {$produto->codigo} no armazém (existe {$noArmazem}, pedido {$quantidade}).", 'STOCK_INSUFICIENTE', 422,
                        ['produto_id' => $produto->id, 'disponivel' => $noArmazem]);
                }
                $novoTotal = bcsub($total, $quantidade, 3);
                $novoArmazem = bcsub($noArmazem, $quantidade, 3);
                // E-STK-1: saída a custo explícito ≠ custo médio (estorno de uma recepção, quebra com custo próprio) — o valor do
                // stock que sobra passa a ser (q × cm − q_saída × custo); sem isto o stock valorizado deixava de bater com a 26
                if (isset($m['custo']) && bccomp($custo, $custoMedio, 6) !== 0 && bccomp($total, '0', 3) > 0 && bccomp($novoTotal, '0', 3) > 0) {
                    $restante = bcsub(bcmul($total, $custoMedio, 8), bcmul($quantidade, $custo, 8), 8);
                    $custoMedio = bccomp($restante, '0', 8) > 0 ? bcdiv($restante, $novoTotal, 6) : '0.000000';
                }
            }

            $linha->update(['quantidade_stock' => $novoArmazem]);
            $produto->forceFill(['quantidade_stock' => $novoTotal, 'custo_medio' => $custoMedio])->save();
            $movimento = MovimentoInventario::create([
                'produto_id' => $produto->id, 'armazem_id' => $armazemId, 'tipo' => $m['tipo'], 'sentido' => $m['sentido'], 'quantidade' => $quantidade, 'data' => $m['data'],
                'terceiro_id' => $m['terceiro_id'] ?? null, 'referencia' => mb_substr((string) $m['referencia'], 0, 255), 'projeto_id' => $m['projeto_id'] ?? null,
                'preco_unitario' => number_format((float) $custo, 2, '.', ''), 'valor' => number_format(round((float) bcmul($quantidade, $custo, 6), 2), 2, '.', ''),
                'custo_medio_apos' => $custoMedio, 'documento_tipo' => $m['documento_tipo'] ?? null, 'documento_id' => $m['documento_id'] ?? null,
                'armazem_contraparte_id' => $m['armazem_contraparte_id'] ?? null, 'criado_por' => Auth::user()?->nome_utilizador,
            ]);

            return ['movimento' => $movimento, 'custo_unitario' => $custo];
        });
    }
}
