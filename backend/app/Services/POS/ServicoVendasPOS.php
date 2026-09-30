<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\StockArmazem;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\Logistica\ServicoArmazens;
use App\Services\Vendas\CalculadoraDocumento;
use App\Services\Vendas\ServicoConfigVendas;
use App\Services\Vendas\ServicoDocumentosVenda;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Venda POS (criarVendaPOS / calcularPagamentos, js/pos_gestao.js:296-429): factura-recibo emitida pelo motor de vendas
 * (série do terminal, AGT, hash, stock e CMV — ADR-043), numa única transacção. Correcções face ao legado:
 *   - stock verificado no ARMAZÉM do terminal, com o saldo actual (o legado usava o stock global lido quando o ecrã abriu,
 *     perdia actualizações entre computadores e cortava negativos a zero);
 *   - saída ao custo médio (o legado gravava o preço de venda como custo) e CMV na integração da sessão;
 *   - totais do cabeçalho coerentes com o desconto (CalculadoraDocumento::calcularComIva);
 *   - cliente padrão do terminal usado (no legado era configurável mas ignorado);
 *   - operador = quem regista a venda.
 */
final class ServicoVendasPOS
{
    public function __construct(
        private readonly ServicoDocumentosVenda $documentos,
        private readonly ServicoArmazens $armazens,
        private readonly ServicoConfigVendas $configVendas,
    ) {}

    /**
     * @param  array{cliente_id?: ?int, linhas: list<array{produto_id: int, quantidade: float|string, preco_unitario?: float|string|null, descricao?: ?string}>,
     *               percentagem_desconto?: float|string, pagamentos: list<array{meio_id: string, valor: float|string, referencia?: ?string}>, observacoes?: ?string}  $d
     * @param  array<string, mixed>  $extra  colunas adicionais da venda (hotel, lavandaria)
     */
    public function vender(SessaoPOS $s, array $d, array $extra = []): Venda
    {
        return DB::transaction(function () use ($s, $d, $extra) {
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);   // o fecho espera pelas vendas em curso (e vice-versa)
            if ($s->estado !== 'ABERTA') {
                throw new ErroNegocio('A sessão de caixa já foi fechada. Abra uma nova sessão.', 'SESSAO_NAO_ABERTA', 422);
            }
            $t = TerminalPOS::query()->findOrFail($s->terminal_pos_id);
            if (! $t->ativo) {
                throw new ErroNegocio('O terminal está inactivo.', 'TERMINAL_INATIVO', 422);
            }
            $pct = number_format((float) ($d['percentagem_desconto'] ?? 0), 4, '.', '');
            if (bccomp($pct, '0', 4) < 0 || bccomp($pct, '100', 4) > 0) {
                throw new ErroNegocio('O desconto tem de estar entre 0 e 100 %.', 'DESCONTO_INVALIDO', 422);
            }
            $cliente = $this->cliente($d['cliente_id'] ?? null, $t);
            $armazem = $t->armazem_id ?: $this->armazens->garantirPredefinido()->id;
            $this->exigirStock($d['linhas'], $armazem);

            // total a cobrar (mesmo cálculo que a emissão) para validar os pagamentos antes de numerar
            $produtos = Produto::query()->whereIn('id', array_column($d['linhas'], 'produto_id'))->get()->keyBy('id');
            $total = CalculadoraDocumento::calcularComIva(array_map(fn ($l) => ['quantidade' => $l['quantidade'],
                'preco_unitario' => $l['preco_unitario'] ?? $produtos[$l['produto_id']]?->preco_unitario ?? 0,
                'taxa_imposto' => $produtos[$l['produto_id']]?->taxa_imposto ?? 0], $d['linhas']), $pct)['total_bruto'];
            [$pagamentos, $troco] = $this->pagamentos($t, $d['pagamentos'], $total);
            $bruto = array_reduce($d['linhas'], fn ($c, $l) => bcadd($c, CalculadoraDocumento::arredondar(bcmul((string) $l['quantidade'],
                (string) ($l['preco_unitario'] ?? $produtos[$l['produto_id']]?->preco_unitario ?? 0), 8)), 2), '0.00');
            $tipos = array_unique(array_column($pagamentos, 'tipo'));   // o legado gravava o rótulo «Misto (A + B)»; os meios ficam em pos_pagamentos

            return $this->documentos->emitir([
                'tipo_documento' => 'FR', 'cliente_id' => $cliente->id, 'data_emissao' => now()->toDateString(), 'linhas' => $d['linhas'],
                'armazem_id' => $armazem, 'observacoes' => $d['observacoes'] ?? null,
                'unidade_negocio_id' => $t->unidade_negocio_id, 'centro_custo_id' => $t->centro_custo_id, 'meio_pagamento' => count($tipos) === 1 ? $tipos[0] : 'MISTO',
                'pos' => ['origem_serie' => $t->codigo, 'percentagem_desconto' => $pct, 'colunas' => $extra + [
                    'sessao_pos_id' => $s->id, 'terminal_pos_id' => $t->id, 'codigo_terminal_pos' => $t->codigo, 'pos_pagamentos' => $pagamentos,
                    'pos_troco' => $troco, 'pos_operador' => Auth::user()?->nome_utilizador, 'desconto' => bcsub($bruto, $total, 2),
                ]],
            ]);
        });
    }

    /**
     * Pagamentos (calcularPagamentos, pos_gestao.js:296-322): vários meios activos do terminal; troco só em numerário;
     * TPA + transferências não excedem o total; transferência exige n.º de comprovativo. O valor gravado é líquido do troco.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function pagamentos(TerminalPOS $t, array $pagamentos, string $total): array
    {
        if (! $pagamentos) {
            throw new ErroNegocio('Indique o pagamento.', 'SEM_PAGAMENTOS', 422);
        }
        $recebido = $naoNumerario = $numerario = '0.00';
        $linhas = [];
        foreach ($pagamentos as $p) {
            $m = ServicoTerminaisPOS::meio($t, (string) $p['meio_id']);
            $valor = number_format((float) $p['valor'], 2, '.', '');
            if (bccomp($valor, '0', 2) <= 0) {
                throw new ErroNegocio("Valor inválido no pagamento por {$m['nome']}.", 'PAGAMENTO_INVALIDO', 422);
            }
            $ref = trim((string) ($p['referencia'] ?? '')) ?: null;
            if ($m['tipo'] === 'TRANSFERENCIA' && ! $ref) {
                throw new ErroNegocio("Indique o n.º do comprovativo da transferência ({$m['nome']}).", 'COMPROVATIVO_EM_FALTA', 422);
            }
            $recebido = bcadd($recebido, $valor, 2);
            $m['tipo'] === 'NUMERARIO' ? $numerario = bcadd($numerario, $valor, 2) : $naoNumerario = bcadd($naoNumerario, $valor, 2);
            $linhas[] = ['meio_id' => $m['id'], 'tipo' => $m['tipo'], 'nome' => $m['nome'], 'valor' => $valor, 'recebido' => $valor, 'referencia' => $ref,
                'conta_transitoria' => $m['conta_transitoria'], 'conta_liquidacao' => $m['conta_liquidacao'], 'codigo_tpa' => $m['codigo_tpa'] ?? null];
        }
        if (bccomp($naoNumerario, $total, 2) > 0) {
            throw new ErroNegocio('TPA e transferências não podem exceder o total: o troco só se dá em numerário.', 'PAGAMENTO_EXCEDE', 422);
        }
        if (bccomp($recebido, $total, 2) < 0) {
            throw new ErroNegocio("Pagamento insuficiente: recebido {$recebido}, total {$total}.", 'PAGAMENTO_INSUFICIENTE', 422, ['total' => $total, 'recebido' => $recebido]);
        }
        $troco = bcsub($recebido, $total, 2);
        // o troco sai do numerário (do último pagamento em numerário para trás)
        $falta = $troco;
        for ($i = count($linhas) - 1; $i >= 0 && bccomp($falta, '0', 2) > 0; $i--) {
            if ($linhas[$i]['tipo'] === 'NUMERARIO') {
                $abate = bccomp($linhas[$i]['valor'], $falta, 2) >= 0 ? $falta : $linhas[$i]['valor'];
                $linhas[$i]['valor'] = bcsub($linhas[$i]['valor'], $abate, 2);
                $falta = bcsub($falta, $abate, 2);
            }
        }

        return [array_values(array_filter($linhas, fn ($l) => bccomp($l['valor'], '0', 2) > 0)), $troco];
    }

    /** Cliente indicado → cliente padrão do terminal → «Consumidor Final» (criado se faltar, como o legado — ui_sales.js:6428-6450). */
    private function cliente(?int $id, TerminalPOS $t): Terceiro
    {
        if ($id ?? $t->cliente_padrao_id) {
            return Terceiro::query()->findOrFail($id ?? $t->cliente_padrao_id);
        }

        return Terceiro::query()->whereIn('nif', ['999999999', '99999999'])->whereIn('tipo', Terceiro::TIPOS_CLIENTE)->orderBy('id')->first()
            ?? Terceiro::query()->where('nome', 'ilike', 'consumidor final')->whereIn('tipo', Terceiro::TIPOS_CLIENTE)->orderBy('id')->first()
            ?? Terceiro::create(['nome' => 'Consumidor Final', 'nif' => '999999999', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => $this->configVendas->conta('clientes_default')]);
    }

    /** Mercadoria em stock no armazém do terminal (o legado recusava sem stock, mas pelo stock global). */
    private function exigirStock(array $linhas, int $armazem): void
    {
        $pedido = [];
        foreach ($linhas as $l) {
            $pedido[(int) $l['produto_id']] = bcadd($pedido[(int) $l['produto_id']] ?? '0', (string) $l['quantidade'], 3);
        }
        $produtos = Produto::query()->whereIn('id', array_keys($pedido))->where('movimenta_stock', true)->get();
        foreach ($produtos as $p) {
            $saldo = (string) (StockArmazem::query()->where('armazem_id', $armazem)->where('produto_id', $p->id)->lockForUpdate()->value('quantidade_stock') ?? '0');
            if (bccomp($saldo, $pedido[$p->id], 3) < 0) {
                throw new ErroNegocio("Stock insuficiente de {$p->codigo} no armazém do terminal (disponível {$saldo}).", 'STOCK_INSUFICIENTE', 422,
                    ['produto_id' => $p->id, 'disponivel' => $saldo, 'pedido' => $pedido[$p->id]]);
            }
        }
    }
}
