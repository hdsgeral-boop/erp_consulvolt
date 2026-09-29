<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigFaturacaoEletronica;
use App\Models\ItemVenda;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Contabilidade\ServicoExercicios;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Documentos comerciais (saveSale / executeConversion do legado, js/ui_sales.js:1708, 2893), com as correcções:
 *   - numeração por série, sem colisões nem condição de corrida (ServicoSeries);
 *   - totais AGT em decimal exacto (CalculadoraDocumento);
 *   - tudo numa única transacção (o legado gravava cabeçalho, linhas, stock e recibo separadamente);
 *   - estado persistido e coerente (o legado quase nunca gravava `status`);
 *   - a nota de crédito abate o pendente da factura de origem (o legado não o fazia);
 *   - validação de quantidades, preços, produtos bloqueados e condições de pagamento.
 * Guias (GR/GD) e baixa de stock chegam com o módulo Logística (movimentos de inventário).
 */
final class ServicoDocumentosVenda
{
    /** Conversões permitidas (convertDocument, js/ui_sales.js:2730), sem as que o legado não suportava. */
    public const CONVERSOES = ['OR' => ['NE', 'FT'], 'PF' => ['NE', 'FT'], 'NE' => ['FT'], 'FT' => ['NC'], 'FR' => ['NC']];

    private const TIPOS_EMITIVEIS = ['FT', 'FR', 'NC', 'OR', 'PF', 'NE'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoSeries $series,
        private readonly ServicoSelagemAgt $selagem,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoRecibosVenda $recibos,
        private readonly ServicoEstadoVenda $estado,
    ) {}

    /**
     * @param  array<string, mixed>  $d  tipo_documento, cliente_id, data_emissao, linhas[{produto_id, quantidade, preco_unitario?, descricao?}],
     *                                   venda_origem_id (NC), motivo_nota_credito (NC), conta_disponibilidade + meio_pagamento (FR),
     *                                   modo_pagamento/plano_pagamentos, dias_validade/valido_ate, observacoes, UN/CC/projecto
     */
    public function emitir(array $d, ?Venda $origemConversao = null): Venda
    {
        $empresa = $this->contexto->obrigatorio();
        $tipo = $d['tipo_documento'];
        if (! in_array($tipo, self::TIPOS_EMITIVEIS, true)) {
            throw new ErroNegocio("Tipo de documento {$tipo} não emitível neste módulo (guias: módulo Logística).", 'TIPO_NAO_SUPORTADO', 422);
        }
        $fiscal = in_array($tipo, Venda::FISCAIS, true);
        $data = substr($d['data_emissao'], 0, 10);
        $this->exercicios->exigirAberto($empresa, $data);

        $cliente = Terceiro::query()->findOrFail($d['cliente_id']);
        if (! $cliente->eCliente() || ! $cliente->codigo_conta) {
            throw new ErroNegocio('O cliente tem de estar registado como cliente e ter conta contabilística.', 'CLIENTE_INVALIDO', 422);
        }
        $config = ConfigFaturacaoEletronica::query()->first();
        $linhas = $this->prepararLinhas($d['linhas'], $fiscal, $config);
        $calculo = CalculadoraDocumento::calcular($linhas);

        if ($tipo === 'FR') {
            $this->recibos->exigirContaDisponibilidade($d['conta_disponibilidade']
                ?? throw new ErroNegocio('Indique a conta de disponibilidade (caixa/banco) da factura-recibo.', 'CONTA_DISPONIBILIDADE_EM_FALTA', 422));
        }
        $condicoes = $this->condicoesPagamento($tipo, $d, $data);

        return DB::transaction(function () use ($d, $tipo, $fiscal, $data, $cliente, $linhas, $calculo, $config, $condicoes, $empresa, $origemConversao) {
            // dentro da transacção e com a factura de origem bloqueada: duas NC em simultâneo não excedem o saldo
            $origemNc = $tipo === 'NC' ? $this->validarNotaCredito($d, $cliente, $calculo['total_bruto']) : null;
            $reserva = $this->series->reservar($empresa, $tipo, $data, $fiscal);
            $bruto = $calculo['total_bruto'];
            $venda = Venda::create(array_merge([
                'cliente_id' => $cliente->id, 'tipo_documento' => $tipo, 'numero_documento' => $reserva['numero_documento'],
                'data_emissao' => $data.' '.now()->format('H:i:s'),
                'total_liquido' => $calculo['total_liquido'], 'total_imposto' => $calculo['total_imposto'], 'total_bruto' => $bruto,
                'valor_pago' => $tipo === 'FR' ? $bruto : '0.00', 'valor_pendente' => in_array($tipo, ['FR', 'NC'], true) ? '0.00' : $bruto,
                'estado' => match ($tipo) {
                    'FR' => 'PAGO', 'NC' => 'CONCLUIDO', default => 'PENDENTE'
                },
                'contabilizado' => false, 'codigo_moeda' => 'AOA',
                'serie_faturacao_eletronica_id' => $reserva['serie']->id, 'fe_serie' => $reserva['serie']->codigo, 'fe_numero' => $reserva['numero'],
                'fe_data_entrada_sistema' => now(),
                'motivo_nota_credito' => $tipo === 'NC' ? $d['motivo_nota_credito'] : null,
                'meio_pagamento' => $tipo === 'FR' ? ($d['meio_pagamento'] ?? 'NUMERARIO') : null,
                'observacoes' => $d['observacoes'] ?? null, 'condicoes_pagamento' => $d['condicoes_pagamento'] ?? null,
                'unidade_negocio_id' => $d['unidade_negocio_id'] ?? null, 'centro_custo_id' => $d['centro_custo_id'] ?? null, 'projeto_id' => $d['projeto_id'] ?? null,
            ], $condicoes));

            $itens = new Collection;
            foreach ($linhas as $i => $l) {
                $itens->push(ItemVenda::create([
                    'venda_id' => $venda->id, 'produto_id' => $l['produto_id'], 'descricao' => $l['descricao'], 'quantidade' => $l['quantidade'],
                    'preco_unitario' => $l['preco_unitario'], 'taxa_imposto' => $l['taxa_imposto'], 'total' => $calculo['linhas'][$i]['total'],
                    'total_linha' => $calculo['linhas'][$i]['valor'], 'observacoes' => $l['observacoes'] ?? null,
                ]));
            }

            $relacionada = $origemNc ?? $origemConversao;
            if ($relacionada) {
                DB::table('vendas_documentos_relacionados')->insert(['venda_id' => $venda->id, 'venda_relacionada_id' => $relacionada->id, 'empresa_id' => $empresa]);
            }
            if ($origemNc) {
                $this->estado->recalcular($origemNc->refresh());
            }
            if ($tipo === 'FR') {
                $this->recibos->criarDaFacturaRecibo($venda, $d['conta_disponibilidade'], $d['meio_pagamento'] ?? 'NUMERARIO', $d['referencia_pagamento'] ?? null);
            }
            if ($fiscal) {
                $this->selagem->selar($venda, $itens, $config, $origemNc?->numero_documento);
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

        $itens = $origem->itensVenda()->orderBy('id')->get();
        $linhas = [];
        foreach ($itens as $i) {
            $restante = $destino === 'NC' ? (float) $i->quantidade : (float) $i->quantidade - (float) $i->quantidade_faturada;
            if ($restante > 0.0005) {
                $linhas[] = ['produto_id' => $i->produto_id, 'quantidade' => $restante, 'preco_unitario' => $i->preco_unitario, 'descricao' => $i->descricao, '_origem' => $i];
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
            ], array_intersect_key($extra, array_flip(['motivo_nota_credito', 'observacoes', 'modo_pagamento', 'plano_pagamentos']))), $destino === 'NC' ? null : $origem);

            if ($destino !== 'NC') {
                foreach ($linhas as $l) {
                    $l['_origem']->update(['quantidade_faturada' => (float) $l['_origem']->quantidade_faturada + (float) $l['quantidade']]);
                }
                $origem->update(['estado' => 'CONCLUIDO']);
            }

            return $nova;
        });
    }

    /** Anula um documento NÃO fiscal ainda não convertido. Os fiscais corrigem-se com nota de crédito. */
    public function anular(Venda $venda): Venda
    {
        if ($venda->eFiscal()) {
            throw new ErroNegocio('Documentos fiscais não se anulam: emita uma nota de crédito.', 'DOCUMENTO_SELADO', 422);
        }
        if ($venda->estado === 'CONCLUIDO' || $venda->itensVenda()->where('quantidade_faturada', '>', 0)->exists()) {
            throw new ErroNegocio('O documento já foi convertido: não pode ser anulado.', 'DOCUMENTO_CONVERTIDO', 422);
        }
        $venda->update(['estado' => 'ANULADO', 'valor_pendente' => '0.00']);

        return $venda;
    }

    /**
     * @param  list<array<string, mixed>>  $linhas
     * @return list<array<string, mixed>>
     */
    private function prepararLinhas(array $linhas, bool $fiscal, ?ConfigFaturacaoEletronica $config): array
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
            $preco = $l['preco_unitario'] ?? $p->preco_unitario ?? 0;
            if ((float) $preco < 0) {
                throw new ErroNegocio("Linha {$n}: o preço não pode ser negativo.", 'PRECO_INVALIDO', 422);
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
        if (bccomp($totalNc, $disponivel, 2) > 0) {
            throw new ErroNegocio("A nota de crédito ({$totalNc}) excede o saldo creditável da factura ({$disponivel}).", 'NC_EXCEDE_SALDO', 422,
                ['saldo_creditavel' => $disponivel]);
        }

        return $origem;
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
