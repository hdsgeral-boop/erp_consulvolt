<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\DocumentoTesouraria;
use App\Models\ItemDocumentoTesouraria;
use App\Models\ItemReciboVenda;
use App\Models\ReciboVenda;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Vendas\ServicoConfigVendas;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recebimentos de Vendas na Tesouraria (ronda 3, r3_teso). No sistema anterior o dinheiro recebido de clientes entrava
 * na Tesouraria como documento RECEBIMENTO (treasury_documents com a referência do documento de venda e a contrapartida
 * de cliente — ui_sales.js:7593-7628 na venda a dinheiro, :7949-7950 no hotel, pos_prestacao.js:545) e era daí que as
 * listas «Recebimentos», «Todos os registos», Integração e Histórico o mostravam (ui_tesouraria.js:340-430). O recibo de
 * cliente (RE) do legado ia directo ao diário (postReceiptToAccounting, ui_sales.js:4906-5005) e por isso não aparecia na
 * Tesouraria — é o que o utilizador pede para corrigir.
 *
 * Regra (uma só fonte de lançamento): cada recibo de venda (RE, adiantamento e o recibo da factura-recibo) tem um
 * documento de tesouraria RECEBIMENTO ligado (documentos_tesouraria.recibo_venda_id) que ESPELHA o recibo:
 *   - recibo por contabilizar → PENDENTE; contabilizado → INTEGRADO com o n.º do lançamento do recibo; anulado → ANULADO;
 *   - o espelho nunca cria lançamentos: «Integrar» na Tesouraria contabiliza o RECIBO (ServicoContabilizacaoVendas) e
 *     «Desintegrar» descontabiliza-o (estorno); editar e anular fazem-se no recibo (Vendas › Recibos);
 *   - as linhas não têm venda_id (a factura já foi actualizada pelo recibo; ligar a venda contaria o pago duas vezes):
 *     levam o terceiro, a conta de cliente e o n.º da factura — servem a lista e mantêm os pendentes «em curso».
 * Só para contas de disponibilidade da Tesouraria (42/43/45 ou cartão); outras contas da classe 4 ficam de fora (sem espelho).
 * As vendas do POS não têm recibo: os seus recebimentos já entram pela prestação de contas (ADR-048).
 */
final class ServicoRecebimentosVendas
{
    /** Contas de disponibilidade que têm espelho na Tesouraria, por prefixo (além das contas-cartão, marcadas na conta). */
    public const PREFIXOS = ['42', '43', '45'];

    public function __construct(private readonly ServicoConfigVendas $configVendas, private readonly ServicoContasCartao $contasCartao) {}

    /** A coluna de ligação existe (migração 2026_10_17_094200)? Sem ela nada é feito (instalações por migrar). */
    public static function activo(): bool
    {
        static $existe = null;

        return $existe ??= Schema::hasColumn('documentos_tesouraria', 'recibo_venda_id');
    }

    public function eContaTesouraria(?string $conta): bool
    {
        $conta = trim((string) $conta);

        return $conta !== '' && (in_array(substr($conta, 0, 2), self::PREFIXOS, true) || $this->contasCartao->ehCodigoCartao($conta));
    }

    /** Recibo de venda que gerou o documento (null se o documento é da Tesouraria). */
    public function reciboDe(DocumentoTesouraria $doc): ?ReciboVenda
    {
        $id = self::activo() ? ($doc->getAttributes()['recibo_venda_id'] ?? null) : null;

        return $id ? ReciboVenda::query()->find($id) : null;
    }

    /**
     * Cria ou actualiza o documento de tesouraria do recibo (idempotente; chamado na transacção de quem mexe no recibo).
     * Nunca lança: um recibo cuja conta não é de tesouraria simplesmente não tem espelho.
     */
    public function sincronizar(ReciboVenda $recibo): ?DocumentoTesouraria
    {
        if (! self::activo()) {
            return null;
        }

        return DB::transaction(function () use ($recibo) {
            $recibo = ReciboVenda::query()->find($recibo->id) ?? $recibo;
            $doc = DocumentoTesouraria::query()->where('recibo_venda_id', $recibo->id)->lockForUpdate()->first();
            if (! $doc && ! $this->eContaTesouraria($recibo->codigo_conta)) {
                return null;
            }
            $cliente = Terceiro::query()->withTrashed()->find($recibo->cliente_id);
            $estado = $recibo->estado === 'ANULADO' ? 'ANULADO' : ($recibo->contabilizado ? 'INTEGRADO' : 'PENDENTE');
            $dados = [
                'tipo' => 'RECEBIMENTO', 'data_documento' => $recibo->data?->toDateString(), 'conta_financeira' => trim((string) $recibo->codigo_conta),
                'descricao' => mb_substr($this->descricao($recibo, $cliente), 0, 1000), 'valor_total' => number_format((float) $recibo->montante_total, 2, '.', ''),
                'referencia' => mb_substr((string) ($recibo->referencia_pagamento ?: $recibo->numero_recibo), 0, 100) ?: null,
                'estado' => $estado, 'codigo_moeda' => 'AOA', 'projeto_id' => $recibo->projeto_id, 'codigo_projeto' => $recibo->codigo_projeto,
                'numero_lan_contabilizacao' => $estado === 'INTEGRADO' ? $recibo->numero_lan_contabilizacao : ($estado === 'ANULADO' ? $doc?->numero_lan_contabilizacao : null),
                'integrado_em' => $estado === 'INTEGRADO' ? ($doc?->integrado_em ?? now()) : null,
                'integrado_por' => $estado === 'INTEGRADO' ? ($doc?->integrado_por ?? auth()->user()?->nome_utilizador) : null,
                'anulado_em' => $estado === 'ANULADO' ? ($recibo->anulado_em ?? now()) : null,
                'motivo_anulacao' => $estado === 'ANULADO' ? $recibo->motivo_anulacao : null,
                'anulado_por' => $estado === 'ANULADO' ? mb_substr((string) ($recibo->getAttributes()['anulado_por'] ?? ''), 0, 100) ?: null : null,
            ];
            if ($doc) {
                $doc->forceFill($dados)->save();
            } else {
                $numero = (string) $recibo->numero_recibo;
                $livre = $numero !== '' && ! DocumentoTesouraria::query()->where('numero_documento', $numero)->exists();
                $doc = new DocumentoTesouraria;
                $doc->forceFill($dados + ['numero_documento' => $livre ? mb_substr($numero, 0, 50) : null, 'importado' => false,
                    'recibo_venda_id' => $recibo->id, 'empresa_id' => $recibo->empresa_id])->save();
            }
            $this->linhas($doc, $recibo, $cliente);

            return $doc->refresh();
        });
    }

    /**
     * «Integrar» um recebimento de recibo na Tesouraria = contabilizar o recibo (o lançamento é o do recibo: D disponibilidade
     * / C cliente). O recibo da factura-recibo contabiliza-se com a própria FR.
     */
    public function integrar(DocumentoTesouraria $doc): DocumentoTesouraria
    {
        return DB::transaction(function () use ($doc) {
            $doc = DocumentoTesouraria::query()->lockForUpdate()->findOrFail($doc->id);
            $recibo = $this->exigirRecibo($doc);
            if ($doc->estado !== 'PENDENTE') {
                throw new ErroNegocio("O documento está {$doc->estado}.", 'DOCUMENTO_NAO_INTEGRAVEL', 422);
            }
            if ($recibo->venda_origem_id) {
                throw new ErroNegocio("O recebimento {$recibo->numero_recibo} é da factura-recibo: contabilize a factura-recibo em Vendas › Facturação.", 'RECIBO_DE_FR', 422);
            }
            if (! $recibo->contabilizado) {
                app(ServicoContabilizacaoVendas::class)->contabilizarRecibo($recibo);   // sincroniza o espelho
            }
            $this->sincronizar($recibo);

            return $doc->refresh();
        });
    }

    /** «Desintegrar» = descontabilizar o recibo (estorno do lançamento do recibo; as mesmas regras de Vendas). */
    public function desintegrar(DocumentoTesouraria $doc, string $motivo): DocumentoTesouraria
    {
        return DB::transaction(function () use ($doc, $motivo) {
            $doc = DocumentoTesouraria::query()->lockForUpdate()->findOrFail($doc->id);
            $recibo = $this->exigirRecibo($doc);
            if ($doc->estado !== 'INTEGRADO') {
                throw new ErroNegocio('O documento não está integrado.', 'DOCUMENTO_NAO_INTEGRADO', 422);
            }
            if ($recibo->contabilizado) {
                app(ServicoContabilizacaoVendas::class)->descontabilizarRecibo($recibo, $motivo);
            }
            $this->sincronizar($recibo);

            return $doc->refresh();
        });
    }

    /** Editar ou anular na Tesouraria um recebimento de recibo: recusado (faz-se no recibo). */
    public function exigirNaoEspelho(DocumentoTesouraria $doc, string $accao): void
    {
        if ($recibo = $this->reciboDe($doc)) {
            throw new ErroNegocio("Recebimento gerado pelo recibo {$recibo->numero_recibo}: {$accao} em Vendas › Recibos (o documento de tesouraria acompanha o recibo).",
                'DOCUMENTO_RECIBO_VENDA', 422, ['recibo_venda_id' => $recibo->id, 'numero_recibo' => $recibo->numero_recibo]);
        }
    }

    /**
     * Reconciliação dos recibos anteriores sem documento de tesouraria (idempotente). Com $simular nada é gravado.
     *
     * @return array{simulacao: bool, recibos: int, criados: int, sem_conta_tesouraria: int, por_estado: array<string, int>, erros: list<array{recibo: string, mensagem: string}>}
     */
    public function reconciliar(bool $simular = true, int $limite = 0): array
    {
        $r = ['simulacao' => $simular, 'recibos' => 0, 'criados' => 0, 'sem_conta_tesouraria' => 0, 'por_estado' => ['PENDENTE' => 0, 'INTEGRADO' => 0, 'ANULADO' => 0], 'erros' => []];
        if (! self::activo()) {
            throw new ErroNegocio('Falta a migração 2026_10_17_094200 (documentos_tesouraria.recibo_venda_id).', 'MIGRACAO_EM_FALTA', 422);
        }
        $q = ReciboVenda::query()->whereNotExists(fn ($s) => $s->selectRaw('1')->from('documentos_tesouraria as d')
            ->whereColumn('d.recibo_venda_id', 'recibos_venda.id'))->orderBy('id');
        $limite > 0 && $q->limit($limite);
        foreach ($q->get() as $recibo) {
            $r['recibos']++;
            if (! $this->eContaTesouraria($recibo->codigo_conta)) {
                $r['sem_conta_tesouraria']++;

                continue;
            }
            try {
                DB::beginTransaction();
                try {
                    $doc = $this->sincronizar($recibo);
                    if ($doc) {
                        $r['criados']++;
                        $r['por_estado'][$doc->estado] = ($r['por_estado'][$doc->estado] ?? 0) + 1;
                    }
                    $simular ? DB::rollBack() : DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            } catch (\Throwable $e) {
                report($e);
                $r['erros'][] = ['recibo' => (string) $recibo->numero_recibo, 'mensagem' => mb_substr($e->getMessage(), 0, 300)];
            }
        }

        return $r;
    }

    private function exigirRecibo(DocumentoTesouraria $doc): ReciboVenda
    {
        $recibo = $this->reciboDe($doc) ?? throw new ErroNegocio('O documento não foi gerado por um recibo de venda.', 'NAO_E_RECIBO', 422);

        return ReciboVenda::query()->lockForUpdate()->findOrFail($recibo->id);
    }

    private function descricao(ReciboVenda $recibo, ?Terceiro $cliente): string
    {
        $nome = $cliente?->nome ? trim((string) $cliente->nome) : 'N/D';
        if ($recibo->venda_origem_id) {
            return "Factura-recibo {$recibo->referencia} — recibo {$recibo->numero_recibo} ({$nome})";
        }

        // legado (_generateReceiptPostingLines, ui_sales.js:4970): «Recebimento: <recibo> - <cliente>»
        return ($recibo->eAdiantamento() ? 'Adiantamento' : 'Recebimento').": {$recibo->numero_recibo} - {$nome}";
    }

    /** Linhas (contrapartida) do espelho, refeitas a partir do recibo: uma por factura liquidada ou uma do adiantamento. */
    private function linhas(DocumentoTesouraria $doc, ReciboVenda $recibo, ?Terceiro $cliente): void
    {
        $contaCliente = null;
        try {
            $contaCliente = $cliente?->contaCliente() ?: null;
        } catch (\Throwable) {
        }
        $contaCliente ??= $this->configVendas->conta('clientes_default');
        $comum = ['terceiro_id' => $cliente?->id, 'tipo_dc' => 'C', 'unidade_negocio_id' => $recibo->unidade_negocio_id,
            'centro_custo_id' => $recibo->centro_custo_id, 'projeto_id' => $recibo->projeto_id, 'empresa_id' => $recibo->empresa_id];
        $itens = ItemReciboVenda::query()->where('recibo_venda_id', $recibo->id)->orderBy('id')->get(['venda_id', 'montante_pago']);
        $linhas = [];
        if ($recibo->eAdiantamento() || $itens->isEmpty()) {
            $conta = $recibo->eAdiantamento() ? ($this->configVendas->conta('adiantamentos_clientes') ?? $contaCliente) : $contaCliente;
            $linhas[] = ['codigo_conta' => $conta, 'numero_documento' => $recibo->numero_recibo, 'valor' => $recibo->montante_total,
                'descricao' => mb_substr($doc->descricao ?? '', 0, 1000)];
        } else {
            $numeros = Venda::query()->whereIn('id', $itens->pluck('venda_id'))->pluck('numero_documento', 'id');
            foreach ($itens as $i) {
                $n = $numeros[$i->venda_id] ?? null;
                $linhas[] = ['codigo_conta' => $contaCliente, 'numero_documento' => $n, 'valor' => $i->montante_pago,
                    'descricao' => mb_substr("Recibo {$recibo->numero_recibo}".($n ? " — {$n}" : ''), 0, 1000)];
            }
        }
        ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->delete();
        foreach ($linhas as $l) {
            $valor = number_format((float) $l['valor'], 2, '.', '');
            ItemDocumentoTesouraria::query()->create($l + $comum + ['documento_tesouraria_id' => $doc->id, 'valor' => $valor, 'valor_introduzido' => $valor,
                'valor_kz_documento' => $valor]);
        }
    }
}
