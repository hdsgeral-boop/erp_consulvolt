<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Jobs\Vendas\CicloAgt;
use App\Models\ConfigFaturacaoEletronica;
use App\Models\ItemVenda;
use App\Models\OportunidadeVendaCRM;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\CRM\ServicoOportunidadesCRM;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoCambios;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Documentos comerciais (saveSale / executeConversion do legado, js/ui_sales.js:1708, 2893), com as correcções:
 *   - numeração por série, sem colisões nem condição de corrida (ServicoSeries);
 *   - totais AGT em decimal exacto (CalculadoraDocumento);
 *   - tudo numa única transacção (o legado gravava cabeçalho, linhas, stock e recibo separadamente);
 *   - estado persistido e coerente (o legado quase nunca gravava `status`);
 *   - a nota de crédito abate o pendente da factura de origem (o legado não o fazia);
 *   - validação de quantidades, preços, produtos bloqueados e condições de pagamento;
 *   - multi-moeda (js/moedas_documentos.js): moeda e câmbio no cabeçalho; os totais oficiais ficam em Kz e os
 *     valores na moeda em *_moeda. Os valores em Kz são calculados com as regras AGT sobre o preço convertido
 *     (6 casas), para o documento fiscal ser coerente; a NC usa sempre o câmbio da factura de origem;
 *   - Hash SAF-T(AO) calculado na emissão, encadeado por série (ServicoHashSaft);
 *   - preço livre (decisão 8): no ecrã de emissão, um preço diferente do da ficha (convertido ao câmbio) exige a tarefa
 *     vendas_alterar_preco e o preço original fica na auditoria; conversões, NC, POS e projectos usam os preços de origem;
 *   - câmbio manual (decisão 9): validado contra o câmbio do dia com a tolerância configurável (ServicoCambios::validarManual);
 *   - POS (decisão 10): o desconto gravado é só o desconto comercial (arred(bruto × % / 100)); o arredondamento AGT
 *     (diferença entre o valor cobrado e o total das linhas) fica no campo próprio arredondamento_agt.
 * Guias (GR/GD) e stock (ADR-043, ServicoStockVendas): FT/FR/GR baixam, GD e NC de devolução repõem, CMV no lançamento.
 */
final class ServicoDocumentosVenda
{
    /** Conversões permitidas (convertDocument, js/ui_sales.js:2730), sem as que o legado não suportava. */
    public const CONVERSOES = ['OR' => ['NE', 'FT'], 'PF' => ['NE', 'FT'], 'NE' => ['GR', 'FT'], 'GR' => ['FT', 'GD'], 'FT' => ['NC'], 'FR' => ['NC']];

    private const TIPOS_EMITIVEIS = ['FT', 'FR', 'NC', 'OR', 'PF', 'NE', 'GR', 'GD'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoSeries $series,
        private readonly ServicoSelagemAgt $selagem,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoRecibosVenda $recibos,
        private readonly ServicoEstadoVenda $estado,
        private readonly ServicoCambios $cambios,
        private readonly ServicoHashSaft $hash,
        private readonly ServicoStockVendas $stockVendas,
        private readonly ServicoConfigVendas $configVendas,
    ) {}

    /**
     * @param  array<string, mixed>  $d  tipo_documento, cliente_id, data_emissao, linhas[{produto_id, quantidade, preco_unitario?, descricao?}],
     *                                   venda_origem_id (NC), motivo_nota_credito (NC), conta_disponibilidade + meio_pagamento (FR),
     *                                   modo_pagamento/plano_pagamentos, dias_validade/valido_ate, observacoes, UN/CC/projecto;
     *                                   pos {origem_serie, percentagem_desconto, colunas} — venda POS (ServicoVendasPOS): preços com IVA,
     *                                   série do terminal, pagamentos nas contas transitórias do terminal (sem recibo avulso)
     */
    public function emitir(array $d, ?Venda $origemConversao = null, bool $exigirPermissaoPreco = false): Venda
    {
        $empresa = $this->contexto->obrigatorio();
        $tipo = $d['tipo_documento'];
        if (! in_array($tipo, self::TIPOS_EMITIVEIS, true)) {
            throw new ErroNegocio("Tipo de documento {$tipo} não emitível.", 'TIPO_NAO_SUPORTADO', 422);
        }
        $fiscal = in_array($tipo, Venda::FISCAIS, true);
        $data = substr($d['data_emissao'], 0, 10);
        $this->exercicios->exigirAberto($empresa, $data);

        $cliente = Terceiro::query()->findOrFail($d['cliente_id']);
        // só a FT e a NC lançam na conta do cliente, e a contabilização usa a conta de clientes por omissão quando ele não tem conta própria;
        // FR (disponibilidade ou transitórias do POS), guias, encomendas e orçamentos não a usam (o legado aceitava clientes sem conta)
        if (! $cliente->eCliente() || (! $cliente->codigo_conta && in_array($tipo, ['FT', 'NC'], true) && ! $this->configVendas->conta('clientes_default'))) {
            throw new ErroNegocio('O cliente tem de estar registado como cliente e ter conta contabilística.', 'CLIENTE_INVALIDO', 422);
        }
        $config = ConfigFaturacaoEletronica::query()->first();
        $origemNcPrevia = $tipo === 'NC' ? Venda::query()->find($d['venda_origem_id'] ?? 0) : null;
        $pos = $d['pos'] ?? null;
        if ($pos && (! in_array($tipo, ['FR', 'FT'], true) || (($d['codigo_moeda'] ?? 'AOA') !== 'AOA'))) {   // FT: lavandaria em conta corrente
            throw new ErroNegocio('No POS só se emitem facturas e facturas-recibo em Kz.', 'POS_TIPO_INVALIDO', 422);
        }
        $moeda = $this->resolverMoeda($d, $empresa, $data, $origemNcPrevia, $tipo);
        $precosAlterados = [];
        $verificarPreco = $exigirPermissaoPreco && ! $pos && $tipo !== 'NC' && ! $origemConversao;
        $linhas = $this->prepararLinhas($d['linhas'], $fiscal, $config, $moeda['taxa'], $verificarPreco, $precosAlterados);
        if ($origemNcPrevia) {
            $linhas = $this->linhasDaOrigemNc($linhas, $d['linhas'], $origemNcPrevia, $moeda['estrangeira']);
        }
        $calculoMoeda = $moeda['estrangeira'] ? CalculadoraDocumento::calcular($linhas) : null;
        $linhasKz = $moeda['estrangeira']
            ? array_map(fn ($l) => ['preco_unitario' => bcmul($l['preco_unitario'], $moeda['taxa'], 6)] + $l, $linhas) : $linhas;
        $calculo = $pos ? CalculadoraDocumento::calcularComIva($linhasKz, $pos['percentagem_desconto'] ?? 0) : CalculadoraDocumento::calcular($linhasKz);
        $arredondamentoPos = $pos ? self::descontoEArredondamentoPos($linhasKz, (string) ($pos['percentagem_desconto'] ?? 0), $calculo['total_bruto']) : null;
        if ($origemNcPrevia && ! $moeda['estrangeira']) {
            $calculo = $this->calculoDaOrigemNc($linhas, $calculo);
        }
        if ($pos) {   // preço da linha passa a ser a base sem IVA e já com o desconto (valor da linha / quantidade)
            foreach ($linhasKz as $i => $l) {
                $linhasKz[$i]['preco_unitario'] = $calculo['linhas'][$i]['preco_base'];
            }
        }
        $exigirSerieAgt = $fiscal && $this->selagem->emRegimeNaData($config, $data) && ! empty($config?->servico['exigir_series_agt']);

        if ($tipo === 'FR' && ! $pos) {
            $this->recibos->exigirContaDisponibilidade($d['conta_disponibilidade']
                ?? throw new ErroNegocio('Indique a conta de disponibilidade (caixa/banco) da factura-recibo.', 'CONTA_DISPONIBILIDADE_EM_FALTA', 422));
        }
        $condicoes = $this->condicoesPagamento($tipo, $d, $data);

        return DB::transaction(function () use ($d, $tipo, $fiscal, $data, $cliente, $linhas, $linhasKz, $calculo, $calculoMoeda, $moeda, $config, $condicoes, $empresa, $origemConversao, $exigirSerieAgt, $pos, $arredondamentoPos, $precosAlterados) {
            // dentro da transacção e com a factura de origem bloqueada: duas NC em simultâneo não excedem o saldo
            $origemNc = $tipo === 'NC' ? $this->validarNotaCredito($d, $cliente, $calculo['total_bruto']) : null;
            if ($origemNc) {
                $this->exigirQuantidadesNc($origemNc, $linhas);
            }
            if ($fiscal && $this->selagem->emRegimeNaData($config, $data)) {
                $this->preValidarAgt($empresa, $tipo, $data, $cliente, $linhas, $linhasKz, $calculo, $calculoMoeda, $moeda, $d, $config, $origemNc);
            }
            $reserva = $this->series->reservar($empresa, $tipo, $data, $fiscal, $pos['origem_serie'] ?? 'GERAL', $exigirSerieAgt);
            $bruto = $calculo['total_bruto'];
            $armazem = $this->stockVendas->armazem(isset($d['armazem_id']) ? (int) $d['armazem_id'] : null, $origemConversao ?? $origemNc);
            $venda = Venda::create(array_merge([
                'cliente_id' => $cliente->id, 'tipo_documento' => $tipo, 'numero_documento' => $reserva['numero_documento'],
                'data_emissao' => $data.' '.now()->format('H:i:s'),
                'total_liquido' => $calculo['total_liquido'], 'total_imposto' => $calculo['total_imposto'], 'total_bruto' => $bruto,
                'valor_pago' => $tipo === 'FR' ? $bruto : '0.00', 'valor_pendente' => in_array($tipo, ['FR', 'NC', 'GR', 'GD'], true) ? '0.00' : $bruto,
                'estado' => match ($tipo) {
                    'FR' => 'PAGO', 'NC', 'GD' => 'CONCLUIDO', default => 'PENDENTE'
                },
                'armazem_id' => $armazem, 'devolucao_mercadoria' => $tipo === 'NC' && ! empty($d['devolucao_mercadoria']),
                'contabilizado' => false, 'codigo_moeda' => $moeda['codigo'],
                'taxa_cambio' => $moeda['estrangeira'] ? $moeda['taxa'] : null, 'taxa_cambio_id' => $moeda['taxa_id'], 'taxa_cambio_manual' => $moeda['manual'],
                'total_liquido_moeda' => $calculoMoeda['total_liquido'] ?? null, 'total_imposto_moeda' => $calculoMoeda['total_imposto'] ?? null,
                'total_bruto_moeda' => $calculoMoeda['total_bruto'] ?? null,
                'serie_faturacao_eletronica_id' => $reserva['serie']->id, 'fe_serie' => $reserva['serie']->codigo, 'fe_numero' => $reserva['numero'],
                'fe_data_entrada_sistema' => now(),
                'motivo_nota_credito' => $tipo === 'NC' ? $d['motivo_nota_credito'] : null,
                'meio_pagamento' => $tipo === 'FR' ? ($d['meio_pagamento'] ?? 'NUMERARIO') : null,
                'observacoes' => $d['observacoes'] ?? null, 'condicoes_pagamento' => $d['condicoes_pagamento'] ?? null,
                'unidade_negocio_id' => $d['unidade_negocio_id'] ?? null, 'centro_custo_id' => $d['centro_custo_id'] ?? null, 'projeto_id' => $d['projeto_id'] ?? null,
            ], $condicoes, $pos['colunas'] ?? []));
            if ($arredondamentoPos) {   // decisão 10: desconto comercial e arredondamento AGT em campos separados
                $venda->forceFill($arredondamentoPos)->save();
            }
            if ($precosAlterados) {   // decisão 8: o preço original da ficha fica na auditoria
                app(ServicoAuditoria::class)->registar('Vendas/Facturação', 'Preço alterado',
                    "{$venda->numero_documento}: preço diferente do da ficha em ".count($precosAlterados).' linha(s).', 'vendas', $venda->id,
                    ['precos_ficha' => array_column($precosAlterados, 'preco_ficha', 'produto')], ['precos_documento' => array_column($precosAlterados, 'preco', 'produto')]);
            }

            $itens = new Collection;
            $itensNc = $origemNc ? $origemNc->itensVenda()->orderBy('id')->get() : collect();
            foreach ($linhas as $i => $l) {
                // linha de origem (conversão) ou, na NC directa, a linha da factura com o mesmo produto
                $idOrigem = $d['linhas'][$i]['item_origem_id'] ?? null;
                $itemOrigem = $idOrigem ? ItemVenda::query()->find($idOrigem) : $itensNc->firstWhere('produto_id', $l['produto_id']);
                $stock = $this->stockVendas->movimentarLinha($venda, $l, $itemOrigem, $origemConversao ?? $origemNc, $armazem);
                $itens->push(ItemVenda::create($stock + [
                    'venda_id' => $venda->id, 'produto_id' => $l['produto_id'], 'descricao' => $l['descricao'], 'quantidade' => $l['quantidade'],
                    'preco_unitario' => CalculadoraDocumento::arredondar($linhasKz[$i]['preco_unitario']), 'taxa_imposto' => $l['taxa_imposto'],
                    'total' => $calculo['linhas'][$i]['total'], 'total_linha' => $calculo['linhas'][$i]['valor'], 'observacoes' => $l['observacoes'] ?? null,
                    'percentagem_desconto' => $pos ? ($pos['percentagem_desconto'] ?? 0) : null,
                    'preco_unitario_moeda' => $calculoMoeda ? $l['preco_unitario'] : null, 'total_moeda' => $calculoMoeda['linhas'][$i]['total'] ?? null,
                    'imposto_moeda' => $calculoMoeda['linhas'][$i]['imposto'] ?? null,
                ]));
            }

            $relacionada = $origemNc ?? $origemConversao;
            if ($relacionada) {
                DB::table('vendas_documentos_relacionados')->insert(['venda_id' => $venda->id, 'venda_relacionada_id' => $relacionada->id, 'empresa_id' => $empresa]);
            }
            if ($origemNc) {
                $this->estado->recalcular($origemNc->refresh());
            }
            if (! empty($d['oportunidade_crm_id'])) {   // CRM (ADR-054): mesmo cliente, não ligado a outra oportunidade; FT/FR/NE ganham-na
                app(ServicoOportunidadesCRM::class)->ligarVenda(OportunidadeVendaCRM::query()->findOrFail($d['oportunidade_crm_id']), $venda->id);
            }
            if ($tipo === 'FR' && ! $pos) {
                $this->recibos->criarDaFacturaRecibo($venda, $d['conta_disponibilidade'], $d['meio_pagamento'] ?? 'NUMERARIO', $d['referencia_pagamento'] ?? null);
            }
            if ($fiscal) {
                $this->hash->assinar($venda);
                $this->selagem->selar($venda, $itens, $config, $origemNc?->numero_documento);
                // envio automático à AGT (fe_config.servico.auto), depois do commit, alguns segundos depois
                if ($venda->fe_regime && $venda->fe_estado === 'PRONTO' && ! empty($config?->servico['auto'])) {
                    // R4: o lock único e o push usam o Redis e correm DEPOIS do COMMIT — uma falha aí devolvia 500 com a factura já
                    // gravada (e o utilizador podia voltar a emitir). Regista-se um aviso: o agendador (erp:agt:ciclo) recolhe-a.
                    DB::afterCommit(function () use ($empresa, $venda) {
                        try {
                            CicloAgt::dispatch($empresa)->delay(now()->addSeconds(3));
                        } catch (Throwable $e) {
                            Log::warning('Envio automático à AGT não agendado; o agendador recolhe o documento no próximo ciclo.',
                                ['empresa_id' => $empresa, 'venda_id' => $venda->id, 'erro' => $e->getMessage()]);
                        }
                    });
                }
            }

            return $venda->refresh();
        });
    }

    /** Converte um documento (orçamento/proforma/encomenda -> factura; factura -> nota de crédito). */
    public function converter(Venda $origem, string $destino, array $extra): Venda
    {
        if (! in_array($destino, self::CONVERSOES[$origem->tipo_documento] ?? [], true)) {
            throw new ErroNegocio("Não é possível converter {$origem->tipo_documento} em {$destino}.", 'CONVERSAO_INVALIDA', 422);
        }
        if ($origem->estado === 'ANULADO') {
            throw new ErroNegocio('Não é possível converter um documento anulado.', 'DOCUMENTO_ANULADO', 422);
        }
        if (in_array($origem->tipo_documento, ['OR', 'PF'], true) && $origem->valido_ate && $origem->valido_ate->lt(today()) && empty($extra['ignorar_validade'])) {
            throw new ErroNegocio("Documento expirado em {$origem->valido_ate->toDateString()}. Confirme para converter mesmo assim.", 'DOCUMENTO_EXPIRADO', 422);
        }

        // legado (convertDocument, js/ui_sales.js:2750-2753): factura liquidada, mesmo em parte, não se converte em NC
        if ($destino === 'NC' && ($origem->estado === 'PAGO' && bccomp((string) ($origem->valor_pago ?? '0'), '0.005', 3) > 0
            || bccomp((string) ($origem->valor_pago ?? '0'), '0.01', 2) > 0)) {
            throw new ErroNegocio("Não é possível emitir nota de crédito para a factura {$origem->numero_documento}, que está liquidada: desassocie ou anule primeiro os pagamentos na Tesouraria.",
                'NC_FATURA_PAGA', 422);
        }

        $itens = $origem->itensVenda()->orderBy('id')->get();
        $creditada = $destino === 'NC' ? $this->quantidadesCreditadas($origem) : [];
        $linhas = [];
        foreach ($itens as $i) {
            // NC: o que ainda não foi creditado por outras NC (creditado por produto, abatido às primeiras linhas)
            $jaCreditada = min((float) $i->quantidade, $creditada[$i->produto_id] ?? 0.0);
            if ($destino === 'NC') {
                $creditada[$i->produto_id] = ($creditada[$i->produto_id] ?? 0.0) - $jaCreditada;
            }
            $restante = match (true) {
                $destino === 'NC' => (float) $i->quantidade - $jaCreditada,
                $destino === 'GR' => (float) $i->quantidade - (float) $i->quantidade_entregue,
                $origem->tipo_documento === 'GR' => (float) $i->quantidade - (float) $i->quantidade_faturada - (float) $i->quantidade_devolvida,
                default => (float) $i->quantidade - (float) $i->quantidade_faturada,
            };
            if ($restante > 0.0005) {
                $preco = $origem->codigo_moeda && $origem->codigo_moeda !== ServicoCambios::BASE && $i->preco_unitario_moeda !== null ? $i->preco_unitario_moeda : $i->preco_unitario;
                $linhas[] = ['produto_id' => $i->produto_id, 'quantidade' => $restante, 'preco_unitario' => $preco, 'descricao' => $i->descricao, 'item_origem_id' => $i->id, '_origem' => $i];
            }
        }
        if (! $linhas) {
            throw new ErroNegocio('O documento já foi totalmente convertido.', 'JA_CONVERTIDO', 422);
        }

        return DB::transaction(function () use ($origem, $destino, $extra, $linhas) {
            $nova = $this->emitir(array_merge([
                'tipo_documento' => $destino, 'cliente_id' => $origem->cliente_id, 'data_emissao' => $extra['data_emissao'] ?? now()->toDateString(),
                'linhas' => array_map(fn ($l) => array_diff_key($l, ['_origem' => 1]), $linhas),
                'unidade_negocio_id' => $origem->unidade_negocio_id, 'centro_custo_id' => $origem->centro_custo_id, 'projeto_id' => $origem->projeto_id,
                'condicoes_pagamento' => $origem->condicoes_pagamento,
                'venda_origem_id' => $destino === 'NC' ? $origem->id : null,
                // a moeda passa ao documento gerado; o câmbio reavalia-se na nova data, salvo se era manual (cambioParaConversao)
                'codigo_moeda' => $origem->codigo_moeda ?: ServicoCambios::BASE,
                'taxa_cambio' => $origem->taxa_cambio_manual ? $origem->taxa_cambio : null, 'taxa_cambio_herdada' => (bool) $origem->taxa_cambio_manual,
            ], array_intersect_key($extra, array_flip(['motivo_nota_credito', 'observacoes', 'modo_pagamento', 'plano_pagamentos', 'armazem_id', 'devolucao_mercadoria']))),
                $destino === 'NC' ? null : $origem);

            if ($destino !== 'NC') {
                $this->actualizarOrigemConvertida($origem, $destino, $linhas);
            }

            return $nova;
        });
    }

    /**
     * Factura a partir de várias guias de remessa do mesmo cliente (fillInvoiceFromGuia do legado, M-18): uma só FT com
     * as quantidades por facturar de cada GR (linha a linha, com item_origem_id), sem nova saída de stock (já saiu na guia).
     * Todas as guias ficam ligadas à factura e passam a CONCLUIDO quando totalmente facturadas.
     *
     * @param  list<int>  $guias
     * @param  array<string, mixed>  $extra  data_emissao, observacoes, modo_pagamento, plano_pagamentos
     */
    public function faturarGuias(array $guias, array $extra): Venda
    {
        $guias = array_values(array_unique(array_map('intval', $guias)));
        if (count($guias) < 1) {
            throw new ErroNegocio('Escolha pelo menos uma guia de remessa.', 'SEM_GUIAS', 422);
        }

        return DB::transaction(function () use ($guias, $extra) {
            $docs = Venda::query()->whereIn('id', $guias)->lockForUpdate()->orderBy('data_emissao')->orderBy('id')->get();
            if ($docs->count() !== count($guias)) {
                throw new ErroNegocio('Uma das guias não existe.', 'GUIA_INEXISTENTE', 422);
            }
            $primeira = $docs->first();
            foreach ($docs as $g) {
                if ($g->tipo_documento !== 'GR' || $g->estado === 'ANULADO') {
                    throw new ErroNegocio("{$g->numero_documento}: só se facturam guias de remessa não anuladas.", 'GUIA_INVALIDA', 422);
                }
                if ($g->cliente_id !== $primeira->cliente_id) {
                    throw new ErroNegocio('As guias têm de ser todas do mesmo cliente.', 'GUIAS_CLIENTES_DIFERENTES', 422);
                }
                if (($g->codigo_moeda ?: ServicoCambios::BASE) !== ($primeira->codigo_moeda ?: ServicoCambios::BASE)) {
                    throw new ErroNegocio('As guias têm de estar todas na mesma moeda.', 'GUIAS_MOEDAS_DIFERENTES', 422);
                }
            }
            $linhas = [];
            $porGuia = [];
            foreach ($docs as $g) {
                foreach ($g->itensVenda()->orderBy('id')->get() as $i) {
                    $restante = (float) $i->quantidade - (float) $i->quantidade_faturada - (float) $i->quantidade_devolvida;
                    if ($restante > 0.0005) {
                        $preco = $g->codigo_moeda && $g->codigo_moeda !== ServicoCambios::BASE && $i->preco_unitario_moeda !== null ? $i->preco_unitario_moeda : $i->preco_unitario;
                        $l = ['produto_id' => $i->produto_id, 'quantidade' => $restante, 'preco_unitario' => $preco,
                            'descricao' => $i->descricao, 'item_origem_id' => $i->id, '_origem' => $i];
                        $linhas[] = $l;
                        $porGuia[$g->id][] = $l;
                    }
                }
            }
            if (! $linhas) {
                throw new ErroNegocio('As guias escolhidas já foram totalmente facturadas.', 'JA_CONVERTIDO', 422);
            }
            $observacoes = $extra['observacoes'] ?? ('Guias: '.$docs->pluck('numero_documento')->implode(', '));
            $nova = $this->emitir(array_merge([
                'tipo_documento' => 'FT', 'cliente_id' => $primeira->cliente_id, 'data_emissao' => $extra['data_emissao'] ?? now()->toDateString(),
                'linhas' => array_map(fn ($l) => array_diff_key($l, ['_origem' => 1]), $linhas),
                'unidade_negocio_id' => $primeira->unidade_negocio_id, 'centro_custo_id' => $primeira->centro_custo_id, 'projeto_id' => $primeira->projeto_id,
                'condicoes_pagamento' => $primeira->condicoes_pagamento, 'observacoes' => $observacoes,
                'codigo_moeda' => $primeira->codigo_moeda ?: ServicoCambios::BASE,
                'taxa_cambio' => $primeira->taxa_cambio_manual ? $primeira->taxa_cambio : null, 'taxa_cambio_herdada' => (bool) $primeira->taxa_cambio_manual,
            ], array_intersect_key($extra, array_flip(['modo_pagamento', 'plano_pagamentos']))), $primeira);

            foreach ($docs as $g) {
                if ($g->id !== $primeira->id && isset($porGuia[$g->id])) {   // a primeira já foi ligada por emitir()
                    DB::table('vendas_documentos_relacionados')->insert(['venda_id' => $nova->id, 'venda_relacionada_id' => $g->id, 'empresa_id' => $g->empresa_id]);
                }
                if (isset($porGuia[$g->id])) {
                    $this->actualizarOrigemConvertida($g, 'FT', $porGuia[$g->id]);
                }
            }

            return $nova;
        });
    }

    /**
     * Depois de uma conversão: quantidades entregues/facturadas/devolvidas na origem, encomenda da guia facturada e estado
     * CONCLUIDO quando nada fica por converter.
     *
     * @param  list<array<string, mixed>>  $linhas  com '_origem' (ItemVenda) e 'quantidade'
     */
    private function actualizarOrigemConvertida(Venda $origem, string $destino, array $linhas): void
    {
        $campo = match ($destino) {
            'GR' => 'quantidade_entregue', 'GD' => 'quantidade_devolvida', default => 'quantidade_faturada'
        };
        foreach ($linhas as $l) {
            $l['_origem']->refresh()->update([$campo => (float) $l['_origem']->{$campo} + (float) $l['quantidade']]);
        }
        // FT de uma GR que veio de uma encomenda: a encomenda também fica facturada (senão podia voltar a ser facturada)
        if ($origem->tipo_documento === 'GR' && in_array($destino, ['FT', 'FR'], true)) {
            $ne = Venda::query()->whereIn('id', DB::table('vendas_documentos_relacionados')->where('venda_id', $origem->id)->pluck('venda_relacionada_id'))
                ->where('tipo_documento', 'NE')->first();
            foreach ($ne ? $linhas : [] as $l) {
                $x = $ne->itensVenda()->where('produto_id', $l['produto_id'])->orderBy('id')->first();
                $x?->update(['quantidade_faturada' => (float) $x->quantidade_faturada + (float) $l['quantidade']]);
            }
            if ($ne && ! $ne->itensVenda()->get()->contains(fn ($x) => (float) $x->quantidade - (float) $x->quantidade_faturada > 0.0005)) {
                $ne->update(['estado' => 'CONCLUIDO']);
            }
        }
        // concluído quando tudo foi facturado (ou, numa GR, facturado ou devolvido)
        $pendente = $origem->itensVenda()->get()->contains(fn ($x) => (float) $x->quantidade - (float) $x->quantidade_faturada
            - ($origem->tipo_documento === 'GR' ? (float) $x->quantidade_devolvida : 0) > 0.0005);
        if (! $pendente) {
            $origem->update(['estado' => 'CONCLUIDO']);
        }
    }

    /** Anula um documento NÃO fiscal ainda não convertido. Os fiscais corrigem-se com nota de crédito. */
    public function anular(Venda $venda): Venda
    {
        if ($venda->eFiscal()) {
            throw new ErroNegocio('Documentos fiscais não se anulam: emita uma nota de crédito.', 'DOCUMENTO_SELADO', 422);
        }
        if (($venda->estado === 'CONCLUIDO' && $venda->tipo_documento !== 'GD')
            || $venda->itensVenda()->where(fn ($q) => $q->where('quantidade_faturada', '>', 0)->orWhere('quantidade_devolvida', '>', 0))->exists()) {
            throw new ErroNegocio('O documento já foi convertido: não pode ser anulado.', 'DOCUMENTO_CONVERTIDO', 422);
        }
        if ($venda->estado === 'ANULADO') {
            throw new ErroNegocio('O documento já está anulado.', 'DOCUMENTO_ANULADO', 422);
        }
        if ($venda->contabilizado) {
            throw new ErroNegocio('A guia está contabilizada: descontabilize-a primeiro (estorno).', 'DOCUMENTO_CONTABILIZADO', 422);
        }

        return DB::transaction(function () use ($venda) {
            if (in_array($venda->tipo_documento, ['GR', 'GD'], true)) {
                $this->stockVendas->reverter($venda, 'anulação');
                // a origem (encomenda da GR, GR da GD) volta a ter a quantidade por entregar/devolver
                $origem = Venda::query()->whereIn('id', DB::table('vendas_documentos_relacionados')->where('venda_id', $venda->id)->pluck('venda_relacionada_id'))->first();
                $campo = $venda->tipo_documento === 'GR' ? 'quantidade_entregue' : 'quantidade_devolvida';
                foreach ($origem ? $venda->itensVenda()->get() : [] as $i) {
                    $o = $origem->itensVenda()->where('produto_id', $i->produto_id)->where($campo, '>', 0)->orderBy('id')->first();
                    $o?->update([$campo => max(0, (float) $o->{$campo} - (float) $i->quantidade)]);
                }
                $origem?->estado === 'CONCLUIDO' && $origem->update(['estado' => 'PENDENTE']);
            }
            $venda->update(['estado' => 'ANULADO', 'valor_pendente' => '0.00']);

            return $venda;
        });
    }

    /**
     * Moeda e câmbio do documento. A NC herda os da factura de origem (o contravalor em Kz tem de anular o da factura).
     *
     * @return array{codigo: string, taxa: string, taxa_id: ?int, manual: bool, estrangeira: bool}
     */
    private function resolverMoeda(array $d, int $empresa, string $data, ?Venda $origemNc, string $tipo = 'FT'): array
    {
        $base = ['taxa' => '1', 'taxa_id' => null, 'manual' => false, 'estrangeira' => false];
        if ($origemNc) {
            $codigo = $origemNc->codigo_moeda ?: ServicoCambios::BASE;

            return $codigo === ServicoCambios::BASE ? ['codigo' => $codigo] + $base
                : ['codigo' => $codigo, 'taxa' => (string) $origemNc->taxa_cambio, 'taxa_id' => $origemNc->taxa_cambio_id,
                    'manual' => (bool) $origemNc->taxa_cambio_manual, 'estrangeira' => true];
        }
        $codigo = strtoupper($d['codigo_moeda'] ?? ServicoCambios::BASE);
        if ($codigo === ServicoCambios::BASE) {
            return ['codigo' => $codigo] + $base;
        }
        if (! empty($d['taxa_cambio'])) {
            // decisão 9: câmbio manual dentro da tolerância face ao câmbio do dia (o herdado numa conversão já foi validado na origem)
            if (empty($d['taxa_cambio_herdada'])) {
                $this->cambios->validarManual($empresa, $codigo, $data, $d['taxa_cambio'], "Vendas {$tipo}");
            }

            return ['codigo' => $codigo, 'taxa' => number_format((float) $d['taxa_cambio'], 6, '.', ''), 'taxa_id' => null, 'manual' => true, 'estrangeira' => true];
        }
        $c = $this->cambios->obter($empresa, $codigo, $data)
            ?? throw new ErroNegocio("Não há câmbio de {$codigo} registado até {$data}. Registe o câmbio ou indique-o manualmente.", 'CAMBIO_EM_FALTA', 422);

        return ['codigo' => $codigo, 'taxa' => $c['taxa'], 'taxa_id' => $c['id'], 'manual' => false, 'estrangeira' => true];
    }

    /**
     * @param  list<array<string, mixed>>  $linhas
     * @param  bool  $verificarPreco  um preço diferente do da ficha exige vendas_alterar_preco (devolvido em $alterados)
     * @param  list<array{produto: string, preco_ficha: string, preco: string}>  $alterados
     * @return list<array<string, mixed>>
     */
    private function prepararLinhas(array $linhas, bool $fiscal, ?ConfigFaturacaoEletronica $config, string $taxaCambio,
        bool $verificarPreco = false, array &$alterados = []): array
    {
        if (! $linhas) {
            throw new ErroNegocio('O documento tem de ter pelo menos uma linha.', 'SEM_LINHAS', 422);
        }
        $produtos = Produto::query()->whereIn('id', array_column($linhas, 'produto_id'))->get()->keyBy('id');
        $saida = [];
        foreach ($linhas as $i => $l) {
            $n = $i + 1;
            $p = $produtos[$l['produto_id']] ?? throw new ErroNegocio("Linha {$n}: produto inexistente.", 'PRODUTO_INEXISTENTE', 422);
            if ($p->bloqueado) {
                throw new ErroNegocio("Linha {$n}: o produto {$p->codigo} está bloqueado.", 'PRODUTO_BLOQUEADO', 422);
            }
            if ((float) $l['quantidade'] <= 0) {
                throw new ErroNegocio("Linha {$n}: a quantidade tem de ser positiva.", 'QUANTIDADE_INVALIDA', 422);
            }
            // preço na moeda do documento; sem preço indicado, o da ficha (em Kz) convertido ao câmbio do documento
            $precoFicha = CalculadoraDocumento::arredondar(bcdiv((string) ($p->preco_unitario ?? 0), $taxaCambio, 8));
            $preco = isset($l['preco_unitario']) && $l['preco_unitario'] !== '' ? $l['preco_unitario'] : $precoFicha;
            if ((float) $preco < 0) {
                throw new ErroNegocio("Linha {$n}: o preço não pode ser negativo.", 'PRECO_INVALIDO', 422);
            }
            if ($verificarPreco && bccomp(number_format((float) $preco, 2, '.', ''), $precoFicha, 2) !== 0) {
                if (! Gate::any(['vendas_alterar_preco'])) {
                    throw new ErroNegocio("Linha {$n}: o preço de {$p->codigo} ({$preco}) é diferente do da ficha ({$precoFicha}). Só quem tem a permissão «Alterar o preço de venda» o pode alterar.",
                        'PRECO_ALTERADO_SEM_PERMISSAO', 403, ['linha' => $n, 'preco_ficha' => $precoFicha]);
                }
                $alterados[] = ['produto' => (string) $p->codigo, 'preco_ficha' => $precoFicha, 'preco' => number_format((float) $preco, 2, '.', '')];
            }
            $taxa = (string) ($p->taxa_imposto ?? '0');   // a taxa vem do produto (paridade: campo IVA só de leitura)
            if ($fiscal && (float) $taxa === 0.0 && ! ($p->codigo_isencao_fe ?: $config?->isencao_padrao)) {
                throw new ErroNegocio("Linha {$n}: o produto {$p->codigo} tem IVA 0% sem motivo de isenção AGT (código M).", 'ISENCAO_EM_FALTA', 422);
            }
            $saida[] = ['produto_id' => $p->id, 'quantidade' => (string) $l['quantidade'], 'preco_unitario' => number_format((float) $preco, 2, '.', ''),
                'taxa_imposto' => $taxa, 'descricao' => $l['descricao'] ?? $p->nome, 'observacoes' => $l['observacoes'] ?? null];
        }

        return $saida;
    }

    /**
     * Decisão 10 (POS): desconto comercial = arred(bruto × % / 100), em que bruto = Σ arred(qtd × preço com IVA); o resto da
     * diferença para o total do documento é o arredondamento AGT (base e IVA por excesso ao cêntimo). Antes, tudo ia para
     * «desconto», e uma venda sem desconto ficava com 0,01-0,02 de desconto.
     *
     * @param  list<array<string, mixed>>  $linhasKz  preços com IVA (antes de passarem à base)
     * @return array{desconto: string, arredondamento_agt: string}
     */
    public static function descontoEArredondamentoPos(array $linhasKz, string $percentagem, string $total): array
    {
        $bruto = array_reduce($linhasKz, fn ($c, $l) => bcadd($c, CalculadoraDocumento::arredondar(bcmul((string) $l['quantidade'], (string) $l['preco_unitario'], 8)), 2), '0.00');
        $desconto = CalculadoraDocumento::arredondar(bcdiv(bcmul($bruto, number_format((float) $percentagem, 4, '.', ''), 8), '100', 8));

        return ['desconto' => $desconto, 'arredondamento_agt' => bcsub(bcsub($bruto, $desconto, 2), $total, 2)];
    }

    /** Nota de crédito (js/ui_sales.js:1718-1791; saldoCreditavel facturacao_agt.js:473-480). */
    private function validarNotaCredito(array $d, Terceiro $cliente, string $totalNc): Venda
    {
        if (empty($d['motivo_nota_credito'])) {
            throw new ErroNegocio('Indique o motivo da nota de crédito.', 'NC_SEM_MOTIVO', 422);
        }
        $origem = Venda::query()->lockForUpdate()->find($d['venda_origem_id'] ?? 0)
            ?? throw new ErroNegocio('Indique a factura de referência da nota de crédito.', 'NC_SEM_REFERENCIA', 422);
        if (! in_array($origem->tipo_documento, ['FT', 'FR'], true) || $origem->cliente_id !== $cliente->id) {
            throw new ErroNegocio('A referência tem de ser uma factura ou factura-recibo do mesmo cliente.', 'NC_REFERENCIA_INVALIDA', 422);
        }
        $disponivel = bcsub((string) $origem->total_bruto, $this->estado->creditado($origem), 2);
        // legado (saveSale, js/ui_sales.js:1725-1728): factura PAGA não admite NC — anule primeiro o recibo/pagamento.
        // Só conta o pago por recibos/tesouraria (valor_pago): uma factura toda creditada por NC cai no saldo creditável abaixo.
        if ($origem->estado === 'PAGO' && bccomp((string) ($origem->valor_pago ?? '0'), '0.005', 3) > 0) {
            throw new ErroNegocio("Não é possível emitir nota de crédito para a factura {$origem->numero_documento}, que está paga: anule primeiro o recibo/pagamento associado.",
                'NC_FATURA_PAGA', 422);
        }
        $disponivel = bcsub((string) $origem->total_bruto, $this->estado->creditado($origem), 2);
        if (bccomp($totalNc, $disponivel, 2) > 0) {
            throw new ErroNegocio("A nota de crédito ({$totalNc}) excede o saldo creditável da factura ({$disponivel}).", 'NC_EXCEDE_SALDO', 422,
                ['saldo_creditavel' => $disponivel]);
        }

        return $origem;
    }

    /**
     * Pré-validação AGT antes de reservar o n.º da série (legado: js/ui_sales.js:1798-1803, 2943-2948 — «Nada foi gravado»).
     * Antes, o documento era numerado e selado com fe_estado = COM_ERROS e, sendo fiscal, só se corrigia com uma NC.
     */
    private function preValidarAgt(int $empresa, string $tipo, string $data, Terceiro $cliente, array $linhas, array $linhasKz, array $calculo,
        ?array $calculoMoeda, array $moeda, array $d, ?ConfigFaturacaoEletronica $config, ?Venda $origemNc): void
    {
        $prov = (new Venda)->forceFill([
            'empresa_id' => $empresa, 'cliente_id' => $cliente->id, 'tipo_documento' => $tipo, 'data_emissao' => $data.' '.now()->format('H:i:s'),
            'total_liquido' => $calculo['total_liquido'], 'total_imposto' => $calculo['total_imposto'], 'total_bruto' => $calculo['total_bruto'],
            'codigo_moeda' => $moeda['codigo'], 'taxa_cambio' => $moeda['estrangeira'] ? $moeda['taxa'] : null,
            'total_bruto_moeda' => $calculoMoeda['total_bruto'] ?? null, 'fe_data_entrada_sistema' => now(),
            'motivo_nota_credito' => $tipo === 'NC' ? ($d['motivo_nota_credito'] ?? null) : null,
        ]);
        $produtos = Produto::query()->whereIn('id', array_column($linhas, 'produto_id'))->get()->keyBy('id');
        $itens = new Collection;
        foreach ($linhas as $i => $l) {
            $itens->push((new ItemVenda)->forceFill([
                'produto_id' => $l['produto_id'], 'descricao' => $l['descricao'], 'quantidade' => $l['quantidade'],
                'preco_unitario' => CalculadoraDocumento::arredondar($linhasKz[$i]['preco_unitario']), 'taxa_imposto' => $l['taxa_imposto'],
                'total' => $calculo['linhas'][$i]['total'], 'total_linha' => $calculo['linhas'][$i]['valor'],
                'preco_unitario_moeda' => $calculoMoeda ? $l['preco_unitario'] : null,
            ])->setRelation('produto', $produtos[$l['produto_id']] ?? null));
        }
        [$erros, $avisos] = $this->selagem->preValidar($prov, $itens, $config, $origemNc?->numero_documento);
        if ($erros) {
            throw new ErroNegocio('O documento não cumpre as regras da AGT. Corrija antes de emitir: nada foi gravado.', 'AGT_PRE_VALIDACAO', 422,
                ['erros' => $erros, 'avisos' => $avisos]);
        }
    }

    /**
     * Linhas da NC presas às da factura de origem: o produto tem de existir na origem e o preço (na moeda do documento)
     * e a taxa de IVA são os da linha de origem — não os do pedido nem a taxa actual do produto. A linha de origem é
     * a indicada em item_origem_id (conversão) ou a primeira com o mesmo produto.
     *
     * @param  list<array<string, mixed>>  $linhas  já preparadas
     * @param  list<array<string, mixed>>  $pedido  linhas do pedido (para item_origem_id)
     * @return list<array<string, mixed>>
     */
    private function linhasDaOrigemNc(array $linhas, array $pedido, Venda $origem, bool $estrangeira): array
    {
        $itens = $origem->itensVenda()->orderBy('id')->get();
        foreach ($linhas as $i => $l) {
            $n = $i + 1;
            $idOrigem = $pedido[$i]['item_origem_id'] ?? null;
            $item = $idOrigem ? $itens->firstWhere('id', (int) $idOrigem) : $itens->firstWhere('produto_id', $l['produto_id']);
            if (! $item || (int) $item->produto_id !== (int) $l['produto_id']) {
                throw new ErroNegocio("Linha {$n}: o produto não consta da factura de origem {$origem->numero_documento}.", 'NC_LINHA_SEM_ORIGEM', 422);
            }
            $preco = $estrangeira && $item->preco_unitario_moeda !== null ? $item->preco_unitario_moeda : $item->preco_unitario;
            $linhas[$i]['preco_unitario'] = number_format((float) $preco, 2, '.', '');
            $linhas[$i]['taxa_imposto'] = (string) ($item->taxa_imposto ?? '0');
            if (! $estrangeira && $item->total_linha !== null && (float) $item->quantidade > 0) {
                $linhas[$i]['_origem'] = $this->valoresOrigemNc($item, (string) $l['quantidade']);
            }
        }

        return $linhas;
    }

    /**
     * Valores em Kz da linha da NC a partir dos da linha de origem, e não de qtd × preço: nas FR/FT do POS o preço
     * guardado é a base arredondada (ou, nas migradas, o preço com IVA), e o recálculo dava um total ≠ da factura
     * (NC recusada com NC_EXCEDE_SALDO ou saldo por creditar). Crédito total: os valores da origem; parcial:
     * valor = arred(total_linha × q / q_origem) e IVA por excesso ao cêntimo.
     *
     * @return array{valor: string, imposto: string}
     */
    private function valoresOrigemNc(ItemVenda $item, string $quantidade): array
    {
        $valorOrigem = number_format((float) $item->total_linha, 2, '.', '');
        $qOrigem = number_format((float) $item->quantidade, 3, '.', '');
        $q = number_format((float) $quantidade, 3, '.', '');
        if (bccomp($q, $qOrigem, 3) === 0) {
            $imposto = $item->total !== null ? bcsub(number_format((float) $item->total, 2, '.', ''), $valorOrigem, 2)
                : CalculadoraDocumento::excessoCentimo(bcdiv(bcmul($valorOrigem, (string) $item->taxa_imposto, 8), '100', 8));

            return ['valor' => $valorOrigem, 'imposto' => bccomp($imposto, '0', 2) < 0 ? '0.00' : $imposto];
        }
        $valor = CalculadoraDocumento::arredondar(bcdiv(bcmul($valorOrigem, $q, 8), $qOrigem, 8), 2);

        return ['valor' => $valor, 'imposto' => CalculadoraDocumento::excessoCentimo(bcdiv(bcmul($valor, (string) ($item->taxa_imposto ?? '0'), 8), '100', 8))];
    }

    /** Substitui no cálculo da NC (Kz) os valores das linhas presas à origem e refaz os totais. */
    private function calculoDaOrigemNc(array $linhas, array $calculo): array
    {
        $liquido = $imposto = '0.00';
        foreach ($linhas as $i => $l) {
            if (isset($l['_origem'])) {
                $calculo['linhas'][$i] = ['valor' => $l['_origem']['valor'], 'imposto' => $l['_origem']['imposto'],
                    'total' => bcadd($l['_origem']['valor'], $l['_origem']['imposto'], 2)];
            }
            $liquido = bcadd($liquido, $calculo['linhas'][$i]['valor'], 2);
            $imposto = bcadd($imposto, $calculo['linhas'][$i]['imposto'], 2);
        }

        return ['linhas' => $calculo['linhas'], 'total_liquido' => $liquido, 'total_imposto' => $imposto, 'total_bruto' => bcadd($liquido, $imposto, 2)];
    }

    /** Quantidade da NC por produto ≤ quantidade da origem − já creditada por outras NC (não anuladas). Chamado com a origem bloqueada. */
    private function exigirQuantidadesNc(Venda $origem, array $linhas): void
    {
        $origemQt = $origem->itensVenda()->get()->groupBy('produto_id')->map(fn ($g) => $g->sum(fn ($x) => (float) $x->quantidade));
        $creditada = $this->quantidadesCreditadas($origem);
        $pedida = [];
        foreach ($linhas as $l) {
            $pedida[$l['produto_id']] = ($pedida[$l['produto_id']] ?? 0) + (float) $l['quantidade'];
        }
        foreach ($pedida as $produto => $qt) {
            $resta = (float) ($origemQt[$produto] ?? 0) - (float) ($creditada[$produto] ?? 0);
            if ($qt - $resta > 0.0005) {
                throw new ErroNegocio('A quantidade a creditar ('.round($qt, 3).') excede a ainda não creditada na factura ('.round(max(0, $resta), 3).').',
                    'NC_QUANTIDADE_EXCEDIDA', 422, ['produto_id' => (int) $produto, 'disponivel' => round(max(0, $resta), 3)]);
            }
        }
    }

    /** @return array<int, float> quantidade já creditada por produto (NC não anuladas sobre a factura) */
    private function quantidadesCreditadas(Venda $origem): array
    {
        return DB::table('vendas_documentos_relacionados as r')->join('vendas as nc', 'nc.id', '=', 'r.venda_id')
            ->join('itens_venda as i', 'i.venda_id', '=', 'nc.id')
            ->where('r.empresa_id', $origem->empresa_id)->where('r.venda_relacionada_id', $origem->id)
            ->where('nc.tipo_documento', 'NC')->where(fn ($q) => $q->whereNull('nc.estado')->orWhere('nc.estado', '<>', 'ANULADO'))
            ->groupBy('i.produto_id')->selectRaw('i.produto_id, SUM(i.quantidade) AS q')->pluck('q', 'produto_id')
            ->map(fn ($q) => (float) $q)->all();
    }

    /**
     * Condições de pagamento e validade (CondPagVenda, js/condicoes_pagamento_vendas.js:207-226).
     *
     * @return array<string, mixed>
     */
    private function condicoesPagamento(string $tipo, array $d, string $data): array
    {
        $saida = [];
        if (in_array($tipo, ['OR', 'PF'], true)) {
            $dias = $d['dias_validade'] ?? null;
            $saida['dias_validade'] = $dias;
            $saida['valido_ate'] = $d['valido_ate'] ?? ($dias ? date('Y-m-d', strtotime("{$data} +{$dias} days")) : null);
        }
        if (in_array($tipo, ['NC', 'FR'], true)) {
            return $saida;
        }
        $modo = $d['modo_pagamento'] ?? 'PRONTO';
        $saida['modo_pagamento'] = $modo;
        if ($modo === 'PRONTO') {
            return $saida;
        }
        $plano = $d['plano_pagamentos'] ?? [];
        if (! $plano) {
            throw new ErroNegocio('Defina pelo menos uma prestação no plano de pagamentos.', 'PLANO_PAGAMENTOS_INVALIDO', 422);
        }
        $soma = '0';
        foreach ($plano as $p) {
            if ((float) ($p['percentagem'] ?? 0) <= 0) {
                throw new ErroNegocio('Todas as prestações têm de ter percentagem positiva.', 'PLANO_PAGAMENTOS_INVALIDO', 422);
            }
            if ($modo === 'PRAZO' && empty($p['data'])) {
                throw new ErroNegocio('No pagamento a prazo todas as prestações têm de ter data.', 'PLANO_PAGAMENTOS_INVALIDO', 422);
            }
            $soma = bcadd($soma, (string) $p['percentagem'], 4);
        }
        if (abs((float) $soma - 100) > 0.005) {
            throw new ErroNegocio("As percentagens do plano de pagamentos somam {$soma}% (têm de somar 100%).", 'PLANO_PAGAMENTOS_INVALIDO', 422);
        }
        $saida['plano_pagamentos'] = $plano;
        $datas = array_filter(array_column($plano, 'data'));
        $saida['data_vencimento'] = $datas ? max($datas) : null;

        return $saida;
    }
}
