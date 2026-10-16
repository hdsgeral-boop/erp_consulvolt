<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\AlocacaoAquisicaoAtivo;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\LancamentoContabil;
use Illuminate\Support\Facades\DB;

/**
 * Aquisições pendentes de inventariação (renderPendingAcquisitions, openAssetCreationModalFromJournal, finalizeAssetCreation,
 * linkUnlinkedAssetsToJournal/saveUnlinkedAssetsLinks, fixMissingAssetSuppliers — js/ui_assets.js:448-713, 2745-2766,
 * 2814-2923, 3279-3391): linhas a débito em contas 11/12 cujo valor ainda não está coberto por activos ligados.
 * Regras do legado: a data de aquisição é a do lançamento e o fornecedor o terceiro da linha; residual 0; estado ACTIVO;
 * inventariação parcial permitida.
 * Correcções:
 *   - o valor inventariado não pode exceder o valor por inventariar da linha (o legado só perguntava e deixava gravar a mais,
 *     o que duplicava o imobilizado face à contabilidade); o mesmo ao ligar activos existentes (o legado não verificava);
 *   - linhas estornadas (e os próprios estornos) não contam como aquisições;
 *   - o fornecedor em falta é preenchido no momento da ligação (o legado corria a reparação sempre que abria a lista, ADR-015);
 *   - inventariação combinada («Inventariar Seleccionados (Combinar)», js/ui_assets.js:2784, 3208-3217, 595-606, 733-745):
 *     o legado somava o restante das linhas seleccionadas (de qualquer conta 11/12), abria o mesmo modal com esse total,
 *     tirava data e fornecedor da primeira linha, gravava journal_line_id = "ID1,ID2" em cada activo e dava todas as linhas
 *     como totalmente consumidas (allocationMap = 999999999, :2761-2769), mesmo com inventário parcial. Aqui o valor de cada
 *     activo reparte-se pelas linhas, por ordem (data, id), em alocacoes_aquisicao_ativos: cada linha fica coberta só pelo
 *     que foi realmente inventariado e o total não pode exceder a soma dos restantes. A FK do activo aponta para a primeira
 *     linha que ele cobre.
 */
final class ServicoAquisicoesAtivos
{
    public function __construct(private readonly ServicoAtivos $ativos) {}

    /** @return array{linhas: list<array>, total_por_inventariar: string} */
    public function pendentes(): array
    {
        $alocado = $this->alocadoPorLinha();
        $linhas = [];
        $total = '0.00';
        foreach ($this->linhasAquisicao()->with(['diario:id,codigo', 'terceiro:id,nome'])->orderBy('data_documento')->orderBy('id')->get() as $l) {
            $inventariado = $alocado[$l->id]['v'] ?? '0.00';
            $restante = bcsub(CalculadoraAmortizacoes::d($l->valor), $inventariado, 2);
            if (bccomp($restante, '0.01', 2) < 0) {
                continue;
            }
            $total = bcadd($total, $restante, 2);
            $linhas[] = $l->only(['id', 'numero_lan', 'numero_documento', 'data_documento', 'codigo_conta', 'descricao', 'valor', 'terceiro_id', 'unidade_negocio_id', 'centro_custo_id'])
                + ['diario' => $l->diario?->codigo, 'terceiro' => $l->terceiro?->nome, 'inventariado' => $inventariado, 'por_inventariar' => $restante,
                    'ativos' => $alocado[$l->id]['n'] ?? 0, 'estado' => bccomp($inventariado, '0', 2) > 0 ? 'PARCIAL' : 'PENDENTE'];
        }

        return ['linhas' => $linhas, 'total_por_inventariar' => $total];
    }

    /** Cria os activos de uma linha de aquisição (finalizeAssetCreation). */
    public function inventariar(int $linhaId, array $itens): array
    {
        return DB::transaction(function () use ($linhaId, $itens) {
            [$linha, $restante] = $this->linha($linhaId);
            $total = $this->validarItens($itens, $restante, 'o valor por inventariar da linha');
            $criados = [];
            foreach (array_values($itens) as $i => $item) {
                $a = $this->criarAtivo($item, $i, $linha);
                $a->update(['lancamento_contabil_id' => $linha->id]);
                $criados[] = $a->refresh();
            }

            return ['ativos' => $criados, 'por_inventariar' => bcsub($restante, $total, 2)];
        });
    }

    /**
     * Inventaria várias linhas de aquisição em conjunto (inventorySelectedPendingMovements + finalizeAssetCreation com
     * "ID1,ID2"). Data de aquisição e fornecedor da primeira linha (data, id); o valor de cada activo reparte-se pelas
     * linhas por essa ordem.
     *
     * @param  list<int>  $linhaIds
     * @return array{ativos: list<AtivoImobilizado>, por_inventariar: string, linhas: list<array{id: int, por_inventariar: string}>}
     */
    public function combinar(array $linhaIds, array $itens): array
    {
        $ids = array_values(array_unique(array_map('intval', $linhaIds)));
        if (count($ids) < 2) {
            throw new ErroNegocio('Seleccione pelo menos duas linhas para inventariar em conjunto.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($ids, $itens) {
            // bloqueio por id (ordem estável entre pedidos concorrentes); depois ordena-se como a lista (data, id)
            $linhas = $this->linhasAquisicao()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($linhas->count() !== count($ids)) {
                throw new ErroNegocio('Há linhas seleccionadas que não são aquisições de imobilizado activas (débito numa conta 11/12, não estornada).',
                    'LINHA_NAO_AQUISICAO', 422, ['linhas' => array_values(array_diff($ids, $linhas->modelKeys()))]);
            }
            $linhas = $linhas->sortBy([fn ($a, $b) => [$a->data_documento?->toDateString(), $a->id] <=> [$b->data_documento?->toDateString(), $b->id]])->values();
            $alocado = $this->alocadoPorLinha($ids);
            $fila = [];
            $semRestante = [];
            foreach ($linhas as $l) {
                $r = bcsub(CalculadoraAmortizacoes::d($l->valor), $alocado[$l->id]['v'] ?? '0.00', 2);
                if (bccomp($r, '0.01', 2) < 0) {
                    $semRestante[] = $l->numero_documento ?: "linha {$l->id}";
                }
                $fila[] = ['linha' => $l, 'restante' => $r];
            }
            if ($semRestante) {
                throw new ErroNegocio('Há linhas sem valor por inventariar: '.implode(', ', $semRestante).'.', 'LINHA_SEM_RESTANTE', 422, ['linhas' => $semRestante]);
            }
            $disponivel = array_reduce($fila, fn ($s, $f) => bcadd($s, $f['restante'], 2), '0.00');
            $total = $this->validarItens($itens, $disponivel, 'a soma do valor por inventariar das linhas');

            $primeira = $linhas->first();
            $criados = [];
            $k = 0;
            foreach (array_values($itens) as $i => $item) {
                $a = $this->criarAtivo($item, $i, $primeira);
                $falta = CalculadoraAmortizacoes::d($a->valor_aquisicao);
                $fk = null;
                while (bccomp($falta, '0', 2) > 0) {
                    $parte = bccomp($falta, $fila[$k]['restante'], 2) <= 0 ? $falta : $fila[$k]['restante'];
                    AlocacaoAquisicaoAtivo::create(['ativo_imobilizado_id' => $a->id, 'lancamento_contabil_id' => $fila[$k]['linha']->id, 'valor' => $parte]);
                    $fk ??= $fila[$k]['linha']->id;
                    $fila[$k]['restante'] = bcsub($fila[$k]['restante'], $parte, 2);
                    $falta = bcsub($falta, $parte, 2);
                    if (bccomp($fila[$k]['restante'], '0', 2) === 0) {
                        $k++;
                    }
                }
                // item de valor 0: liga à linha corrente, sem alocação (conta 0 pela FK)
                $a->update(['lancamento_contabil_id' => $fk ?? $fila[min($k, count($fila) - 1)]['linha']->id]);
                $criados[] = $a->refresh();
            }

            return ['ativos' => $criados, 'por_inventariar' => bcsub($disponivel, $total, 2),
                'linhas' => array_map(fn ($f) => ['id' => $f['linha']->id, 'por_inventariar' => $f['restante']], $fila)];
        });
    }

    /** Liga activos já existentes sem lançamento de compra a uma linha (saveUnlinkedAssetsLinks). */
    public function ligar(int $linhaId, array $ids): array
    {
        return DB::transaction(function () use ($linhaId, $ids) {
            [$linha, $restante] = $this->linha($linhaId);
            $ativos = AtivoImobilizado::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($ativos->count() !== count(array_unique($ids))) {
                throw new ErroNegocio('Há activos seleccionados que não existem.', 'DADOS_INVALIDOS', 422);
            }
            if ($ligados = $ativos->whereNotNull('lancamento_contabil_id')->pluck('codigo')->values()->all()) {
                throw new ErroNegocio('Há activos já ligados a um lançamento de compra: '.implode(', ', $ligados).'.', 'ATIVO_JA_LIGADO', 422, ['ativos' => $ligados]);
            }
            $total = $ativos->reduce(fn ($s, $a) => bcadd($s, CalculadoraAmortizacoes::d($a->valor_aquisicao), 2), '0.00');
            if (bccomp($total, $restante, 2) > 0) {
                throw new ErroNegocio("O valor dos activos ({$total}) excede o valor por inventariar da linha ({$restante}).", 'EXCEDE_AQUISICAO', 422,
                    ['por_inventariar' => $restante, 'seleccionado' => $total]);
            }
            foreach ($ativos as $a) {
                $a->update(['lancamento_contabil_id' => $linha->id, 'fornecedor_id' => $a->fornecedor_id ?? $linha->terceiro_id]);
            }

            return ['ativos' => $ativos->count(), 'por_inventariar' => bcsub($restante, $total, 2)];
        });
    }

    /** Activos sem lançamento de compra associado (candidatos à ligação). */
    public function semLancamento(): array
    {
        $ativos = AtivoImobilizado::query()->whereNull('lancamento_contabil_id')->orderBy('codigo')
            ->get(['id', 'codigo', 'descricao', 'valor_aquisicao', 'data_aquisicao', 'categoria_ativo_id', 'estado']);
        $categorias = CategoriaAtivo::withTrashed()->whereIn('id', $ativos->pluck('categoria_ativo_id')->filter()->unique()->values()->all())->pluck('nome', 'id');

        // com o nome da categoria (ADR-064)
        return $ativos->map(fn ($a) => $a->toArray() + ['categoria_nome' => $categorias[$a->categoria_ativo_id] ?? null])->values()->all();
    }

    /**
     * Valor inventariado e n.º de activos por linha de aquisição: activos ligados pela FK sem alocações (pelo valor de
     * aquisição) mais as alocações dos activos combinados. Activos eliminados não contam.
     *
     * @param  list<int>|null  $ids
     * @return array<int, array{v: string, n: int}>
     */
    public function alocadoPorLinha(?array $ids = null): array
    {
        $porFk = AtivoImobilizado::query()->whereNotNull('lancamento_contabil_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('alocacoes_aquisicao_ativos as x')->whereColumn('x.ativo_imobilizado_id', 'ativos_imobilizados.id'))
            ->when($ids !== null, fn ($q) => $q->whereIn('lancamento_contabil_id', $ids))
            ->groupBy('lancamento_contabil_id')->selectRaw('lancamento_contabil_id AS l, SUM(valor_aquisicao) AS v, COUNT(*) AS n')->toBase()->get();
        $porAlocacao = AtivoImobilizado::query()->join('alocacoes_aquisicao_ativos as x', 'x.ativo_imobilizado_id', '=', 'ativos_imobilizados.id')
            ->when($ids !== null, fn ($q) => $q->whereIn('x.lancamento_contabil_id', $ids))
            ->groupBy('x.lancamento_contabil_id')->selectRaw('x.lancamento_contabil_id AS l, SUM(x.valor) AS v, COUNT(*) AS n')->toBase()->get();
        $r = [];
        foreach ($porFk->concat($porAlocacao) as $x) {
            $atual = $r[(int) $x->l] ?? ['v' => '0.00', 'n' => 0];
            $r[(int) $x->l] = ['v' => bcadd($atual['v'], CalculadoraAmortizacoes::d($x->v), 2), 'n' => $atual['n'] + (int) $x->n];
        }

        return $r;
    }

    /** Valida o total e os códigos dos itens face ao disponível; devolve o total. */
    private function validarItens(array $itens, string $disponivel, string $rotulo): string
    {
        $total = array_reduce($itens, fn ($s, $i) => bcadd($s, CalculadoraAmortizacoes::d($i['valor_aquisicao'] ?? 0), 2), '0.00');
        if (bccomp($total, $disponivel, 2) > 0) {
            throw new ErroNegocio("O valor inventariado ({$total}) excede {$rotulo} ({$disponivel}).", 'EXCEDE_AQUISICAO', 422,
                ['por_inventariar' => $disponivel, 'inventariado' => $total]);
        }
        $codigos = array_filter(array_map(fn ($i) => mb_strtolower(trim((string) ($i['codigo'] ?? ''))), $itens));
        if (count($codigos) !== count(array_unique($codigos))) {
            throw new ErroNegocio('Existem números de inventário repetidos nos itens.', 'CODIGO_DUPLICADO', 422);
        }

        return $total;
    }

    /** Cria um activo a partir de um item do formulário, com a data de aquisição e o fornecedor da linha. */
    private function criarAtivo(array $item, int $i, LancamentoContabil $linha): AtivoImobilizado
    {
        try {
            return $this->ativos->guardar(array_intersect_key($item, array_flip(['codigo', 'descricao', 'categoria_ativo_id', 'valor_aquisicao', 'vida_util', 'vida_util_restante',
                'amortizacao_acumulada_inicial', 'unidade_negocio_id', 'centro_custo_id'])) + [
                    'data_aquisicao' => $linha->data_documento->toDateString(), 'fornecedor_id' => $linha->terceiro_id, 'valor_residual' => 0,
                ]);
        } catch (ErroNegocio $e) {
            throw new ErroNegocio('Item '.($i + 1).': '.$e->getMessage(), $e->codigo, 422, ['item' => $i + 1] + $e->detalhes);
        }
    }

    /** @return array{0: LancamentoContabil, 1: string} linha bloqueada e valor por inventariar */
    private function linha(int $id): array
    {
        $linha = $this->linhasAquisicao()->whereKey($id)->lockForUpdate()->first()
            ?? throw new ErroNegocio('A linha não é uma aquisição de imobilizado activa (débito numa conta 11/12, não estornada).', 'LINHA_NAO_AQUISICAO', 422);

        return [$linha, bcsub(CalculadoraAmortizacoes::d($linha->valor), $this->alocadoPorLinha([$id])[$id]['v'] ?? '0.00', 2)];
    }

    private function linhasAquisicao()
    {
        return LancamentoContabil::query()->where('tipo_dc', 'D')->where(fn ($q) => $q->where('codigo_conta', 'like', '11%')->orWhere('codigo_conta', 'like', '12%'))
            ->whereNull('estorno_de_id')->whereNull('estornado_por_id');
    }
}
