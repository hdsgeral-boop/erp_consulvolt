<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
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
 *   - a inventariação de várias linhas num só grupo ("ID1,ID2" em journal_line_id) não é suportada: a ligação é uma FK para
 *     uma linha (ver ADR-051); inventaria-se linha a linha.
 */
final class ServicoAquisicoesAtivos
{
    public function __construct(private readonly ServicoAtivos $ativos) {}

    /** @return array{linhas: list<array>, total_por_inventariar: string} */
    public function pendentes(): array
    {
        $alocado = AtivoImobilizado::query()->whereNotNull('lancamento_contabil_id')->groupBy('lancamento_contabil_id')
            ->selectRaw('lancamento_contabil_id, SUM(valor_aquisicao) AS v, COUNT(*) AS n')->get()->keyBy('lancamento_contabil_id');
        $linhas = [];
        $total = '0.00';
        foreach ($this->linhasAquisicao()->with(['diario:id,codigo', 'terceiro:id,nome'])->orderBy('data_documento')->orderBy('id')->get() as $l) {
            $inventariado = CalculadoraAmortizacoes::d($alocado[$l->id]->v ?? '0');
            $restante = bcsub(CalculadoraAmortizacoes::d($l->valor), $inventariado, 2);
            if (bccomp($restante, '0.01', 2) < 0) {
                continue;
            }
            $total = bcadd($total, $restante, 2);
            $linhas[] = $l->only(['id', 'numero_lan', 'numero_documento', 'data_documento', 'codigo_conta', 'descricao', 'valor', 'terceiro_id', 'unidade_negocio_id', 'centro_custo_id'])
                + ['diario' => $l->diario?->codigo, 'terceiro' => $l->terceiro?->nome, 'inventariado' => $inventariado, 'por_inventariar' => $restante,
                    'ativos' => (int) ($alocado[$l->id]->n ?? 0), 'estado' => bccomp($inventariado, '0', 2) > 0 ? 'PARCIAL' : 'PENDENTE'];
        }

        return ['linhas' => $linhas, 'total_por_inventariar' => $total];
    }

    /** Cria os activos de uma linha de aquisição (finalizeAssetCreation). */
    public function inventariar(int $linhaId, array $itens): array
    {
        return DB::transaction(function () use ($linhaId, $itens) {
            [$linha, $restante] = $this->linha($linhaId);
            $total = array_reduce($itens, fn ($s, $i) => bcadd($s, CalculadoraAmortizacoes::d($i['valor_aquisicao'] ?? 0), 2), '0.00');
            if (bccomp($total, $restante, 2) > 0) {
                throw new ErroNegocio("O valor inventariado ({$total}) excede o valor por inventariar da linha ({$restante}).", 'EXCEDE_AQUISICAO', 422,
                    ['por_inventariar' => $restante, 'inventariado' => $total]);
            }
            $codigos = array_filter(array_map(fn ($i) => mb_strtolower(trim((string) ($i['codigo'] ?? ''))), $itens));
            if (count($codigos) !== count(array_unique($codigos))) {
                throw new ErroNegocio('Existem números de inventário repetidos nos itens.', 'CODIGO_DUPLICADO', 422);
            }
            $criados = [];
            foreach (array_values($itens) as $i => $item) {
                try {
                    $a = $this->ativos->guardar(array_intersect_key($item, array_flip(['codigo', 'descricao', 'categoria_ativo_id', 'valor_aquisicao', 'vida_util', 'vida_util_restante',
                        'amortizacao_acumulada_inicial', 'unidade_negocio_id', 'centro_custo_id'])) + [
                            'data_aquisicao' => $linha->data_documento->toDateString(), 'fornecedor_id' => $linha->terceiro_id, 'valor_residual' => 0,
                        ]);
                } catch (ErroNegocio $e) {
                    throw new ErroNegocio('Item '.($i + 1).': '.$e->getMessage(), $e->codigo, 422, ['item' => $i + 1] + $e->detalhes);
                }
                $a->update(['lancamento_contabil_id' => $linha->id]);
                $criados[] = $a->refresh();
            }

            return ['ativos' => $criados, 'por_inventariar' => bcsub($restante, $total, 2)];
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

    /** @return array{0: LancamentoContabil, 1: string} linha bloqueada e valor por inventariar */
    private function linha(int $id): array
    {
        $linha = $this->linhasAquisicao()->whereKey($id)->lockForUpdate()->first()
            ?? throw new ErroNegocio('A linha não é uma aquisição de imobilizado activa (débito numa conta 11/12, não estornada).', 'LINHA_NAO_AQUISICAO', 422);
        $alocado = (string) (AtivoImobilizado::query()->where('lancamento_contabil_id', $id)->sum('valor_aquisicao') ?: '0');

        return [$linha, bcsub(CalculadoraAmortizacoes::d($linha->valor), CalculadoraAmortizacoes::d($alocado), 2)];
    }

    private function linhasAquisicao()
    {
        return LancamentoContabil::query()->where('tipo_dc', 'D')->where(fn ($q) => $q->where('codigo_conta', 'like', '11%')->orWhere('codigo_conta', 'like', '12%'))
            ->whereNull('estorno_de_id')->whereNull('estornado_por_id');
    }
}
