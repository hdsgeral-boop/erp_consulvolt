<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\CotacaoCompra;
use App\Models\EncomendaCompra;
use App\Models\ItemCompra;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Sistema\ServicoCambios;
use App\Services\Vendas\ServicoSeries;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pedido → proposta → adjudicação → encomenda (js/ui_compras_v2.js do legado), com as correcções:
 *   - numeração por série (PC/PP/EC) sem colisões — o legado dava às encomendas "ORD-" + 4 dígitos aleatórios;
 *   - adjudicação atómica e única: exercício verificado ANTES, estados verificados com bloqueio, as outras propostas
 *     ficam RECUSADAS (o legado permitia adjudicar duas vezes e deixava a proposta adjudicada sem encomenda);
 *   - a encomenda herda o projecto, o prazo de entrega e a moeda (câmbio reavaliado na data, salvo se manual);
 *   - anulações com motivo e rasto, nunca eliminação (o legado apagava cabeçalhos e deixava linhas órfãs).
 * O controlo orçamental (OrcControlo) chega com o módulo Orçamento.
 */
final class ServicoProcessoCompras
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoSeries $series,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoCambios $cambios,
        private readonly ServicoDeliberacaoCompras $deliberacao,
    ) {}

    // ───────────────────────── Pedidos ─────────────────────────

    /** @param  array{nome_requerente: string, data?: string, descricao?: ?string, data_entrega?: ?string, linhas: list<array{produto_id: int, quantidade: float|string, preco_unitario?: float|string|null, descricao?: ?string}>}  $d */
    public function criarPedido(array $d): PedidoCompra
    {
        $empresa = $this->contexto->obrigatorio();
        $data = substr($d['data'] ?? now()->toDateString(), 0, 10);
        $produtos = $this->produtos($d['linhas']);

        return DB::transaction(function () use ($d, $data, $empresa, $produtos) {
            $reserva = $this->series->reservar($empresa, 'PC', $data, false);
            $pedido = PedidoCompra::create([
                'numero_pedido' => $reserva['numero_documento'], 'nome_requerente' => $d['nome_requerente'], 'data' => $data.' '.now()->format('H:i:s'),
                'estado' => 'PENDENTE', 'descricao' => $d['descricao'] ?? null, 'data_entrega' => $d['data_entrega'] ?? null, 'observacoes' => $d['observacoes'] ?? null,
                'unidade_negocio_id' => $d['unidade_negocio_id'] ?? null, 'centro_custo_id' => $d['centro_custo_id'] ?? null, 'projeto_id' => $d['projeto_id'] ?? null,
                'criado_por' => Auth::user()?->nome_utilizador,
            ]);
            foreach ($d['linhas'] as $l) {
                $preco = number_format((float) ($l['preco_unitario'] ?? 0), 2, '.', '');
                ItemCompra::create(['tipo_documento_origem' => 'PEDIDO', 'pedido_compra_id' => $pedido->id, 'produto_id' => $l['produto_id'],
                    'descricao' => $l['descricao'] ?? $produtos[$l['produto_id']]->nome, 'quantidade' => (string) $l['quantidade'], 'preco_unitario' => $preco,
                    'total' => bcmul((string) $l['quantidade'], $preco, 2), 'projeto_id' => $d['projeto_id'] ?? null]);
            }
            $this->deliberacao->iniciar($pedido);

            return $pedido->refresh();
        });
    }

    public function anularPedido(PedidoCompra $pedido, string $motivo): PedidoCompra
    {
        return DB::transaction(function () use ($pedido, $motivo) {
            $pedido = PedidoCompra::query()->lockForUpdate()->findOrFail($pedido->id);
            if (! in_array($pedido->estado, ['PENDENTE', 'APROVADO', 'REJEITADO'], true)) {
                throw new ErroNegocio("Um pedido {$pedido->estado} não pode ser anulado (anule primeiro a encomenda).", 'PEDIDO_NAO_ANULAVEL', 422);
            }
            CotacaoCompra::query()->where('pedido_compra_id', $pedido->id)->whereIn('estado', ['PROPOSTA', 'PROPOSTA_ADJUDICACAO'])->update(['estado' => 'ANULADA']);
            $pedido->update(['estado' => 'ANULADO', 'anulado_em' => now(), 'motivo_anulacao' => $motivo]);

            return $pedido;
        });
    }

    // ───────────────────────── Propostas ─────────────────────────

    /**
     * @param  array{pedido_compra_id: int, fornecedor_id: int, referencia: string, data?: string, data_entrega?: ?string,
     *               codigo_moeda?: ?string, taxa_cambio?: ?float, linhas: list<array{item_pedido_id: int, preco_unitario: float|string, taxa_imposto?: float|string|null}>}  $d
     */
    public function criarProposta(array $d): CotacaoCompra
    {
        $empresa = $this->contexto->obrigatorio();
        $data = substr($d['data'] ?? now()->toDateString(), 0, 10);
        $pedido = PedidoCompra::query()->findOrFail($d['pedido_compra_id']);
        if ($pedido->estado !== 'APROVADO') {
            throw new ErroNegocio("Só se registam propostas para pedidos aprovados (este está {$pedido->estado}).", 'PEDIDO_NAO_APROVADO', 422);
        }
        $fornecedor = $this->fornecedor($d['fornecedor_id']);
        $moeda = CalculadoraCompra::moeda($this->cambios, $empresa, $d['codigo_moeda'] ?? $fornecedor->codigo_moeda, isset($d['taxa_cambio']) ? (string) $d['taxa_cambio'] : null, $data);

        $itensPedido = ItemCompra::query()->where('pedido_compra_id', $pedido->id)->with('produto')->get()->keyBy('id');
        $linhas = [];
        foreach ($d['linhas'] as $n => $l) {
            $ip = $itensPedido[$l['item_pedido_id']] ?? throw new ErroNegocio('Linha '.($n + 1).': não pertence ao pedido.', 'LINHA_INVALIDA', 422);
            if ((float) $l['preco_unitario'] < 0) {
                throw new ErroNegocio('Linha '.($n + 1).': o preço não pode ser negativo.', 'PRECO_INVALIDO', 422);
            }
            $linhas[] = ['item' => $ip, 'quantidade' => (string) $ip->quantidade, 'preco_unitario' => number_format((float) $l['preco_unitario'], 2, '.', ''),
                'taxa_imposto' => (string) ($l['taxa_imposto'] ?? $ip->produto?->taxa_imposto ?? 14)];
        }
        $calc = CalculadoraCompra::calcular($linhas, $moeda['taxa']);
        if (bccomp($calc['liquido'], '0', 2) <= 0) {
            throw new ErroNegocio('A proposta tem de ter valor (preços por linha).', 'PROPOSTA_SEM_VALOR', 422);
        }

        return DB::transaction(function () use ($d, $pedido, $fornecedor, $moeda, $linhas, $calc, $data, $empresa) {
            $reserva = $this->series->reservar($empresa, 'PP', $data, false);
            $cotacao = CotacaoCompra::create([
                'numero_proposta' => $reserva['numero_documento'], 'pedido_compra_id' => $pedido->id, 'fornecedor_id' => $fornecedor->id,
                'referencia' => $d['referencia'], 'data' => $data, 'data_entrega' => $d['data_entrega'] ?? null, 'estado' => 'PROPOSTA',
                'montante_total' => $calc['liquido_kz'], 'total_imposto' => $calc['imposto_kz'], 'total_com_imposto' => $calc['bruto_kz'],
                'codigo_moeda' => $moeda['codigo'], 'taxa_cambio' => $moeda['estrangeira'] ? $moeda['taxa'] : null, 'taxa_cambio_id' => $moeda['taxa_id'],
                'taxa_cambio_manual' => $moeda['manual'], 'montante_total_moeda' => $moeda['estrangeira'] ? $calc['liquido'] : null,
                'unidade_negocio_id' => $pedido->unidade_negocio_id, 'centro_custo_id' => $pedido->centro_custo_id,
            ]);
            foreach ($linhas as $i => $l) {
                ItemCompra::create($this->linha('COTACAO', ['cotacao_compra_id' => $cotacao->id], $l, $calc['linhas'][$i], $moeda) + ['projeto_id' => $l['item']->projeto_id]);
            }

            return $cotacao;
        });
    }

    /** Comparação das propostas (UC:2589-2825): score = preço (70) + prazo (30 − 3/dia de atraso). Indicativo. */
    public function comparar(PedidoCompra $pedido): array
    {
        $propostas = CotacaoCompra::query()->where('pedido_compra_id', $pedido->id)->where('estado', '<>', 'ANULADA')->with('fornecedor:id,nome')->get();
        $validas = $propostas->filter(fn ($p) => (float) $p->montante_total > 0);
        $minimo = $validas->min(fn ($p) => (float) $p->montante_total) ?: 0;
        $itensPedido = ItemCompra::query()->where('pedido_compra_id', $pedido->id)->orderBy('id')->get();
        $linhasPropostas = ItemCompra::query()->whereIn('cotacao_compra_id', $propostas->pluck('id'))->get()->groupBy('cotacao_compra_id');

        return [
            'propostas' => $propostas->map(function ($p) use ($minimo, $pedido) {
                $preco = (float) $p->montante_total > 0 ? round($minimo / (float) $p->montante_total * 70, 2) : 0;
                $prazo = 0;
                if ($p->data_entrega) {
                    $atraso = $pedido->data_entrega ? max(0, $pedido->data_entrega->diffInDays($p->data_entrega, false)) : 0;
                    $prazo = max(0, 30 - 3 * $atraso);
                }

                return ['id' => $p->id, 'numero_proposta' => $p->numero_proposta, 'referencia' => $p->referencia, 'fornecedor' => $p->fornecedor?->nome,
                    'estado' => $p->estado, 'montante_total' => $p->montante_total, 'total_com_imposto' => $p->total_com_imposto,
                    'codigo_moeda' => $p->codigo_moeda ?: 'AOA', 'data_entrega' => $p->data_entrega?->toDateString(),
                    'pontuacao' => ['preco' => $preco, 'prazo' => $prazo, 'total' => round($preco + $prazo, 2)]];
            })->sortByDesc('pontuacao.total')->values(),
            'matriz' => $itensPedido->map(fn ($ip) => ['item_pedido_id' => $ip->id, 'produto_id' => $ip->produto_id, 'descricao' => $ip->descricao, 'quantidade' => $ip->quantidade,
                'precos' => $propostas->mapWithKeys(function ($p) use ($linhasPropostas, $ip) {
                    $l = ($linhasPropostas[$p->id] ?? collect())->firstWhere('produto_id', $ip->produto_id);

                    return [$p->id => $l && (float) $l->preco_unitario > 0 ? $l->preco_unitario : null];   // null = não cotado
                })]),
        ];
    }

    /** Propor a adjudicação (avaliação). Só uma proposta por pedido fica PROPOSTA_ADJUDICACAO. */
    public function propor(CotacaoCompra $cotacao): CotacaoCompra
    {
        return DB::transaction(function () use ($cotacao) {
            $pedido = PedidoCompra::query()->lockForUpdate()->findOrFail($cotacao->pedido_compra_id);
            $cotacao = CotacaoCompra::query()->lockForUpdate()->findOrFail($cotacao->id);
            $this->exigirPedidoAdjudicavel($pedido);
            if ($cotacao->estado !== 'PROPOSTA') {
                throw new ErroNegocio("A proposta está {$cotacao->estado}.", 'PROPOSTA_ESTADO_INVALIDO', 422);
            }
            CotacaoCompra::query()->where('pedido_compra_id', $pedido->id)->where('estado', 'PROPOSTA_ADJUDICACAO')->update(['estado' => 'PROPOSTA']);
            $cotacao->update(['estado' => 'PROPOSTA_ADJUDICACAO']);

            return $cotacao;
        });
    }

    public function cancelarProposta(CotacaoCompra $cotacao): CotacaoCompra
    {
        if ($cotacao->estado !== 'PROPOSTA_ADJUDICACAO') {
            throw new ErroNegocio('Só se cancela uma proposta de adjudicação.', 'PROPOSTA_ESTADO_INVALIDO', 422);
        }
        $cotacao->update(['estado' => 'PROPOSTA']);

        return $cotacao;
    }

    public function anularProposta(CotacaoCompra $cotacao): CotacaoCompra
    {
        if (! in_array($cotacao->estado, ['PROPOSTA', 'PROPOSTA_ADJUDICACAO'], true)) {
            throw new ErroNegocio("Uma proposta {$cotacao->estado} não pode ser anulada.", 'PROPOSTA_ESTADO_INVALIDO', 422);
        }
        $cotacao->update(['estado' => 'ANULADA']);

        return $cotacao;
    }

    /**
     * Adjudica a proposta e gera a encomenda (aprovarAdjudicacao, UC:2084-2161). Se o valor exigir níveis de
     * deliberação ainda não aprovados, abre a revisão e recusa com REVISAO_DELIBERACAO (o pedido volta a PENDENTE).
     */
    public function adjudicar(CotacaoCompra $cotacao, ?string $dataEncomenda = null): EncomendaCompra
    {
        $empresa = $this->contexto->obrigatorio();
        $data = substr($dataEncomenda ?? now()->toDateString(), 0, 10);
        $this->exercicios->exigirAberto($empresa, $data);   // antes de qualquer gravação (o legado verificava depois)
        if ($cotacao->estado !== 'PROPOSTA_ADJUDICACAO') {
            throw new ErroNegocio('A proposta tem de estar proposta para adjudicação (segregação avaliar/adjudicar).', 'PROPOSTA_ESTADO_INVALIDO', 422);
        }
        $pedido = PedidoCompra::query()->findOrFail($cotacao->pedido_compra_id);
        $this->exigirPedidoAdjudicavel($pedido);
        if ($this->deliberacao->exigeRevisao($pedido, (string) $cotacao->montante_total, $cotacao->id)) {
            throw new ErroNegocio('O valor da proposta exige níveis de aprovação adicionais: o pedido voltou a deliberação.', 'REVISAO_DELIBERACAO', 409);
        }

        return DB::transaction(function () use ($cotacao, $pedido, $data, $empresa) {
            $pedido = PedidoCompra::query()->lockForUpdate()->findOrFail($pedido->id);
            $cotacao = CotacaoCompra::query()->lockForUpdate()->findOrFail($cotacao->id);
            $this->exigirPedidoAdjudicavel($pedido);
            if ($cotacao->estado !== 'PROPOSTA_ADJUDICACAO') {
                throw new ErroNegocio('A proposta já não está para adjudicação.', 'PROPOSTA_ESTADO_INVALIDO', 409);
            }
            $moeda = CalculadoraCompra::moeda($this->cambios, $empresa, $cotacao->codigo_moeda,
                $cotacao->taxa_cambio_manual ? (string) $cotacao->taxa_cambio : null, $data);
            $origem = ItemCompra::query()->where('cotacao_compra_id', $cotacao->id)->orderBy('id')->get()
                ->filter(fn ($l) => (float) ($moeda['estrangeira'] ? $l->preco_unitario_moeda : $l->preco_unitario) > 0)->values();
            $linhas = $origem->map(fn ($l) => ['item' => $l, 'quantidade' => (string) $l->quantidade,
                'preco_unitario' => (string) ($moeda['estrangeira'] ? $l->preco_unitario_moeda : $l->preco_unitario), 'taxa_imposto' => (string) ($l->taxa_imposto ?? 0)])->all();
            $calc = CalculadoraCompra::calcular($linhas, $moeda['taxa']);

            $reserva = $this->series->reservar($empresa, 'EC', $data, false);
            $encomenda = EncomendaCompra::create([
                'numero_encomenda' => $reserva['numero_documento'], 'pedido_compra_id' => $pedido->id, 'cotacao_compra_id' => $cotacao->id,
                'fornecedor_id' => $cotacao->fornecedor_id, 'data' => $data.' '.now()->format('H:i:s'), 'estado' => 'EM_PROCESSAMENTO', 'contabilizado' => false,
                'projeto_id' => $pedido->projeto_id, 'data_entrega_prevista' => $cotacao->data_entrega,
                'unidade_negocio_id' => $pedido->unidade_negocio_id, 'centro_custo_id' => $pedido->centro_custo_id,
                'codigo_moeda' => $moeda['codigo'], 'taxa_cambio' => $moeda['estrangeira'] ? $moeda['taxa'] : null, 'taxa_cambio_id' => $moeda['taxa_id'],
                'taxa_cambio_manual' => $moeda['manual'], 'montante_total' => $calc['liquido_kz'], 'montante_total_moeda' => $moeda['estrangeira'] ? $calc['liquido'] : null,
                'total_imposto' => $calc['imposto_kz'], 'total_com_imposto' => $calc['bruto_kz'],
            ]);
            foreach ($linhas as $i => $l) {
                ItemCompra::create($this->linha('ENCOMENDA', ['encomenda_compra_id' => $encomenda->id], $l, $calc['linhas'][$i], $moeda)
                    + ['projeto_id' => $l['item']->projeto_id ?? $pedido->projeto_id, 'quantidade_recebida' => 0, 'quantidade_faturada' => 0, 'valor_recebido_kz' => 0]);
            }
            $cotacao->update(['estado' => 'ADJUDICADO']);
            CotacaoCompra::query()->where('pedido_compra_id', $pedido->id)->where('id', '<>', $cotacao->id)
                ->whereIn('estado', ['PROPOSTA', 'PROPOSTA_ADJUDICACAO'])->update(['estado' => 'RECUSADA']);
            $pedido->update(['estado' => 'ADJUDICADO']);

            return $encomenda;
        });
    }

    /** Anula uma encomenda sem recepções nem facturas activas; a proposta e o pedido voltam a estar adjudicáveis. */
    public function anularEncomenda(EncomendaCompra $encomenda, string $motivo): EncomendaCompra
    {
        return DB::transaction(function () use ($encomenda, $motivo) {
            $encomenda = EncomendaCompra::query()->lockForUpdate()->findOrFail($encomenda->id);
            if ($encomenda->estado === 'ANULADA') {
                throw new ErroNegocio('A encomenda já está anulada.', 'JA_ANULADO', 422);
            }
            if ($encomenda->rececoesCompra()->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))->exists()
                || $encomenda->faturasCompra()->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))->exists()) {
                throw new ErroNegocio('A encomenda tem recepções ou facturas: anule-as primeiro.', 'ENCOMENDA_COM_DOCUMENTOS', 422);
            }
            $encomenda->update(['estado' => 'ANULADA', 'anulado_em' => now(), 'motivo_anulacao' => $motivo]);
            if ($encomenda->cotacao_compra_id) {
                CotacaoCompra::query()->whereKey($encomenda->cotacao_compra_id)->update(['estado' => 'PROPOSTA']);
                CotacaoCompra::query()->where('pedido_compra_id', $encomenda->pedido_compra_id)->where('estado', 'RECUSADA')->update(['estado' => 'PROPOSTA']);
            }
            if ($encomenda->pedido_compra_id) {
                PedidoCompra::query()->whereKey($encomenda->pedido_compra_id)->where('estado', 'ADJUDICADO')->update(['estado' => 'APROVADO']);
            }

            return $encomenda;
        });
    }

    /** Estado da encomenda a partir das quantidades recebidas (o legado nunca gravava PARCIAL). */
    public function recalcularEstadoEncomenda(EncomendaCompra $encomenda): void
    {
        if ($encomenda->estado === 'ANULADA') {
            return;
        }
        $linhas = ItemCompra::query()->where('encomenda_compra_id', $encomenda->id)->get();
        $total = $linhas->every(fn ($l) => bccomp((string) $l->quantidade_recebida, (string) $l->quantidade, 3) >= 0);
        $algum = $linhas->contains(fn ($l) => (float) $l->quantidade_recebida > 0);
        $encomenda->update(['estado' => $linhas->isNotEmpty() && $total ? 'RECEBIDO' : ($algum ? 'PARCIAL' : 'EM_PROCESSAMENTO')]);
    }

    public function fornecedor(int $id): Terceiro
    {
        $f = Terceiro::query()->findOrFail($id);
        if (! $f->eFornecedor() || ! $f->codigo_conta) {
            throw new ErroNegocio('O terceiro tem de ser fornecedor e ter conta contabilística.', 'FORNECEDOR_INVALIDO', 422);
        }

        return $f;
    }

    /** Colunas comuns de uma linha de compra a partir do cálculo. */
    public function linha(string $tipo, array $pai, array $l, array $c, array $moeda): array
    {
        $item = $l['item'] ?? null;

        return $pai + [
            'tipo_documento_origem' => $tipo, 'produto_id' => $item?->produto_id ?? $l['produto_id'], 'descricao' => $item?->descricao ?? $l['descricao'] ?? null,
            'quantidade' => $l['quantidade'], 'taxa_imposto' => $l['taxa_imposto'],
            'preco_unitario' => $c['preco_kz'], 'total' => $c['liquido_kz'], 'total_kz' => $c['liquido_kz'], 'imposto_kz' => $c['imposto_kz'],
            'imposto_moeda' => $moeda['estrangeira'] ? $c['imposto'] : null,
            'preco_unitario_moeda' => $moeda['estrangeira'] ? $l['preco_unitario'] : null, 'total_moeda' => $moeda['estrangeira'] ? $c['liquido'] : null,
        ];
    }

    private function exigirPedidoAdjudicavel(PedidoCompra $pedido): void
    {
        if ($pedido->estado !== 'APROVADO') {
            throw new ErroNegocio("O pedido está {$pedido->estado}: não é adjudicável.", 'PEDIDO_NAO_ADJUDICAVEL', 422);
        }
    }

    /** @return Collection<int, Produto> */
    private function produtos(array $linhas)
    {
        if (! $linhas) {
            throw new ErroNegocio('Indique pelo menos uma linha.', 'SEM_LINHAS', 422);
        }
        $produtos = Produto::query()->whereIn('id', array_column($linhas, 'produto_id'))->get()->keyBy('id');
        foreach ($linhas as $n => $l) {
            $p = $produtos[$l['produto_id']] ?? throw new ErroNegocio('Linha '.($n + 1).': produto inexistente.', 'PRODUTO_INEXISTENTE', 422);
            if ($p->bloqueado) {
                throw new ErroNegocio('Linha '.($n + 1).": o produto {$p->codigo} está bloqueado.", 'PRODUTO_BLOQUEADO', 422);
            }
            if ((float) $l['quantidade'] <= 0) {
                throw new ErroNegocio('Linha '.($n + 1).': a quantidade tem de ser positiva.', 'QUANTIDADE_INVALIDA', 422);
            }
        }

        return $produtos;
    }
}
