<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Models\SessaoInventario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Recalcular valorizações de stock» (tarefa armazem_recalcular do catálogo, ADR-064). Refaz, por produto e por ordem
 * cronológica (data, id), o custo médio ponderado e o valor das saídas a partir do histórico de movimentos, com as mesmas
 * fórmulas do ServicoStock (ADR-042). Serve sobretudo para os movimentos com data anterior a outros já registados (o motor
 * calcula o custo médio pela ordem de registo).
 *
 * Regras (o recálculo nunca altera valores já contabilizados):
 *   - por omissão é uma SIMULAÇÃO (nada é gravado); `aplicar` grava tudo numa única transacção, com os produtos bloqueados;
 *   - o recálculo começa depois do último movimento «fixo» do produto: os do legado (sem documento de origem) e o acerto
 *     da migração (MIGRACAO) são a verdade (ADR-042) e servem de ponto de partida (quantidade acumulada e custo médio);
 *   - as entradas mantêm o custo do seu documento; só as entradas de transferência seguem o custo recalculado da saída par;
 *   - as saídas valorizadas a um custo explícito (ex.: estorno de uma recepção) mantêm-no; as restantes passam ao custo
 *     médio recalculado — excepto se o documento de origem estiver contabilizado (venda, guia ou recepção contabilizadas,
 *     regularizações de inventário): essas ficam como estão e aparecem no relatório como divergências não aplicadas;
 *   - o custo médio após cada movimento (informativo, não contabilístico) e o custo médio actual do produto são actualizados;
 *   - com um inventário em curso a aplicação é recusada (a fotografia usa o custo médio).
 */
final class ServicoRecalculoStock
{
    private const ESCALA_CUSTO = 6;

    /** Documentos cujas saídas são sempre consideradas contabilizadas (a regularização é lançada na aprovação). */
    private const SEMPRE_CONTABILIZADOS = ['INVENTARIO', 'INVENTARIO_ANULACAO'];

    /** Documento de origem → tabela com a coluna `contabilizado`. */
    private const TABELAS_CONTABILIZADO = ['VENDA' => 'vendas', 'GUIA_SAIDA' => 'guias_saida', 'RECECAO' => 'rececoes_compra'];

    /**
     * @return array{aplicado: bool, resumo: array<string, mixed>, produtos: list<array<string, mixed>>, avisos: list<string>}
     */
    public function recalcular(?int $produtoId, bool $aplicar): array
    {
        if ($aplicar && SessaoInventario::query()->whereIn('estado', ['EM_CONTAGEM', 'REVISAO'])->exists()) {
            throw new ErroNegocio('Há um inventário em curso: conclua-o ou anule-o antes de aplicar o recálculo das valorizações.', 'ARMAZEM_EM_INVENTARIO', 422);
        }
        if ($produtoId) {
            Produto::query()->findOrFail($produtoId);
        }

        $executar = function () use ($produtoId, $aplicar) {
            $ids = MovimentoInventario::query()->when($produtoId, fn ($q, $v) => $q->where('produto_id', $v))
                ->distinct()->orderBy('produto_id')->pluck('produto_id');
            $contabilizados = $this->documentosContabilizados();
            $produtos = [];
            $avisos = [];
            $resumo = ['produtos_analisados' => 0, 'produtos_com_alteracoes' => 0, 'movimentos_alterados' => 0,
                'divergencias_contabilizadas' => 0, 'diferenca_valor' => '0.00'];

            foreach ($ids as $id) {
                $produto = Produto::withTrashed()->when($aplicar, fn ($q) => $q->lockForUpdate())->find($id);
                if (! $produto) {
                    continue;
                }
                $resumo['produtos_analisados']++;
                $r = $this->recalcularProduto($produto, $contabilizados, $aplicar, $avisos);
                if ($r === null) {
                    continue;
                }
                $resumo['movimentos_alterados'] += $r['movimentos_alterados'];
                $resumo['divergencias_contabilizadas'] += $r['divergencias_contabilizadas'];
                $resumo['diferenca_valor'] = bcadd($resumo['diferenca_valor'], $r['diferenca_valor'], 2);
                if ($r['movimentos_alterados'] || $r['divergencias_contabilizadas'] || $r['custo_medio_alterado']) {
                    $resumo['produtos_com_alteracoes']++;
                    $produtos[] = $r;
                }
            }

            return ['aplicado' => $aplicar, 'resumo' => $resumo, 'produtos' => $produtos, 'avisos' => $avisos];
        };

        return $aplicar ? DB::transaction($executar) : $executar();
    }

    /**
     * @param  array<string, array<int, true>|true>  $contabilizados
     * @param  list<string>  $avisos
     * @return array<string, mixed>|null
     */
    private function recalcularProduto(Produto $produto, array $contabilizados, bool $aplicar, array &$avisos): ?array
    {
        /** @var Collection<int, MovimentoInventario> $movs */
        $movs = MovimentoInventario::query()->where('produto_id', $produto->id)->orderBy('data')->orderBy('id')->get();
        if ($movs->contains(fn ($m) => ! in_array($m->sentido, ['E', 'S'], true))) {
            $avisos[] = "Produto {$produto->codigo}: há movimentos sem sentido (E/S) no histórico; não foi recalculado.";

            return null;
        }

        // Ponto de partida: depois do último movimento fixo (legado sem documento de origem ou acerto da migração).
        $ultimoFixo = -1;
        foreach ($movs->values() as $i => $m) {
            if ($m->documento_tipo === null || $m->documento_tipo === 'MIGRACAO') {
                $ultimoFixo = $i;
            }
        }
        $lista = $movs->values();
        $qtd = '0.000';
        for ($i = 0; $i <= $ultimoFixo; $i++) {
            $qtd = $this->somar($qtd, $lista[$i]);
        }
        $janela = $lista->slice($ultimoFixo + 1)->values();
        if ($janela->isEmpty()) {
            return null;
        }
        $cm = $this->custoInicial($ultimoFixo >= 0 ? $lista[$ultimoFixo] : null, $janela->first(), $qtd);

        $custoSaidaTransferencia = [];
        $alterados = 0;
        $divergencias = 0;
        $diferenca = '0.00';
        $detalhe = [];
        foreach ($janela as $m) {
            $q = (string) $m->quantidade;
            $valorNovo = (string) $m->valor;
            $precoNovo = (string) $m->preco_unitario;
            $contab = $this->contabilizado($m, $contabilizados);
            if ($m->sentido === 'E') {
                if ($m->tipo === 'TRANSFERENCIA' && isset($custoSaidaTransferencia[$this->chaveTransferencia($m, true)])) {
                    $custo = $custoSaidaTransferencia[$this->chaveTransferencia($m, true)];
                    [$precoNovo, $valorNovo] = $this->valorizar($q, $custo);
                } else {
                    $custo = $this->custoEntrada($m);
                }
                $base = bccomp($qtd, '0', 3) > 0 ? $qtd : '0';
                $cm = bccomp(bcadd($base, $q, 3), '0', 3) > 0
                    ? bcdiv(bcadd(bcmul($base, $cm, 8), bcmul($q, $custo, 8), 8), bcadd($base, $q, 3), self::ESCALA_CUSTO) : $custo;
            } else {
                // saída ao custo médio (a não ser que tenha sido valorizada a um custo explícito)
                $aoCustoMedio = $m->custo_medio_apos === null || bccomp((string) $m->preco_unitario, $this->arredondar((string) $m->custo_medio_apos), 2) === 0;
                if ($aoCustoMedio) {
                    [$precoNovo, $valorNovo] = $this->valorizar($q, $cm);
                } elseif (bccomp($qtd, '0', 3) > 0 && bccomp(bcsub($qtd, $q, 3), '0', 3) > 0) {
                    // E-STK-1: saída a custo explícito — o custo médio do que sobra é (q × cm − valor da saída) / (q − q_saída)
                    $restante = bcsub(bcmul($qtd, $cm, 8), (string) $m->valor, 8);
                    $cm = bccomp($restante, '0', 8) > 0 ? bcdiv($restante, bcsub($qtd, $q, 3), self::ESCALA_CUSTO) : '0';
                }
                if ($m->tipo === 'TRANSFERENCIA') {
                    $custoSaidaTransferencia[$this->chaveTransferencia($m, false)] = $aoCustoMedio ? $cm : (string) $m->preco_unitario;
                }
            }
            $qtd = $this->somar($qtd, $m);

            $mudaValor = bccomp($valorNovo, (string) $m->valor, 2) !== 0;
            $mudaCm = $m->custo_medio_apos === null || bccomp($cm, (string) $m->custo_medio_apos, self::ESCALA_CUSTO) !== 0;
            if ($mudaValor && $contab) {
                $divergencias++;
                $detalhe[] = $this->linhaDetalhe($m, $valorNovo, $precoNovo, $cm, true);
                $valorNovo = (string) $m->valor;
                $precoNovo = (string) $m->preco_unitario;
                $mudaValor = false;
            }
            if (! $mudaValor && ! $mudaCm) {
                continue;
            }
            if ($mudaValor) {
                $alterados++;
                $diferenca = bcadd($diferenca, bcsub($valorNovo, (string) $m->valor, 2), 2);
                $detalhe[] = $this->linhaDetalhe($m, $valorNovo, $precoNovo, $cm, false);
            }
            if ($aplicar) {
                $m->forceFill(['valor' => $valorNovo, 'preco_unitario' => $precoNovo, 'custo_medio_apos' => $cm])->save();
            }
        }

        $cmAtual = (string) ($produto->custo_medio ?? '0');
        $cmAlterado = bccomp($cm, $cmAtual, self::ESCALA_CUSTO) !== 0;
        if ($aplicar && $cmAlterado) {
            $produto->forceFill(['custo_medio' => $cm])->save();
        }

        return [
            'produto_id' => $produto->id, 'codigo' => $produto->codigo, 'nome' => $produto->nome,
            'custo_medio_atual' => $cmAtual, 'custo_medio_recalculado' => $cm, 'custo_medio_alterado' => $cmAlterado,
            'movimentos_recalculados' => $janela->count(), 'movimentos_alterados' => $alterados, 'divergencias_contabilizadas' => $divergencias,
            'diferenca_valor' => $diferenca, 'movimentos' => array_slice($detalhe, 0, 200),
        ];
    }

    /**
     * Custo médio no início da janela: o custo médio após o último movimento fixo; sem ele (legado), o custo médio antes
     * do primeiro movimento da janela, deduzido do que lá ficou gravado.
     */
    private function custoInicial(?MovimentoInventario $fixo, MovimentoInventario $primeiro, string $qtd): string
    {
        if ($fixo?->custo_medio_apos !== null) {
            return bcadd((string) $fixo->custo_medio_apos, '0', self::ESCALA_CUSTO);
        }
        $apos = (string) ($primeiro->custo_medio_apos ?? '0');
        if ($primeiro->sentido === 'S') {
            return bcadd($apos, '0', self::ESCALA_CUSTO);
        }
        $base = bccomp($qtd, '0', 3) > 0 ? $qtd : '0';
        if (bccomp($base, '0', 3) === 0) {
            return bcadd($apos, '0', self::ESCALA_CUSTO);
        }
        $q = (string) $primeiro->quantidade;
        $inicial = bcdiv(bcsub(bcmul($apos, bcadd($base, $q, 3), 8), bcmul($q, $this->custoEntrada($primeiro), 8), 8), $base, self::ESCALA_CUSTO);

        return bccomp($inicial, '0', self::ESCALA_CUSTO) < 0 ? '0.000000' : $inicial;
    }

    /** Custo unitário de uma entrada: o preço gravado quando reproduz o valor; senão valor ÷ quantidade. */
    private function custoEntrada(MovimentoInventario $m): string
    {
        $q = (string) $m->quantidade;
        [, $valor] = $this->valorizar($q, (string) $m->preco_unitario);
        if (bccomp($valor, (string) $m->valor, 2) === 0 || bccomp($q, '0', 3) === 0) {
            return bcadd((string) $m->preco_unitario, '0', self::ESCALA_CUSTO);
        }

        return bcdiv((string) $m->valor, $q, self::ESCALA_CUSTO);
    }

    /** @return array{0: string, 1: string} preço unitário (2 casas) e valor, com o arredondamento do ServicoStock */
    private function valorizar(string $quantidade, string $custo): array
    {
        return [number_format((float) $custo, 2, '.', ''), number_format(round((float) bcmul($quantidade, $custo, 6), 2), 2, '.', '')];
    }

    private function arredondar(string $v): string
    {
        return number_format(round((float) $v, 2), 2, '.', '');
    }

    private function somar(string $qtd, MovimentoInventario $m): string
    {
        return $m->sentido === 'E' ? bcadd($qtd, (string) $m->quantidade, 3) : bcsub($qtd, (string) $m->quantidade, 3);
    }

    /** Liga a entrada de uma transferência à saída par (mesmo número, mesmo produto, armazéns trocados). */
    private function chaveTransferencia(MovimentoInventario $m, bool $entrada): string
    {
        $origem = $entrada ? $m->armazem_contraparte_id : $m->armazem_id;
        $destino = $entrada ? $m->armazem_id : $m->armazem_contraparte_id;

        return "{$m->documento_id}|{$origem}|{$destino}|".$m->data?->toDateString();
    }

    /** @param  array<string, array<int, true>|true>  $contabilizados */
    private function contabilizado(MovimentoInventario $m, array $contabilizados): bool
    {
        if (in_array($m->documento_tipo, self::SEMPRE_CONTABILIZADOS, true)) {
            return true;
        }

        return $m->documento_id !== null && isset($contabilizados[$m->documento_tipo][$m->documento_id]);
    }

    /** @return array<string, array<int, true>> ids dos documentos de origem contabilizados, por tipo */
    private function documentosContabilizados(): array
    {
        $out = [];
        foreach (self::TABELAS_CONTABILIZADO as $tipo => $tabela) {
            $ids = MovimentoInventario::query()->where('documento_tipo', $tipo)->whereNotNull('documento_id')->distinct()->pluck('documento_id');
            $out[$tipo] = $ids->isEmpty() ? [] : array_fill_keys(
                DB::table($tabela)->whereIn('id', $ids)->where('contabilizado', true)->pluck('id')->map(fn ($v) => (int) $v)->all(), true);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function linhaDetalhe(MovimentoInventario $m, string $valorNovo, string $precoNovo, string $cm, bool $contabilizado): array
    {
        return [
            'movimento_id' => $m->id, 'data' => $m->data?->toDateString(), 'tipo' => $m->tipo, 'sentido' => $m->sentido, 'armazem_id' => $m->armazem_id,
            'documento_tipo' => $m->documento_tipo, 'documento_id' => $m->documento_id, 'quantidade' => (string) $m->quantidade,
            'valor_atual' => (string) $m->valor, 'valor_recalculado' => $valorNovo, 'preco_recalculado' => $precoNovo, 'custo_medio_apos' => $cm,
            'contabilizado' => $contabilizado,
        ];
    }
}
