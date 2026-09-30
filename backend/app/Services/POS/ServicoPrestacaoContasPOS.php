<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\DocumentoTesouraria;
use App\Models\LiquidacaoPOS;
use App\Models\MovimentoCaixa;
use App\Models\SessaoCaixa;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Tesouraria\ServicoCaixa;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use App\Services\Vendas\CalculadoraDocumento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Prestação de contas do POS — regularização das contas transitórias depois da integração da sessão
 * (js/pos_prestacao.js:494-797). Itens por sessão: `NUM:<meio>` (numerário → folha de caixa), `TPA:<meio>` (fecho do TPA →
 * recebimento no banco, com comissão) e `TRF:<venda>:<meio>` (um recebimento por comprovativo). Correcções face ao legado:
 *   - o numerário prestado inclui o desvio sempre que a deliberação tem efeito — automática (dentro da tolerância) ou
 *     manual —, pelo que a transitória fica saldada; no legado o desvio dentro da tolerância nunca era lançado e a
 *     diferença física ficava por regularizar (itensLiquidar, :499-505). Se o desvio deliberado ainda não tem lançamento
 *     o item fica bloqueado (prestar desbalancearia a transitória);
 *   - sessão sem numerário nas vendas mas com desvio deliberado: item de numerário pelo desvio (no legado não havia item
 *     e a sessão ficava PENDENTE para sempre); numerário a prestar negativo (falta maior do que as vendas em numerário)
 *     sai da caixa por movimento PAG (D transitória / C caixa); sessão sem nada a regularizar passa a LIQUIDADA;
 *   - segregação de funções no servidor: quem operou a sessão não presta contas dela (o legado só avisava);
 *   - TPA: a transitória é regularizada pelo valor do sistema (como o legado, :650); a diferença para o talão fica exposta
 *     no item e nos relatórios (o legado só avisava no modal); a comissão sugerida é pct × talão (:647-648), editável;
 *   - a folha de caixa é a sessão ABERTA da conta de liquidação do meio (o legado usava a única folha aberta da empresa);
 *   - anular não apaga nada: retira o movimento da folha ainda aberta e por contabilizar, ou ANULA os documentos de
 *     tesouraria pendentes; integrado → erro (desintegrar primeiro). A liquidação fica ANULADO com cancelado_em/por (:771-797);
 *   - tudo numa transacção com lock da sessão POS (o legado podia registar o mesmo item duas vezes com duplo clique).
 * Contas: a transitória vem do instantâneo da sessão (totais_por_metodo); a conta de liquidação e a comissão vêm do meio
 * actual do terminal (mesmo inactivo — a prestação de uma sessão antiga não depende de o meio continuar activo).
 */
final class ServicoPrestacaoContasPOS
{
    public const NATUREZAS = ['NUMERARIO', 'TPA', 'TRANSFERENCIA'];

    public function __construct(
        private readonly ServicoCaixa $caixa,
        private readonly ServicoDocumentosTesouraria $documentos,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /**
     * Itens a regularizar da sessão (itensLiquidar, pos_prestacao.js:494-518), com o estado de cada um.
     *
     * @return list<array<string, mixed>>
     */
    public function itens(SessaoPOS $s, ?Collection $liquidacoes = null): array
    {
        if ($s->estado !== 'FECHADA' || $s->estado_liquidacao === 'SEM_MOVIMENTO') {
            return [];
        }
        $liquidacoes ??= LiquidacaoPOS::query()->where('sessao_pos_id', $s->id)->where('estado', 'REGISTADO')->get();
        $feitos = $liquidacoes->where('estado', 'REGISTADO')->keyBy('chave_item');
        $terminal = TerminalPOS::query()->withTrashed()->find($s->terminal_pos_id);
        $meios = collect($terminal?->meios_pagamento ?? [])->keyBy('id');
        $totais = collect($s->totais_por_metodo ?? []);
        $del = $s->deliberacao;
        $desvio = number_format((float) $s->desvio, 2, '.', '');
        $comEfeito = $s->estado_desvio === 'DELIBERADO' && $del && ($del['decisao'] ?? 'SEM_EFEITO') !== 'SEM_EFEITO' && bccomp($desvio, '0', 2) !== 0;
        $porIntegrar = ! in_array($s->estado_contabilizacao, ['CONTABILIZADA', 'SEM_MOVIMENTO'], true);

        $itens = [];
        $desvioAplicado = false;
        $numerario = $totais->where('tipo', 'NUMERARIO')->values()->all();
        if (! $numerario && $comEfeito) {   // sem vendas em numerário: o desvio deliberado é o único numerário a prestar
            $m = $meios->first(fn ($m) => $m['tipo'] === 'NUMERARIO' && ($m['conta_transitoria'] ?? null) === ($del['conta_transitoria'] ?? null))
                ?? $meios->first(fn ($m) => $m['tipo'] === 'NUMERARIO' && ! empty($m['ativo']));
            if ($m) {
                $numerario[] = ['meio_id' => $m['id'], 'tipo' => 'NUMERARIO', 'nome' => $m['nome'], 'conta_transitoria' => $del['conta_transitoria'] ?? $m['conta_transitoria'] ?? null,
                    'valor' => '0.00', 'quantidade' => 0];
            }
        }
        foreach ($numerario as $m) {
            $sistema = number_format((float) $m['valor'], 2, '.', '');
            $valor = $sistema;
            $aplicado = null;
            if (! $desvioAplicado && $comEfeito) {
                $valor = bcadd($sistema, $desvio, 2);
                $aplicado = $desvio;
                $desvioAplicado = true;
            }
            $bloqueio = match (true) {
                $s->estado_desvio === 'PENDENTE' => ['DESVIO_PENDENTE', 'Delibere primeiro o desvio de caixa.'],
                $aplicado !== null && empty($del['numero_lan']) => ['DESVIO_NAO_LANCADO', 'O desvio deliberado ainda não tem lançamento: integre a sessão.'],
                default => null,
            };
            $itens[] = $this->item($s, 'NUMERARIO', "NUM:{$m['meio_id']}", $m, $meios, $valor, $feitos, $porIntegrar, $bloqueio)
                + ['numerario_sistema' => $sistema, 'desvio_aplicado' => $aplicado, 'movimento' => bccomp($valor, '0', 2) < 0 ? 'PAG' : 'REC'];
        }
        foreach ($totais->where('tipo', 'TPA') as $m) {
            $f = collect($s->fechos_tpa ?? [])->firstWhere('meio_id', $m['meio_id']) ?? [];
            $cfg = $meios[$m['meio_id']] ?? [];
            $valor = number_format((float) $m['valor'], 2, '.', '');
            $talao = isset($f['valor_talao']) ? number_format((float) $f['valor_talao'], 2, '.', '') : null;
            $pct = (string) ($cfg['comissao_pct'] ?? 0);
            $itens[] = $this->item($s, 'TPA', "TPA:{$m['meio_id']}", $m, $meios, $valor, $feitos, $porIntegrar, null) + [
                'codigo_tpa' => ($m['codigo_tpa'] ?? null) ?: ($f['codigo_tpa'] ?? null), 'operacoes_sistema' => $m['quantidade'] ?? null,
                'valor_talao' => $talao, 'operacoes_talao' => $f['operacoes_talao'] ?? null, 'referencia_lote' => $f['referencia_lote'] ?? null,
                'diferenca' => $talao === null ? null : bcsub($talao, $valor, 2),
                'comissao_pct' => (float) $pct, 'comissao_sugerida' => CalculadoraDocumento::arredondar(bcdiv(bcmul($talao ?? $valor, $pct, 8), '100', 8)),
                'conta_comissao' => $cfg['conta_comissao'] ?? null, 'comissao_deduzida' => (bool) ($cfg['comissao_deduzida'] ?? true),
            ];
        }
        foreach ($s->transferencias ?? [] as $t) {
            $m = ($totais->firstWhere('meio_id', $t['meio_id'] ?? null) ?? []) + ['meio_id' => $t['meio_id'] ?? null, 'nome' => 'Transferência', 'conta_transitoria' => null];
            $itens[] = $this->item($s, 'TRANSFERENCIA', "TRF:{$t['venda_id']}:{$m['meio_id']}", $m, $meios, number_format((float) $t['valor'], 2, '.', ''), $feitos, $porIntegrar, null)
                + ['venda_id' => (int) $t['venda_id'], 'numero_documento' => $t['numero_documento'] ?? null, 'referencia' => $t['referencia'] ?? null];
        }

        // como o legado (:520), só contam os itens com valor; o numerário negativo também se presta (PAG)
        return array_values(array_filter($itens, fn ($i) => $i['natureza'] === 'NUMERARIO' ? bccomp($i['valor'], '0', 2) !== 0 : bccomp($i['valor'], '0', 2) > 0));
    }

    /**
     * Regista a prestação de um item (posLiquidarItem / liquidarNumerario / posConfirmarTPA / posConfirmarTransferencia).
     *
     * @param  array{data?: ?string, conta_financeira?: ?string, sessao_caixa_id?: ?int, comissao?: float|string|null, comissao_deduzida?: ?bool}  $d
     * @return array{liquidacao: LiquidacaoPOS, aviso: ?string}
     */
    public function registar(SessaoPOS $s, string $chave, array $d = []): array
    {
        return DB::transaction(function () use ($s, $chave, $d) {
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);
            $u = Auth::user();
            if ($u && $s->operador_id && (int) $s->operador_id === (int) $u->id) {   // par (pos_venda, pos_prestar); o legado só avisava
                throw new ErroNegocio('Segregação de funções: quem operou a sessão não presta contas dela.', 'SEGREGACAO_FUNCOES', 403);
            }
            if ($s->estado !== 'FECHADA') {
                throw new ErroNegocio('Só se presta contas de sessões fechadas (fecho Z).', 'SESSAO_NAO_FECHADA', 422);
            }
            $item = collect($this->itens($s))->firstWhere('chave_item', $chave)
                ?? throw new ErroNegocio("A sessão {$s->numero_z} não tem o item {$chave} por regularizar.", 'ITEM_INEXISTENTE', 422, ['chave_item' => $chave]);
            if ($item['liquidacao']) {
                throw new ErroNegocio('Este valor já foi regularizado.', 'ITEM_JA_PRESTADO', 422, ['liquidacao_id' => $item['liquidacao']['id']]);
            }
            if ($item['bloqueio']) {
                throw new ErroNegocio($item['bloqueio']['mensagem'], $item['bloqueio']['codigo'], 422, ['chave_item' => $chave]);
            }
            $t = TerminalPOS::query()->withTrashed()->findOrFail($s->terminal_pos_id);
            $r = match ($item['natureza']) {
                'NUMERARIO' => $this->numerario($s, $t, $item, $d),
                'TPA' => $this->tpa($s, $t, $item, $d),
                'TRANSFERENCIA' => $this->transferencia($s, $t, $item, $d),
            };
            $this->actualizarEstado($s);

            return $r;
        });
    }

    /**
     * Regista todas as transferências por regularizar da sessão (posLiquidarTodasTransferencias, :736-746): um recebimento
     * por comprovativo, na data da venda e na conta de liquidação do meio. Cada uma na sua transacção.
     *
     * @return array{registadas: list<LiquidacaoPOS>, erros: list<array{chave_item: string, numero_documento: ?string, mensagem: string, codigo: ?string}>}
     */
    public function registarTransferencias(SessaoPOS $s): array
    {
        $r = ['registadas' => [], 'erros' => []];
        $pendentes = array_filter($this->itens($s), fn ($i) => $i['natureza'] === 'TRANSFERENCIA' && ! $i['liquidacao']);
        if (! $pendentes) {
            throw new ErroNegocio('A sessão não tem transferências por regularizar.', 'SEM_TRANSFERENCIAS', 422);
        }
        foreach ($pendentes as $i) {
            try {
                $r['registadas'][] = $this->registar($s, $i['chave_item'])['liquidacao'];
            } catch (ErroNegocio $e) {
                if ($e->codigo === 'SEGREGACAO_FUNCOES') {
                    throw $e;
                }
                $r['erros'][] = ['chave_item' => $i['chave_item'], 'numero_documento' => $i['numero_documento'], 'mensagem' => $e->getMessage(), 'codigo' => $e->codigo];
            }
        }

        return $r;
    }

    /** Anula uma liquidação (posAnularLiquidacao, :771-797), sem apagar nada. */
    public function anular(LiquidacaoPOS $l, string $motivo): LiquidacaoPOS
    {
        return DB::transaction(function () use ($l, $motivo) {
            $l = LiquidacaoPOS::query()->lockForUpdate()->findOrFail($l->id);
            if ($l->estado !== 'REGISTADO') {
                throw new ErroNegocio('A liquidação já está anulada.', 'LIQUIDACAO_JA_ANULADA', 422);
            }
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($l->sessao_pos_id);
            if ($l->alvo === 'FOLHA_CAIXA') {
                $m = $l->movimento_caixa_id ? MovimentoCaixa::query()->find($l->movimento_caixa_id) : null;
                if ($m) {
                    $folha = SessaoCaixa::query()->find($m->sessao_caixa_id);
                    if ($m->contabilizado || $folha?->estado === 'CONTABILIZADA') {
                        throw new ErroNegocio("O movimento da folha de caixa {$folha?->codigo_conta} já foi contabilizado: descontabilize primeiro essa sessão de caixa.",
                            'MOVIMENTO_CAIXA_CONTABILIZADO', 422, ['sessao_caixa_id' => $m->sessao_caixa_id]);
                    }
                    if ($folha?->estado !== 'ABERTA') {
                        throw new ErroNegocio("A sessão de caixa {$folha?->codigo_conta} (n.º {$m->sessao_caixa_id}) já está fechada: o movimento não pode ser retirado.",
                            'FOLHA_CAIXA_FECHADA', 422, ['sessao_caixa_id' => $m->sessao_caixa_id]);
                    }
                    $l->update(['movimento_caixa_id' => null]);   // chave estrangeira: a ligação fica no registo de auditoria
                    $this->caixa->removerMovimento($m);
                }
            } else {
                // primeiro a liquidação (a tesouraria recusa anular documentos de liquidações registadas)
                $l->update(['estado' => 'ANULADO', 'cancelado_em' => now(), 'cancelado_por' => Auth::user()?->nome_utilizador]);
                $docs = DocumentoTesouraria::query()->whereIn('id', array_filter([$l->documento_tesouraria_id, $l->documento_comissao_id]))->lockForUpdate()->get();
                foreach ($docs as $doc) {
                    if ($doc->estado === 'INTEGRADO') {
                        throw new ErroNegocio('O documento de tesouraria '.($doc->numero_documento ?: $doc->referencia)." (n.º {$doc->id}) já está integrado: desintegre-o primeiro.",
                            'DOCUMENTO_INTEGRADO', 422, ['documento_tesouraria_id' => $doc->id]);
                    }
                }
                foreach ($docs->where('estado', 'PENDENTE') as $doc) {
                    $this->documentos->anular($doc, mb_substr("Prestação de contas POS {$l->numero_z} anulada: {$motivo}", 0, 500));
                }
            }
            $l->update(['estado' => 'ANULADO', 'cancelado_em' => now(), 'cancelado_por' => Auth::user()?->nome_utilizador]);
            $this->actualizarEstado($s);

            return $l;
        });
    }

    /** estado_liquidacao da sessão (actualizarEstadoLiquidacao, :520-527); sem nada a regularizar → LIQUIDADA. */
    public function actualizarEstado(SessaoPOS $s): void
    {
        if ($s->estado !== 'FECHADA' || $s->estado_liquidacao === 'SEM_MOVIMENTO' || ! in_array($s->estado_contabilizacao, ['CONTABILIZADA', 'SEM_MOVIMENTO'], true)) {
            return;
        }
        $itens = $this->itens($s);
        $feitos = count(array_filter($itens, fn ($i) => $i['liquidacao']));
        $estado = $feitos === count($itens) ? 'LIQUIDADA' : ($feitos ? 'PARCIAL' : 'PENDENTE');
        if ($estado !== $s->estado_liquidacao) {
            $s->update(['estado_liquidacao' => $estado]);
        }
    }

    // ───────────── Por natureza ─────────────

    private function numerario(SessaoPOS $s, TerminalPOS $t, array $item, array $d): array
    {
        $conta = $item['conta_liquidacao'];
        $folha = ! empty($d['sessao_caixa_id']) ? SessaoCaixa::query()->findOrFail($d['sessao_caixa_id'])
            : ($conta ? SessaoCaixa::query()->where('codigo_conta', $conta)->where('estado', 'ABERTA')->first() : null);
        if (! $folha) {
            throw new ErroNegocio($conta ? "Não há folha de caixa aberta na conta {$conta}: abra uma sessão na folha de caixa para receber o numerário do POS."
                : "Defina a conta de liquidação de «{$item['nome']}» no terminal ou indique a sessão de caixa.", 'SEM_FOLHA_CAIXA', 422, ['conta' => $conta]);
        }
        if ($folha->estado !== 'ABERTA') {
            throw new ErroNegocio("A sessão de caixa {$folha->codigo_conta} (n.º {$folha->id}) não está aberta.", 'FOLHA_CAIXA_FECHADA', 422);
        }
        $valor = $item['valor'];
        $pag = bccomp($valor, '0', 2) < 0;
        $data = $this->data($d);
        $liq = $this->criar($s, $item, $data, $valor, '0.00', $valor, [
            'alvo' => 'FOLHA_CAIXA', 'conta_destino' => $folha->codigo_conta, 'sessao_caixa_id' => $folha->id]);
        $mov = $this->caixa->registarMovimento($folha, [
            'tipo' => $pag ? 'PAG' : 'REC', 'data_documento' => $data, 'conta_contrapartida' => $item['conta_transitoria'], 'valor' => ltrim($valor, '-'),
            'descricao' => mb_substr("Prestação de contas POS {$s->numero_z} · numerário ({$s->nome_operador})".($pag ? ' — falta superior às vendas em numerário' : ''), 0, 1000),
            'numero_documento' => $s->numero_z, 'referencia' => $s->codigo_sessao,
            'unidade_negocio_id' => $t->unidade_negocio_id, 'centro_custo_id' => $t->centro_custo_id,
        ]);
        $mov->update(['tipo_origem' => 'POS', 'origem_id' => $liq->id]);
        $liq->update(['movimento_caixa_id' => $mov->id]);
        $aviso = $conta && $folha->codigo_conta !== $conta ? "O numerário foi registado na caixa {$folha->codigo_conta}; o terminal indica a conta {$conta}." : null;

        return ['liquidacao' => $liq->refresh(), 'aviso' => $aviso];
    }

    private function tpa(SessaoPOS $s, TerminalPOS $t, array $item, array $d): array
    {
        $banco = $this->banco($d['conta_financeira'] ?? null, $item);
        $bruto = $item['valor'];
        $comissao = isset($d['comissao']) ? number_format((float) $d['comissao'], 2, '.', '') : $item['comissao_sugerida'];
        if (bccomp($comissao, '0', 2) < 0 || bccomp($comissao, $bruto, 2) >= 0) {
            throw new ErroNegocio('A comissão não pode ser negativa nem igual ou superior ao valor liquidado.', 'COMISSAO_INVALIDA', 422, ['comissao' => $comissao, 'valor' => $bruto]);
        }
        $temComissao = bccomp($comissao, '0', 2) > 0;
        $contaComissao = $item['conta_comissao'];
        if ($temComissao && ! $contaComissao) {
            throw new ErroNegocio("Defina a conta de comissões de «{$item['nome']}» no terminal.", 'COMISSAO_SEM_CONTA', 422);
        }
        $deduzida = array_key_exists('comissao_deduzida', $d) && $d['comissao_deduzida'] !== null ? (bool) $d['comissao_deduzida'] : $item['comissao_deduzida'];
        $data = $this->data($d);
        $referencia = mb_substr("POS-TPA-{$s->numero_z}-{$item['meio_id']}", 0, 60);
        $descricao = mb_substr('Liquidação TPA '.($item['codigo_tpa'] ?: $item['nome'])." · {$s->numero_z}".($item['referencia_lote'] ? " · lote {$item['referencia_lote']}" : ''), 0, 500);
        $dim = ['unidade_negocio_id' => $t->unidade_negocio_id, 'centro_custo_id' => $t->centro_custo_id, 'numero_documento' => $s->numero_z];
        $linhas = [['codigo_conta' => $item['conta_transitoria'], 'tipo_dc' => 'C', 'valor' => $bruto, 'descricao' => "{$descricao} — regularização da conta transitória"] + $dim];
        if ($temComissao && $deduzida) {
            $linhas[] = ['codigo_conta' => $contaComissao, 'tipo_dc' => 'D', 'valor' => $comissao, 'descricao' => "{$descricao} — comissão bancária"] + $dim;
        }
        $doc = $this->documentos->gravar(['tipo' => 'RECEBIMENTO', 'data_documento' => $data, 'conta_financeira' => $banco, 'descricao' => $descricao,
            'referencia' => $referencia, 'linhas' => $linhas]);
        $docComissao = $temComissao && ! $deduzida ? $this->documentos->gravar(['tipo' => 'PAGAMENTO', 'data_documento' => $data, 'conta_financeira' => $banco,
            'descricao' => "{$descricao} — comissão bancária", 'referencia' => mb_substr("{$referencia}-COM", 0, 60),
            'linhas' => [['codigo_conta' => $contaComissao, 'tipo_dc' => 'D', 'valor' => $comissao, 'descricao' => "{$descricao} — comissão bancária (não deduzida no valor creditado)"] + $dim]]) : null;
        $liq = $this->criar($s, $item, $data, $bruto, $comissao, $deduzida ? bcsub($bruto, $comissao, 2) : $bruto, [
            'alvo' => 'TESOURARIA', 'conta_destino' => $banco, 'conta_comissao' => $contaComissao, 'documento_tesouraria_id' => $doc->id,
            'documento_comissao_id' => $docComissao?->id, 'comissao_deduzida' => $deduzida ? 1 : 0, 'referencia_lote' => $item['referencia_lote']]);
        $aviso = $item['diferenca'] !== null && bccomp($item['diferenca'], '0', 2) !== 0
            ? "O talão ({$item['valor_talao']}) difere do sistema ({$bruto}): a transitória foi regularizada pelo valor do sistema; confira a diferença ({$item['diferenca']}) com o extracto." : null;

        return ['liquidacao' => $liq, 'aviso' => $aviso];
    }

    private function transferencia(SessaoPOS $s, TerminalPOS $t, array $item, array $d): array
    {
        $venda = Venda::query()->find($item['venda_id']);
        $banco = $this->banco($d['conta_financeira'] ?? null, $item);
        $data = $this->data($d, $venda?->data_emissao?->toDateString());
        $descricao = mb_substr("Transferência POS {$item['numero_documento']} · comprovativo {$item['referencia']}", 0, 500);
        $doc = $this->documentos->gravar(['tipo' => 'RECEBIMENTO', 'data_documento' => $data, 'conta_financeira' => $banco, 'descricao' => $descricao,
            'referencia' => mb_substr("POS-TRF-{$item['numero_documento']}-{$item['referencia']}", 0, 60),
            'linhas' => [['codigo_conta' => $item['conta_transitoria'], 'tipo_dc' => 'C', 'valor' => $item['valor'], 'descricao' => "{$descricao} — regularização da conta transitória",
                'terceiro_id' => $venda?->cliente_id, 'numero_documento' => $item['numero_documento'],
                'unidade_negocio_id' => $t->unidade_negocio_id, 'centro_custo_id' => $t->centro_custo_id]]]);
        $liq = $this->criar($s, $item, $data, $item['valor'], '0.00', $item['valor'], [
            'alvo' => 'TESOURARIA', 'conta_destino' => $banco, 'documento_tesouraria_id' => $doc->id,
            'numero_documento' => mb_substr((string) $item['numero_documento'], 0, 50), 'referencia' => mb_substr((string) $item['referencia'], 0, 50)]);

        return ['liquidacao' => $liq, 'aviso' => null];
    }

    // ───────────── Auxiliares ─────────────

    private function item(SessaoPOS $s, string $natureza, string $chave, array $m, Collection $meios, string $valor, Collection $feitos, bool $porIntegrar, ?array $bloqueio): array
    {
        $cfg = $meios[$m['meio_id'] ?? ''] ?? null;
        $transitoria = ($m['conta_transitoria'] ?? null) ?: ($cfg['conta_transitoria'] ?? null);
        $bloqueio = match (true) {
            $porIntegrar => ['SESSAO_NAO_INTEGRADA', 'Integre primeiro a sessão na contabilidade: as contas transitórias só têm saldo depois disso.'],
            ! $transitoria => ['MEIO_SEM_CONTA', "O meio «{$m['nome']}» não tem conta transitória."],
            default => $bloqueio,
        };
        $l = $feitos[$chave] ?? null;

        return ['chave_item' => $chave, 'natureza' => $natureza, 'meio_id' => $m['meio_id'] ?? null, 'nome' => $m['nome'] ?? ($cfg['nome'] ?? null),
            'conta_transitoria' => $transitoria, 'conta_liquidacao' => $cfg['conta_liquidacao'] ?? null, 'valor' => $valor,
            'estado' => $l ? 'PRESTADO' : ($bloqueio ? 'BLOQUEADO' : 'POR_PRESTAR'),
            'bloqueio' => $l || ! $bloqueio ? null : ['codigo' => $bloqueio[0], 'mensagem' => $bloqueio[1]],
            'liquidacao' => $l?->only(['id', 'alvo', 'data', 'montante_bruto', 'comissao', 'montante_liquido', 'conta_destino', 'sessao_caixa_id', 'movimento_caixa_id',
                'documento_tesouraria_id', 'documento_comissao_id', 'comissao_deduzida', 'criado_por', 'criado_em'])];
    }

    private function criar(SessaoPOS $s, array $item, string $data, string $bruto, string $comissao, string $liquido, array $extra): LiquidacaoPOS
    {
        return LiquidacaoPOS::create($extra + [
            'estado' => 'REGISTADO', 'criado_por' => Auth::user()?->nome_utilizador, 'sessao_pos_id' => $s->id, 'numero_z' => $s->numero_z, 'chave_item' => $item['chave_item'],
            'natureza_registo' => $item['natureza'], 'meio_pagamento_codigo' => $item['meio_id'], 'data' => $data, 'montante_bruto' => $bruto, 'comissao' => $comissao,
            'montante_liquido' => $liquido, 'conta_transitoria' => $item['conta_transitoria'],
        ]);
    }

    /** Conta bancária (43, em Kz) do recebimento: a indicada ou a de liquidação do meio. */
    private function banco(?string $conta, array $item): string
    {
        $conta = trim((string) $conta) ?: ($item['conta_liquidacao'] ?? null)
            ?: throw new ErroNegocio("Defina a conta de liquidação de «{$item['nome']}» no terminal ou indique a conta bancária.", 'CONTA_LIQUIDACAO_EM_FALTA', 422);
        $c = $this->plano->contaDeMovimento($conta);
        if (! str_starts_with($conta, '43')) {
            throw new ErroNegocio('O TPA e as transferências liquidam numa conta bancária (43).', 'CONTA_NAO_BANCO', 422, ['conta' => $conta]);
        }
        if (($c['codigo_moeda'] ?? null) && $c['codigo_moeda'] !== 'AOA') {
            throw new ErroNegocio("A conta {$conta} é em {$c['codigo_moeda']}: a transitória do POS está em Kz.", 'MOEDA_NAO_SUPORTADA', 422);
        }

        return $conta;
    }

    private function data(array $d, ?string $padrao = null): string
    {
        return substr((string) (($d['data'] ?? null) ?: ($padrao ?: now()->toDateString())), 0, 10);
    }
}
