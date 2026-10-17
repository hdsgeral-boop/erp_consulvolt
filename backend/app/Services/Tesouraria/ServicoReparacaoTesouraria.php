<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\DocumentoTesouraria;
use App\Models\ItemDocumentoTesouraria;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Consulta;
use Illuminate\Support\Facades\DB;

/**
 * «Reparar Inconsistências» e «Resgatar Documento» do sistema anterior (repairtreasuryInconsistencies / manualRescueDocument,
 * js/ui_tesouraria.js:1183-1367), só para a Tesouraria e como operação SEGURA:
 *
 *   - Inconsistência = documento INTEGRADO sem nenhum lançamento activo da integração no Diário (o lançamento foi
 *     estornado directamente na Contabilidade, ou não existe — documentos do legado). Esse documento já não se consegue
 *     desintegrar (não há lançamento para estornar) e fica preso.
 *   - Pré-visualização (GET) lista o que seria alterado, sem gravar nada; a execução (POST) recebe os ids escolhidos e um
 *     motivo, volta a verificar cada documento dentro da sua transacção (com bloqueio) e só então o repõe PENDENTE —
 *     o mesmo estado que a desintegração deixaria, incluindo o «pago» das vendas/facturas ligadas (−1), mas SEM tocar
 *     no Diário (não há lançamento a estornar). Exercício do documento tem de estar aberto. Auditado.
 *   - Não se apagam documentos nem reconciliações, e nada é feito noutros módulos (no legado também repunha vendas,
 *     compras e salários: no sistema novo esses módulos têm a sua própria descontabilização por estorno).
 */
final class ServicoReparacaoTesouraria
{
    public const LIMITE = 500;

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoLiquidacoes $liquidacoes,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * Documentos integrados sem lançamento activo (pré-visualização; não grava).
     *
     * @return array{documentos: list<array<string, mixed>>, total: int, truncado: bool}
     */
    public function integracoesOrfas(?string $pesquisa = null): array
    {
        $q = $this->orfas(DocumentoTesouraria::query())
            ->when($pesquisa, fn ($x, $v) => $x->where(fn ($y) => $y->where('numero_documento', $v)->orWhere('referencia', $v)
                ->orWhere('numero_documento', 'ilike', '%'.$this->semCuringas($v).'%')->orWhere('referencia', 'ilike', '%'.$this->semCuringas($v).'%')));
        $total = (clone $q)->count();
        $docs = $q->orderByDesc('data_documento')->orderByDesc('id')->limit(self::LIMITE)
            ->get(['id', 'tipo', 'data_documento', 'conta_financeira', 'descricao', 'valor_total', 'referencia', 'numero_documento', 'numero_lan_contabilizacao', 'integrado_em']);
        $estornados = $this->lancamentosEstornados($docs->pluck('numero_lan_contabilizacao')->filter()->unique()->values()->all());

        return [
            'documentos' => $docs->map(fn (DocumentoTesouraria $d) => [
                'id' => $d->id, 'tipo' => $d->tipo, 'data_documento' => $d->data_documento?->toDateString(), 'conta_financeira' => $d->conta_financeira,
                'descricao' => $d->descricao, 'valor_total' => $d->valor_total, 'referencia' => $d->referencia, 'numero_documento' => $d->numero_documento,
                'numero_lan_contabilizacao' => $d->numero_lan_contabilizacao,
                'motivo' => ! $d->numero_lan_contabilizacao ? 'LEGADO_SEM_LIGACAO'
                    : (isset($estornados[$d->numero_lan_contabilizacao]) ? 'LANCAMENTO_ESTORNADO' : 'LANCAMENTO_INEXISTENTE'),
            ])->values()->all(),
            'total' => $total,
            'truncado' => $total > self::LIMITE,
        ];
    }

    /**
     * Repõe PENDENTE os documentos indicados que continuem inconsistentes; cada um na sua transacção.
     *
     * @param  list<int>  $ids
     * @return array{repostos: list<array<string, mixed>>, erros: list<array<string, mixed>>}
     */
    public function repor(array $ids, string $motivo, bool $confirmarLegado = false): array
    {
        $empresa = $this->contexto->obrigatorio();
        $repostos = [];
        $erros = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            try {
                $repostos[] = DB::transaction(function () use ($id, $motivo, $empresa, $confirmarLegado) {
                    $doc = DocumentoTesouraria::query()->lockForUpdate()->find($id)
                        ?? throw new ErroNegocio('Documento inexistente.', 'DOCUMENTO_INEXISTENTE', 422);
                    if ($doc->estado !== 'INTEGRADO') {
                        throw new ErroNegocio("O documento está {$doc->estado}: nada a reparar.", 'DOCUMENTO_NAO_INTEGRADO', 422);
                    }
                    if (! $this->orfas(DocumentoTesouraria::query()->whereKey($doc->id))->exists()) {
                        throw new ErroNegocio('O lançamento da integração existe: use «Anular» (desintegração por estorno).', 'LANCAMENTO_EXISTE', 422);
                    }
                    // documento do legado sem n.º de lançamento: a ausência de ligação não prova que não foi lançado (ex.: folha
                    // de caixa agregada) — só com confirmação explícita, depois de verificado no Razão
                    if (! $doc->numero_lan_contabilizacao && ! $confirmarLegado) {
                        throw new ErroNegocio('Documento do sistema anterior sem ligação verificável ao Diário: confirme no Razão da conta e repita com a confirmação de documentos do legado.',
                            'CONFIRMAR_LEGADO', 422);
                    }
                    $this->exercicios->exigirAbertoNaTransacao($empresa, $doc->data_documento->toDateString());
                    $antes = ['estado' => $doc->estado, 'numero_lan_contabilizacao' => $doc->numero_lan_contabilizacao,
                        'integrado_em' => $doc->integrado_em?->toIso8601String(), 'integrado_por' => $doc->integrado_por];
                    $doc->update(['estado' => 'PENDENTE', 'numero_lan_contabilizacao' => null, 'integrado_em' => null, 'integrado_por' => null]);
                    // o mesmo efeito da desintegração nas vendas/facturas liquidadas (o «pago» que a integração somou)
                    $this->liquidacoes->aplicar(ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->get()
                        ->map(fn ($i) => $i->only(['venda_id', 'fatura_compra_id', 'tipo_dc', 'valor']))->all(), -1);
                    $this->auditoria->registar('Tesouraria', 'Reparou integração sem lançamento',
                        mb_substr("Documento {$this->numero($doc)} reposto por integrar (lançamento {$antes['numero_lan_contabilizacao']} inexistente ou estornado). Motivo: {$motivo}", 0, 1000),
                        'documentos_tesouraria', $doc->id, $antes, ['estado' => 'PENDENTE', 'numero_lan_contabilizacao' => null, 'integrado_em' => null, 'integrado_por' => null]);

                    return ['id' => $doc->id, 'numero_documento' => $this->numero($doc)];
                });
            } catch (ErroNegocio $e) {
                $erros[] = ['id' => $id, 'codigo' => $e->codigo, 'mensagem' => $e->getMessage()];
            }
        }

        return ['repostos' => $repostos, 'erros' => $erros];
    }

    /** Integrados para os quais não há NENHUMA linha activa do lançamento (pelo n.º do lançamento ou, no legado, pelo n.º/referência). */
    private function orfas(Builder $q): Builder
    {
        if (ServicoRecebimentosVendas::activo()) {   // os recebimentos de recibos de venda seguem o recibo: não se repõem aqui
            $q->whereNull('recibo_venda_id');
        }

        return $q->where('estado', 'INTEGRADO')->whereNotExists(function (Consulta $s) {
            $s->selectRaw('1')->from('lancamentos_contabeis as l')
                ->whereColumn('l.empresa_id', 'documentos_tesouraria.empresa_id')
                ->whereNull('l.estorno_de_id')->whereNull('l.estornado_por_id')
                ->where(fn (Consulta $x) => $x
                    ->where(fn (Consulta $a) => $a->whereNotNull('documentos_tesouraria.numero_lan_contabilizacao')->whereColumn('l.numero_lan', 'documentos_tesouraria.numero_lan_contabilizacao'))
                    // documentos do legado (sem n.º de lançamento): qualquer lançamento com o n.º, a referência ou TES-<id>, ou
                    // (conservador) qualquer movimento da conta financeira com a mesma data e o mesmo valor — na dúvida, não é órfão
                    ->orWhere(fn (Consulta $b) => $b->whereNull('documentos_tesouraria.numero_lan_contabilizacao')
                        ->where(fn (Consulta $c) => $c->whereColumn('l.numero_documento', 'documentos_tesouraria.numero_documento')
                            ->orWhereColumn('l.numero_documento', 'documentos_tesouraria.referencia')
                            ->orWhereRaw("l.numero_documento = 'TES-' || documentos_tesouraria.id")
                            ->orWhere(fn (Consulta $v) => $v->whereColumn('l.codigo_conta', 'documentos_tesouraria.conta_financeira')
                                ->whereColumn('l.data_documento', 'documentos_tesouraria.data_documento')->whereColumn('l.valor', 'documentos_tesouraria.valor_total')))));
        });
    }

    /** @return array<string, true> n.º de lançamento => existe estornado */
    private function lancamentosEstornados(array $lans): array
    {
        if (! $lans) {
            return [];
        }

        return DB::table('lancamentos_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->whereIn('numero_lan', $lans)
            ->whereNotNull('estornado_por_id')->distinct()->pluck('numero_lan')->flip()->map(fn () => true)->all();
    }

    private function numero(DocumentoTesouraria $d): string
    {
        return $d->numero_documento ?: ($d->referencia ?: "TES-{$d->id}");
    }

    private function semCuringas(string $v): string
    {
        return addcslashes($v, '%_\\');
    }
}
