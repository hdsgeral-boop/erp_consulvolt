<?php

namespace App\Services\Tesouraria;

use App\Models\CentroCusto;
use App\Models\DocumentoTesouraria;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\ReconciliacaoBancaria;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Listagem dos documentos de tesouraria com as colunas do sistema anterior (renderMovimentosTesouraria e
 * renderAccountingHistory, js/ui_tesouraria.js): contrapartidas, U. negócio / C. custo, D/C, entidade (NIF), doc. origem,
 * notas (D/F), estado de reconciliação bancária e compensação — verificação visual 7.1.1 e 7.4.1.
 *
 * Tudo em consultas agregadas por página (itens, terceiros, UN, CC, notas, linhas reconciliadas, reconciliações): o
 * número de consultas não depende do número de documentos (sem N+1). O isolamento por empresa é o global scope
 * PertenceEmpresa de cada model.
 */
final class ServicoListagemTesouraria
{
    /** Códigos de reconciliação bancária (o resto — REC_/AUTO/TRF_/MATC… — são compensações de terceiros). */
    private const PREFIXO_BANCO = '/^(REC|MAN|DFT)-/';

    /**
     * @param  Collection<int, DocumentoTesouraria>  $docs
     * @return list<array<string, mixed>>
     */
    public function enriquecer(Collection $docs): array
    {
        if ($docs->isEmpty()) {
            return [];
        }
        $itens = ItemDocumentoTesouraria::query()->whereIn('documento_tesouraria_id', $docs->pluck('id'))
            ->with(['terceiro' => fn ($q) => $q->withTrashed()->select(['id', 'nome', 'nif'])])
            ->orderBy('id')
            ->get(['id', 'documento_tesouraria_id', 'codigo_conta', 'tipo_dc', 'valor', 'terceiro_id', 'nif_importado', 'numero_documento',
                'data_documento_original', 'unidade_negocio_id', 'centro_custo_id', 'nota_demonstracao_id', 'nota_fluxo_caixa_id', 'descricao'])
            ->groupBy('documento_tesouraria_id');
        $todos = $itens->flatten(1);
        $un = $this->codigos(UnidadeNegocio::class, $todos->pluck('unidade_negocio_id'));
        $cc = $this->codigos(CentroCusto::class, $todos->pluck('centro_custo_id'));
        $nd = $this->codigos(NotaDemonstracao::class, $todos->pluck('nota_demonstracao_id'));
        $nf = $this->codigos(NotaFluxoCaixa::class, $todos->pluck('nota_fluxo_caixa_id'));
        $reconciliacoes = $this->reconciliacoes($docs);

        return $docs->map(function (DocumentoTesouraria $d) use ($itens, $un, $cc, $nd, $nf, $reconciliacoes) {
            $linhas = $itens->get($d->id, collect());
            $entidades = [];
            foreach ($linhas as $l) {
                $nome = $l->terceiro?->nome ? trim((string) $l->terceiro->nome) : null;
                $nif = $l->terceiro?->nif ?: ($l->nif_importado ?: null);
                if ($nome === null && $nif === null) {
                    continue;
                }
                $entidades[($nome ?? '').'|'.($nif ?? '')] = ['nome' => $nome, 'nif' => $nif];
            }
            $rec = $reconciliacoes[$d->id] ?? ['banco' => null, 'compensacao' => null];

            return $d->toArray() + [
                'contrapartidas' => $linhas->map(fn ($l) => [
                    'codigo_conta' => $l->codigo_conta, 'tipo_dc' => $l->tipo_dc, 'valor' => $l->valor,
                    'unidade_negocio' => $un[$l->unidade_negocio_id] ?? null, 'centro_custo' => $cc[$l->centro_custo_id] ?? null,
                    'nota_demonstracao' => $nd[$l->nota_demonstracao_id] ?? null, 'nota_fluxo_caixa' => $nf[$l->nota_fluxo_caixa_id] ?? null,
                    'numero_documento' => $l->numero_documento, 'data_documento_original' => $l->data_documento_original ? substr((string) $l->data_documento_original, 0, 10) : null,
                    'descricao' => $l->descricao,
                ])->values()->all(),
                'entidades' => array_values($entidades),
                'reconciliacao_bancaria' => $rec['banco'],
                'compensacao' => $rec['compensacao'],
            ];
        })->values()->all();
    }

    /** Contagens por estado para os indicadores «Pendentes» / «Integrados» dos agregadores (updateGlobalTesoSummary). */
    public function resumo(): array
    {
        $n = DocumentoTesouraria::query()->selectRaw('estado, count(*) as n')->groupBy('estado')->pluck('n', 'estado');

        return ['pendentes' => (int) ($n['PENDENTE'] ?? 0), 'integrados' => (int) ($n['INTEGRADO'] ?? 0), 'anulados' => (int) ($n['ANULADO'] ?? 0)];
    }

    /**
     * @param  class-string<Model>  $modelo
     * @return array<int, string>
     */
    private function codigos(string $modelo, Collection $ids): array
    {
        $ids = $ids->filter()->unique()->values();

        return $ids->isEmpty() ? [] : $modelo::query()->whereIn('id', $ids)->pluck('codigo', 'id')->all();
    }

    /**
     * Reconciliação de cada documento: códigos das linhas activas do lançamento da integração (n.º do lançamento) e o
     * código guardado no próprio documento (documentos do legado). Banco = reconciliação CONCILIADO_BANCO (ou código
     * REC-/MAN-/DFT-); o resto, não anulado, é compensação.
     *
     * @param  Collection<int, DocumentoTesouraria>  $docs
     * @return array<int, array{banco: ?array<string, mixed>, compensacao: ?array<string, mixed>}>
     */
    private function reconciliacoes(Collection $docs): array
    {
        $lans = $docs->pluck('numero_lan_contabilizacao')->filter()->unique()->values();
        $porLan = $lans->isEmpty() ? collect() : LancamentoContabil::query()->whereIn('numero_lan', $lans)->whereNotNull('reconciliacao_codigo')
            ->where('reconciliacao_codigo', '<>', '')->whereNull('estornado_por_id')->whereNull('estorno_de_id')
            ->get(['numero_lan', 'reconciliacao_codigo'])->groupBy('numero_lan')->map(fn ($g) => $g->pluck('reconciliacao_codigo')->unique()->values());
        $codigosPorDoc = [];
        foreach ($docs as $d) {
            $c = collect($porLan->get($d->numero_lan_contabilizacao, []));
            if ($d->reconciliacao_codigo) {
                $c->push($d->reconciliacao_codigo);
            }
            $codigosPorDoc[$d->id] = $c->unique()->values();
        }
        $todos = collect($codigosPorDoc)->flatten()->unique()->values();
        $registos = $todos->isEmpty() ? collect() : ReconciliacaoBancaria::query()->whereIn('reconciliacao_codigo', $todos)
            ->get(['reconciliacao_codigo', 'estado', 'data'])->keyBy('reconciliacao_codigo');

        $r = [];
        foreach ($codigosPorDoc as $id => $codigos) {
            $banco = $compensacao = null;
            foreach ($codigos as $codigo) {
                $reg = $registos->get($codigo);
                if ($reg && $reg->estado === 'ANULADA') {
                    continue;
                }
                $info = ['codigo' => $codigo, 'estado' => $reg?->estado, 'data' => $reg?->data?->toDateString()];
                if (($reg && $reg->estado === 'CONCILIADO_BANCO') || preg_match(self::PREFIXO_BANCO, (string) $codigo)) {
                    $banco ??= $info;
                } else {
                    $compensacao ??= $info;
                }
            }
            $r[$id] = ['banco' => $banco, 'compensacao' => $compensacao];
        }

        return $r;
    }
}
