<?php

namespace App\Services\POS\Lavandaria;

use App\Exceptions\ErroNegocio;
use App\Models\ItemReciboVenda;
use App\Models\PagamentoLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\POS\ServicoVendasPOS;
use App\Services\Sistema\ServicoNumeracao;
use App\Services\Vendas\ServicoDocumentosVenda;
use App\Services\Vendas\ServicoEstadoVenda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dinheiro da lavandaria na sessão POS: documentos, recibos, recebimentos e entrega (js/lavandaria.js:223-320, 1097-1264).
 * Regras mantidas:
 *   - recebido nesta operação = total das linhas a facturar e sem saldos anteriores (facturado = pago) → Factura-Recibo
 *     (venda directa, emitida por ServicoVendasPOS na série do terminal); senão Factura em conta corrente + recibo;
 *   - recibo ADIANTAMENTO quando o pago passa a exceder o facturado, senão PAGAMENTO;
 *   - Consumidor Final paga o saldo facturado na entrega; «facturar no adiantamento» factura os serviços confirmados;
 *   - os recebimentos distribuem-se pelas facturas da ordem, das mais antigas para as mais recentes.
 * Correcções face ao legado:
 *   - tudo numa transacção, com a ordem e a sessão bloqueadas (o legado gravava documento, recibo e ordem em passos soltos);
 *   - recibos RC-LAV/<terminal>/<ano>/<n> por ServicoNumeracao, a continuar os contadores do legado (lavandaria.js:288 podia
 *     recuar/duplicar); os documentos fiscais usam as séries (ServicoSeries) em vez de FT-LAV/FR-LAV;
 *   - pagamentos validados no servidor pelas regras do POS (troco só em numerário, comprovativo da transferência);
 *   - taxa de armazenagem com o IVA do produto da taxa (o legado simulava com 14 % fixo, lavandaria.js:1204);
 *   - o valor de cada linha passa a ser o valor do documento que a factura (o documento manda);
 *   - anulação de recibo enquanto a sessão está aberta (o legado não permitia anular recebimentos).
 * Pendente de alteração nas Vendas (ver relatório): a Factura sai por ServicoDocumentosVenda::emitir sem `pos` (série geral e
 * preço base a 2 casas), porque o motor só aceita facturas-recibo no modo POS.
 */
final class ServicoCaixaLavandaria
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
        private readonly ServicoVendasPOS $vendasPOS,
        private readonly ServicoDocumentosVenda $documentos,
        private readonly ServicoEstadoVenda $estadoVenda,
        private readonly ServicoConfigLavandaria $config,
        private readonly ServicoTabelasLavandaria $tabelas,
    ) {}

    /** Sessão aberta de um terminal de lavandaria activo (sessaoValida do legado), bloqueada até ao fim da transacção. */
    public function sessaoValida(int $sessaoId): array
    {
        $s = SessaoPOS::query()->lockForUpdate()->findOrFail($sessaoId);
        if ($s->estado !== 'ABERTA') {
            throw new ErroNegocio('A sessão de caixa já foi fechada. Abra uma nova sessão.', 'SESSAO_NAO_ABERTA', 422);
        }
        $t = TerminalPOS::query()->findOrFail($s->terminal_pos_id);
        if (! $t->ativo) {
            throw new ErroNegocio('O terminal está inactivo.', 'TERMINAL_INATIVO', 422);
        }
        if ($t->tipo !== 'LAVANDARIA') {
            throw new ErroNegocio("O terminal {$t->codigo} não é um terminal de lavandaria.", 'TERMINAL_NAO_LAVANDARIA', 422);
        }

        return [$s, $t];
    }

    /** Documentos de venda da ordem (FT/FR), pela coluna da venda e pela tabela de ligação migrada. */
    public function faturas(PedidoLavandaria $o): Collection
    {
        $ids = DB::table('pedidos_lavandaria_faturas')->where('empresa_id', $o->empresa_id)->where('pedido_lavandaria_id', $o->id)->pluck('venda_id');

        return Venda::query()->where(fn ($q) => $q->where('pedido_lavandaria_id', $o->id)->orWhereIn('id', $ids))
            ->whereIn('tipo_documento', ['FT', 'FR'])->orderBy('id')->get();
    }

    public function pagamentos(PedidoLavandaria $o): Collection
    {
        return PagamentoLavandaria::query()->where('pedido_lavandaria_id', $o->id)->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->orderBy('id')->get();
    }

    /** situacaoOrdem (lavandaria.js:214-218). */
    public function situacao(PedidoLavandaria $o): array
    {
        $facturado = RegrasLavandaria::soma($this->faturas($o)->where('estado', '<>', 'ANULADO')->map(fn ($v) => ['total' => $v->total_bruto])->all());
        $pago = RegrasLavandaria::soma($this->pagamentos($o)->map(fn ($p) => ['total' => $p->montante])->all());

        return RegrasLavandaria::totais($o->itens ?? [], $o->extras ?? [], $facturado, $pago);
    }

    /**
     * Recebimento com eventual facturação (receberComFacturacao, lavandaria.js:302-307).
     *
     * @param  list<array<string, mixed>>  $linhas  RegrasLavandaria::linhasPorFacturar
     * @return array{documento: ?Venda, recibo: ?PagamentoLavandaria}
     */
    public function receberComFaturacao(PedidoLavandaria $o, array $linhas, string $valor, array $pagamentos, SessaoPOS $s, TerminalPOS $t): array
    {
        $validados = null;
        if (bccomp($valor, '0', 2) > 0) {
            $validados = $this->vendasPOS->pagamentos($t, $pagamentos, $valor);   // valida antes de numerar
        }
        $documento = $linhas ? $this->emitirDocumento($o, $linhas, $s, $t, $validados ? $valor : null, $pagamentos) : null;
        $recibo = null;
        if ($validados && ! ($documento && $documento->tipo_documento === 'FR')) {
            $recibo = $this->registarRecibo($o, $valor, $validados[0], $validados[1], $s, $t);
        }

        return ['documento' => $documento, 'recibo' => $recibo];
    }

    /** emitirDocumento (lavandaria.js:223-282). */
    public function emitirDocumento(PedidoLavandaria $o, array $linhas, SessaoPOS $s, TerminalPOS $t, ?string $recebido, array $pagamentos = []): Venda
    {
        $total = RegrasLavandaria::soma($linhas);
        $antes = $this->situacao($o);
        $fr = $recebido !== null && bccomp($recebido, '0', 2) > 0 && bccomp($recebido, $total, 2) === 0 && bccomp($antes['facturado'], $antes['pago'], 2) === 0;
        $extra = ['pedido_lavandaria_id' => $o->id, 'numero_pedido_lavandaria' => $o->numero_encomenda];
        $obs = "Lavandaria {$o->numero_encomenda}";
        if ($fr) {
            $v = $this->vendasPOS->vender($s, ['cliente_id' => $o->cliente_id, 'observacoes' => $obs, 'pagamentos' => $pagamentos,
                'linhas' => array_map(fn ($l) => ['produto_id' => $l['produto_id'], 'quantidade' => $l['quantidade'], 'preco_unitario' => $l['preco'], 'descricao' => $l['descricao']], $linhas)], $extra);
        } else {
            $v = $this->emitirFatura($linhas, $s, $t, $o->cliente_id, $obs, $extra);
        }

        // o documento manda: cada linha fica com o valor facturado e a ligação ao documento
        $itensDoc = $v->itensVenda()->orderBy('id')->get()->values();
        $itens = $o->itens ?? [];
        $extras = $o->extras ?? [];
        foreach ($linhas as $k => $l) {
            $valorDoc = RegrasLavandaria::dinheiro($itensDoc[$k]->total);
            if ($l['ref'] === 'item') {
                foreach ($itens as $j => $i) {
                    if ((int) $i['linha_id'] === (int) $l['id']) {
                        $itens[$j]['venda_id'] = $v->id;
                        $itens[$j]['valor'] = $valorDoc;
                    }
                }
            } else {
                foreach ($extras as $j => $e) {
                    if ((string) $e['id'] === (string) $l['id']) {
                        $extras[$j]['venda_id'] = $v->id;
                        $extras[$j]['valor'] = $valorDoc;
                    }
                }
            }
        }
        $o->itens = $itens;
        $o->extras = $extras;
        DB::table('pedidos_lavandaria_faturas')->insert(['empresa_id' => $o->empresa_id, 'pedido_lavandaria_id' => $o->id, 'venda_id' => $v->id, 'criado_em' => now()]);
        if ($fr) {
            // o recebimento da factura-recibo fica também nos pagamentos da ordem (saldos); não é recibo nem entra duas vezes no Z
            PagamentoLavandaria::create(['pedido_lavandaria_id' => $o->id, 'numero_encomenda' => $o->numero_encomenda, 'sessao_pos_id' => $s->id, 'terminal_pos_id' => $t->id,
                'cliente_id' => $o->cliente_id, 'data' => now()->toDateString(), 'montante' => $v->total_bruto, 'troco' => $v->pos_troco, 'pos_pagamentos' => $v->pos_pagamentos,
                'numero_recibo' => $v->numero_documento, 'natureza_registo' => 'FR', 'estado' => 'REGISTADO', 'venda_id' => $v->id, 'criado_por' => Auth::user()?->nome_utilizador]);
            self::historico($o, "Factura-Recibo {$v->numero_documento} emitida e paga (".self::kz($v->total_bruto).').');
        } else {
            self::historico($o, "Factura {$v->numero_documento} emitida em conta corrente (".self::kz($v->total_bruto).').');
        }

        return $v;
    }

    /** registarRecibo (lavandaria.js:284-299). */
    public function registarRecibo(PedidoLavandaria $o, string $valor, array $linhasPagamento, string $troco, SessaoPOS $s, TerminalPOS $t): PagamentoLavandaria
    {
        $ano = (int) now()->format('Y');
        $prefixo = "RC-LAV/{$t->codigo}/{$ano}/";
        $n = $this->numeracao->proximo($this->contexto->obrigatorio(), "lav_rc:{$t->id}:{$ano}", fn () => max((int) ($t->lavandaria_contadores_rc[(string) $ano] ?? 0),
            self::maiorSufixo(PagamentoLavandaria::query()->where('numero_recibo', 'like', $prefixo.'%')->pluck('numero_recibo')->all(), $prefixo)));
        $numero = sprintf('%s%05d', $prefixo, $n);
        $sit = $this->situacao($o);
        $natureza = bccomp(bcadd($sit['pago'], $valor, 2), $sit['facturado'], 2) > 0 ? 'ADIANTAMENTO' : 'PAGAMENTO';
        $p = PagamentoLavandaria::create(['pedido_lavandaria_id' => $o->id, 'numero_encomenda' => $o->numero_encomenda, 'sessao_pos_id' => $s->id, 'terminal_pos_id' => $t->id,
            'cliente_id' => $o->cliente_id, 'data' => now()->toDateString(), 'montante' => $valor, 'troco' => $troco, 'pos_pagamentos' => $linhasPagamento,
            'numero_recibo' => $numero, 'natureza_registo' => $natureza, 'estado' => 'REGISTADO', 'criado_por' => Auth::user()?->nome_utilizador]);
        self::historico($o, "Recibo {$numero}: ".self::kz($valor).' ('.implode(' + ', array_column($linhasPagamento, 'nome')).')'.($natureza === 'ADIANTAMENTO' ? ' — adiantamento' : '').'.');

        return $p;
    }

    /**
     * Distribui os recebimentos da ordem pelas facturas, das mais antigas para as mais recentes (actualizarFacturas, lavandaria.js:310-320).
     * Recibos das Vendas lançados sobre a mesma factura também contam.
     */
    public function actualizarFaturas(PedidoLavandaria $o): void
    {
        $resta = RegrasLavandaria::soma($this->pagamentos($o)->map(fn ($p) => ['total' => $p->montante])->all());
        foreach ($this->faturas($o)->where('estado', '<>', 'ANULADO') as $f) {
            $total = (string) $f->total_bruto;
            $parte = bccomp($resta, $total, 2) < 0 ? $resta : $total;
            $resta = bcsub($resta, $parte, 2);
            if ($f->tipo_documento !== 'FT') {
                continue;
            }
            $recibosVendas = (string) ItemReciboVenda::query()->where('venda_id', $f->id)
                ->whereHas('reciboVenda', fn ($q) => $q->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO')))->sum('montante_pago');
            $pago = bcadd($parte, RegrasLavandaria::dinheiro($recibosVendas), 2);
            $f = Venda::query()->lockForUpdate()->findOrFail($f->id);
            $f->update(['valor_pago' => bccomp($pago, $total, 2) > 0 ? $total : $pago]);
            $this->estadoVenda->recalcular($f);
        }
    }

    /** Receber pagamento (lavConfirmarPagamento, lavandaria.js:1113-1141). */
    public function receber(int $sessaoId, int $pedidoId, string $valor, array $pagamentos): array
    {
        return DB::transaction(function () use ($sessaoId, $pedidoId, $valor, $pagamentos) {
            [$s, $t] = $this->sessaoValida($sessaoId);
            $o = $this->pedidoAtivo($pedidoId);
            $sit = $this->situacao($o);
            if (bccomp($valor, '0', 2) <= 0) {
                throw new ErroNegocio('Indique o valor a receber.', 'VALOR_INVALIDO', 422);
            }
            if (bccomp($valor, $sit['saldo'], 2) > 0) {
                throw new ErroNegocio("O valor excede o saldo da ordem ({$sit['saldo']}).", 'VALOR_EXCEDE_SALDO', 422, ['saldo' => $sit['saldo']]);
            }
            // com «facturar no adiantamento», o recebimento que excede o já facturado factura os serviços confirmados
            $facturar = $this->config->obter()['faturar_no_adiantamento'] && bccomp(bcadd($sit['pago'], $valor, 2), $sit['facturado'], 2) > 0;
            $r = $this->receberComFaturacao($o, $facturar ? RegrasLavandaria::linhasPorFacturar($o->itens ?? [], $o->extras ?? []) : [], $valor, $pagamentos, $s, $t);
            $o->save();
            $this->actualizarFaturas($o);

            return $r + ['pedido' => $o->refresh()];
        });
    }

    /** Emitir factura em conta corrente das linhas por facturar (lavFacturarPendente, lavandaria.js:1143-1161). */
    public function faturarPendente(int $sessaoId, int $pedidoId): array
    {
        return DB::transaction(function () use ($sessaoId, $pedidoId) {
            [$s, $t] = $this->sessaoValida($sessaoId);
            $o = $this->pedidoAtivo($pedidoId);
            $linhas = RegrasLavandaria::linhasPorFacturar($o->itens ?? [], $o->extras ?? []);
            if (! $linhas) {
                throw new ErroNegocio('A ordem não tem linhas por facturar.', 'NADA_A_FATURAR', 422);
            }
            $v = $this->emitirDocumento($o, $linhas, $s, $t, null);
            $o->save();
            $this->actualizarFaturas($o);

            return ['documento' => $v, 'recibo' => null, 'pedido' => $o->refresh()];
        });
    }

    /**
     * Simulação da entrega (simularEntrega, lavandaria.js:1198-1211).
     *
     * @param  list<int>  $linhasIds
     */
    public function simularEntrega(PedidoLavandaria $o, array $linhasIds): array
    {
        $cfg = $this->config->obter();
        $prontas = array_values(array_filter($o->itens ?? [], fn ($i) => $i['estado'] === 'PRONTA' && in_array((int) $i['linha_id'], $linhasIds, true)));
        $taxa = RegrasLavandaria::taxaArmazenagem($prontas, $cfg, now());
        $extras = $o->extras ?? [];
        if (bccomp($taxa['valor'], '0', 2) > 0) {
            $taxaIva = Produto::query()->where('codigo', RegrasLavandaria::EXTRAS['ARMAZEM'][0])->value('taxa_imposto') ?? $cfg['taxa_extras'];
            $extras[] = ['id' => 'SIMULACAO', 'produto_id' => 0, 'nome' => 'Taxa de armazenagem', 'valor' => RegrasLavandaria::valorComIva(1, $taxa['valor'], $taxaIva)];
        }
        $porFacturar = RegrasLavandaria::soma(RegrasLavandaria::linhasPorFacturar($o->itens ?? [], $extras, $linhasIds));
        $sit = $this->situacao($o);
        $saldo = bcsub(bcadd($sit['facturado'], $porFacturar, 2), $sit['pago'], 2);

        return ['linhas' => array_column($prontas, 'linha_id'), 'taxa_armazenagem' => $taxa, 'por_facturar' => $porFacturar,
            'saldo_facturado' => bccomp($saldo, '0', 2) < 0 ? '0.00' : $saldo,
            'venda_directa_possivel' => bccomp($porFacturar, '0', 2) > 0 && bccomp($sit['facturado'], $sit['pago'], 2) === 0,
            'consumidor_final' => RegrasLavandaria::consumidorFinal(Terceiro::query()->find($o->cliente_id))];
    }

    /** Entrega ao cliente (lavConfirmarEntrega, lavandaria.js:1226-1264). */
    public function entregar(int $sessaoId, int $pedidoId, array $linhasIds, string $valor, array $pagamentos): array
    {
        return DB::transaction(function () use ($sessaoId, $pedidoId, $linhasIds, $valor, $pagamentos) {
            [$s, $t] = $this->sessaoValida($sessaoId);
            $o = $this->pedidoAtivo($pedidoId);
            $linhasIds = array_values(array_unique(array_map('intval', $linhasIds)));
            if (! $linhasIds) {
                throw new ErroNegocio('Seleccione as peças a entregar.', 'SEM_LINHAS', 422);
            }
            foreach ($linhasIds as $id) {
                $i = collect($o->itens ?? [])->first(fn ($x) => (int) $x['linha_id'] === $id);
                if (! $i || $i['estado'] !== 'PRONTA') {
                    throw new ErroNegocio("A linha {$id} não está pronta para entrega.", 'LINHA_NAO_PRONTA', 422, ['linha_id' => $id]);
                }
            }
            $sim = $this->simularEntrega($o, $linhasIds);
            if ($sim['consumidor_final'] && bccomp($valor, $sim['saldo_facturado'], 2) < 0) {
                throw new ErroNegocio("Consumidor Final: tem de receber {$sim['saldo_facturado']} antes de entregar.", 'PAGAMENTO_OBRIGATORIO', 422,
                    ['saldo_facturado' => $sim['saldo_facturado']]);
            }
            if (bccomp($valor, $sim['saldo_facturado'], 2) > 0) {
                throw new ErroNegocio("O valor excede o saldo a pagar ({$sim['saldo_facturado']}).", 'VALOR_EXCEDE_SALDO', 422, ['saldo_facturado' => $sim['saldo_facturado']]);
            }
            if (bccomp($sim['taxa_armazenagem']['valor'], '0', 2) > 0) {
                $p = $this->tabelas->produtoExtra('ARMAZEM');
                $extras = $o->extras ?? [];
                $extras[] = ['id' => 'ARMAZEM-'.(count($extras) + 1), 'chave' => 'ARMAZEM', 'produto_id' => $p->id,
                    'nome' => 'Taxa de armazenagem ('.implode(', ', array_map(fn ($d) => "{$d['dias']}d", $sim['taxa_armazenagem']['detalhe'])).')',
                    'valor' => RegrasLavandaria::valorComIva(1, $sim['taxa_armazenagem']['valor'], $p->taxa_imposto), 'taxa_imposto' => (string) $p->taxa_imposto];
                $o->extras = $extras;
            }
            $r = $this->receberComFaturacao($o, RegrasLavandaria::linhasPorFacturar($o->itens ?? [], $o->extras ?? [], $linhasIds), $valor, $pagamentos, $s, $t);
            $agora = now()->toIso8601String();
            $quem = Auth::user()?->nome_utilizador;
            $itens = $o->itens;
            $nomes = [];
            foreach ($itens as $k => $i) {
                if (in_array((int) $i['linha_id'], $linhasIds, true) && $i['estado'] === 'PRONTA') {
                    $itens[$k]['estado'] = 'ENTREGUE';
                    $itens[$k]['entregue_em'] = $agora;
                    $itens[$k]['entregue_por'] = $quem;
                    $nomes[] = trim(($i['nome_peca'] ?? '').' '.$i['nome']);
                }
            }
            $o->itens = $itens;
            self::historico($o, 'Entregue(s): '.implode(', ', $nomes).'.');
            $o->estado = RegrasLavandaria::estadoOrdem($itens);
            if ($o->estado === 'ENTREGUE') {
                $o->entregue_em = now();
            }
            $o->save();
            $this->actualizarFaturas($o);

            return $r + ['pedido' => $o->refresh()];
        });
    }

    /**
     * Anula um recibo (ADIANTAMENTO/PAGAMENTO) enquanto a sessão em que foi recebido está aberta — antes do Z, logo antes de
     * integrado e prestado. O dinheiro é devolvido na mesma sessão. A factura-recibo corrige-se com nota de crédito.
     */
    public function anularRecibo(int $pagamentoId, string $motivo): PagamentoLavandaria
    {
        return DB::transaction(function () use ($pagamentoId, $motivo) {
            $p = PagamentoLavandaria::query()->lockForUpdate()->findOrFail($pagamentoId);
            if ($p->estado === 'ANULADO') {
                throw new ErroNegocio('O recibo já está anulado.', 'JA_ANULADO', 422);
            }
            if ($p->natureza_registo === 'FR') {
                throw new ErroNegocio('Recebimento de uma factura-recibo: corrige-se com nota de crédito sobre o documento.', 'RECIBO_DE_FR', 422);
            }
            $s = $p->sessao_pos_id ? SessaoPOS::query()->lockForUpdate()->find($p->sessao_pos_id) : null;
            if (! $s || $s->estado !== 'ABERTA' || $p->lans_contabilizacao) {
                throw new ErroNegocio('Só se anula um recibo enquanto a sessão de caixa em que foi recebido está aberta.', 'RECIBO_SESSAO_FECHADA', 422);
            }
            $o = PedidoLavandaria::query()->lockForUpdate()->findOrFail($p->pedido_lavandaria_id);
            $p->update(['estado' => 'ANULADO']);
            self::historico($o, "Recibo {$p->numero_recibo} anulado (".self::kz($p->montante).") — {$motivo}.");
            $o->save();
            $this->actualizarFaturas($o);

            return $p->refresh();
        });
    }

    public function pedidoAtivo(int $id): PedidoLavandaria
    {
        $o = PedidoLavandaria::query()->lockForUpdate()->findOrFail($id);
        if ($o->estado === 'ANULADA') {
            throw new ErroNegocio('A ordem está anulada.', 'ORDEM_ANULADA', 422);
        }

        return $o;
    }

    /**
     * Factura em conta corrente. Enquanto o motor de vendas não aceitar facturas no modo POS, sai com o preço base
     * (sem IVA) a 2 casas que reproduz o valor com IVA; o valor final é o que o documento calcular.
     */
    /** Factura em conta corrente na série do terminal, com preço com IVA (ServicoDocumentosVenda::emitir em modo POS). */
    private function emitirFatura(array $linhas, SessaoPOS $s, TerminalPOS $t, int $clienteId, string $obs, array $extra): Venda
    {
        return $this->documentos->emitir([
            'tipo_documento' => 'FT', 'cliente_id' => $clienteId, 'data_emissao' => now()->toDateString(), 'modo_pagamento' => 'PRONTO', 'observacoes' => $obs,
            'armazem_id' => $t->armazem_id, 'unidade_negocio_id' => $t->unidade_negocio_id, 'centro_custo_id' => $t->centro_custo_id,
            'linhas' => array_map(fn ($l) => ['produto_id' => $l['produto_id'], 'quantidade' => $l['quantidade'], 'descricao' => $l['descricao'], 'preco_unitario' => $l['preco']], $linhas),
            'pos' => ['origem_serie' => $t->codigo, 'percentagem_desconto' => 0, 'colunas' => $extra + ['sessao_pos_id' => $s->id, 'terminal_pos_id' => $t->id,
                'codigo_terminal_pos' => $t->codigo, 'pos_operador' => Auth::user()?->nomeApresentacao()]],
        ]);
    }

    public static function historico(PedidoLavandaria $o, string $texto): void
    {
        $o->historico_alteracoes = [...($o->historico_alteracoes ?? []), ['em' => now()->toIso8601String(), 'por' => Auth::user()?->nome_utilizador, 'texto' => $texto]];
    }

    public static function kz(mixed $v): string
    {
        return number_format((float) $v, 2, ',', ' ').' Kz';
    }

    public static function maiorSufixo(array $numeros, string $prefixo): int
    {
        return array_reduce($numeros, fn ($m, $x) => str_starts_with((string) $x, $prefixo) ? max($m, (int) preg_replace('/\D/', '', substr((string) $x, strlen($prefixo)))) : $m, 0);
    }
}
