import { formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { dataDoc, type DadosDocumentoComercial, type ItemDocumento } from '@/modulos/vendas/impressao/documentoComercial';
import { numero } from './calculos';
import { rotuloEstado } from './estados';
import type { RefTerceiro } from './referencias';
import { numeroOuId, type ContratoCompra, type EncomendaCompra, type FaturaCompra, type ItemCompra, type PedidoCompra, type PropostaCompra, type RececaoCompra } from './tipos';

/**
 * Documentos de Compras impressos com o motor comum, no formato do documento comercial (A4 retrato): pedido interno,
 * proposta de fornecedor, nota de encomenda, factura de fornecedor, guia de recepção e ficha de contrato.
 */

function fornecedor(id: number, f?: RefTerceiro | null) {
  return { rotulo: 'Fornecedor', nome: f?.nome?.trim() || `#${id}`, nif: f?.nif ?? null };
}

/** Linhas de compra → itens do documento (preço e total em Kz; o total da linha é a base sem IVA). */
export function itensCompra(linhas: ItemCompra[] | undefined, opcoes: { precos?: boolean } = {}): ItemDocumento[] {
  return (linhas ?? []).map((l) => {
    const total = l.total_kz ?? l.total ?? (l.preco_unitario ? (numero(l.quantidade) * numero(l.preco_unitario)).toFixed(2) : null);
    return {
      codigo: l.produto?.codigo ?? null,
      descricao: l.produto?.nome ?? l.descricao ?? `Produto #${l.produto_id}`,
      notas: l.produto?.nome && l.descricao && l.descricao !== l.produto.nome ? l.descricao : null,
      quantidade: l.quantidade,
      preco: opcoes.precos === false ? undefined : l.preco_unitario,
      taxa: opcoes.precos === false ? undefined : l.taxa_imposto ?? undefined,
      total: opcoes.precos === false ? undefined : total,
    };
  });
}

const moedaInfo = (codigo: string | null, cambio: string | null): string | null =>
  codigo && codigo !== 'AOA' ? `${codigo}${cambio ? ` (câmbio ${formatarNumero(cambio)})` : ''}` : 'AOA';

export function dadosPedidoCompra(p: PedidoCompra): DadosDocumentoComercial {
  const etapas = p.deliberacao?.etapas ?? [];
  return {
    tipo: 'Pedido interno de compra',
    numero: numeroOuId(p.numero_pedido, p.id),
    estado: p.estado === 'ANULADO' ? `ANULADO${p.motivo_anulacao ? ` — ${p.motivo_anulacao}` : ''}` : null,
    avisos: ['Documento interno — não serve de encomenda'],
    entidade: { rotulo: 'Requerente', nome: p.nome_requerente },
    meta: [
      ['Data', dataDoc(p.data)],
      ['Entrega pretendida', dataDoc(p.data_entrega)],
      ['Estado', rotuloEstado(p.estado)],
      ['Criado por', p.criado_por],
    ],
    itens: itensCompra(p.linhas),
    impostos: false,
    totais: { total: p.valor_estimado?.valor ?? undefined, rotuloTotal: 'Valor estimado' },
    extenso: false,
    blocos: [
      ['Descrição', p.descricao],
      ['Observações', p.observacoes],
      [
        'Deliberação',
        etapas
          .map((e) => `${e.nome}: ${rotuloEstado(e.estado)}${e.por ? ` — ${e.por}` : ''}${e.em ? ` (${formatarDataHora(e.em)})` : ''}${e.nota ? ` · ${e.nota}` : ''}`)
          .join('\n') || null,
      ],
    ],
    assinaturas: ['Requerente', 'Aprovação'],
  };
}

export function dadosPropostaCompra(c: PropostaCompra): DadosDocumentoComercial {
  return {
    tipo: 'Proposta de fornecedor',
    numero: numeroOuId(c.numero_proposta, c.id),
    estado: c.estado === 'ANULADA' ? 'ANULADA' : null,
    entidade: fornecedor(c.fornecedor_id, c.fornecedor),
    meta: [
      ['Referência', c.referencia],
      ['Data', dataDoc(c.data)],
      ['Prazo de entrega', dataDoc(c.data_entrega)],
      ['Pedido', `#${c.pedido_compra_id}`],
      ['Moeda', moedaInfo(c.codigo_moeda, c.taxa_cambio)],
      ['Estado', rotuloEstado(c.estado)],
    ],
    itens: itensCompra(c.linhas),
    totais: { subtotal: c.montante_total, imposto: c.total_imposto ?? undefined, total: c.total_com_imposto ?? c.montante_total },
    moeda: 'AOA',
  };
}

export function dadosEncomendaCompra(e: EncomendaCompra): DadosDocumentoComercial {
  return {
    tipo: 'Nota de encomenda',
    numero: numeroOuId(e.numero_encomenda, e.id),
    via: 'Original',
    estado: e.estado === 'ANULADA' ? `ANULADA${e.motivo_anulacao ? ` — ${e.motivo_anulacao}` : ''}` : null,
    entidade: fornecedor(e.fornecedor_id, e.fornecedor),
    meta: [
      ['Data', dataDoc(e.data)],
      ['Entrega prevista', dataDoc(e.data_entrega_prevista)],
      ['Moeda', moedaInfo(e.codigo_moeda, e.taxa_cambio)],
      ['Pedido', e.pedido_compra_id ? `#${e.pedido_compra_id}` : null],
      ['Proposta', e.cotacao_compra_id ? `#${e.cotacao_compra_id}` : null],
      ['Contrato', e.contrato_fornecedor_id ? `#${e.contrato_fornecedor_id}` : null],
    ],
    itens: itensCompra(e.linhas),
    totais: { subtotal: e.montante_total, imposto: e.total_imposto ?? undefined, total: e.total_com_imposto ?? e.montante_total },
    moeda: 'AOA',
    assinaturas: ['O comprador', 'O fornecedor (aceitação)'],
  };
}

export function dadosFaturaCompra(f: FaturaCompra, itens?: ItemDocumento[]): DadosDocumentoComercial {
  // nas facturas de fornecedor o montante_total já inclui o IVA (bruto); o líquido é a diferença
  const subtotal = f.montante_total && f.total_imposto ? (numero(f.montante_total) - numero(f.total_imposto)).toFixed(2) : undefined;
  return {
    tipo: 'Factura de fornecedor',
    numero: f.numero_fatura,
    estado: f.estado === 'ANULADA' ? `ANULADA${f.motivo_anulacao ? ` — ${f.motivo_anulacao}` : ''}` : null,
    avisos: ['Registo interno do documento do fornecedor'],
    entidade: fornecedor(f.fornecedor_id, f.fornecedor),
    meta: [
      ['Data', dataDoc(f.data)],
      ['Vencimento', dataDoc(f.data_vencimento)],
      ['Encomenda', f.encomenda_compra_id ? numeroOuId(f.numero_encomenda, f.encomenda_compra_id) : 'Factura directa'],
      ['Moeda', moedaInfo(f.codigo_moeda, f.taxa_cambio)],
      ['Contabilização', f.contabilizado ? `Contabilizada${f.numero_lan_contabilizacao ? ` (${f.numero_lan_contabilizacao})` : ''}` : 'Por contabilizar'],
    ],
    itens: itens ?? itensCompra(f.linhas),
    totais: { subtotal, imposto: f.total_imposto ?? undefined, total: f.montante_total },
    moeda: 'AOA',
  };
}

export function dadosRececaoCompra(r: RececaoCompra, extra: { encomenda?: string | null; armazem?: string | null } = {}): DadosDocumentoComercial {
  return {
    tipo: 'Guia de recepção',
    numero: numeroOuId(r.numero_rececao, r.id),
    estado: r.estado === 'ANULADA' || r.estado === 'ANULADO' ? `${r.estado}${r.motivo_anulacao ? ` — ${r.motivo_anulacao}` : ''}` : null,
    meta: [
      ['Data', dataDoc(r.data)],
      ['Encomenda', extra.encomenda ?? `#${r.encomenda_compra_id}`],
      ['Guia do fornecedor', r.numero_entrega],
      ['Armazém', extra.armazem ?? (r.armazem_id ? `#${r.armazem_id}` : null)],
      ['Estado', rotuloEstado(r.estado)],
      ['Validação', r.validado ? `Validada${r.validado_por ? ` por ${r.validado_por}` : ''}${r.validado_em ? ` em ${formatarDataHora(r.validado_em)}` : ''}` : 'Por validar'],
    ],
    colunas: [{ titulo: 'Produto' }, { titulo: 'Quantidade', alinhar: 'direita' }, { titulo: 'Custo unit. (Kz)', alinhar: 'direita' }, { titulo: 'Valor (Kz)', alinhar: 'direita' }],
    linhas: (r.linhas ?? []).map((l) => [
      `${l.produto?.codigo ? `${l.produto.codigo} — ` : ''}${l.produto?.nome ?? `Produto #${l.produto_id}`}`,
      formatarNumero(l.quantidade),
      l.custo_unitario_kz ? formatarKz(l.custo_unitario_kz) : '',
      l.valor_kz ? formatarKz(l.valor_kz) : '',
    ]),
    totais: r.valor_total_kz ? { total: r.valor_total_kz, rotuloTotal: 'Valor total' } : null,
    extenso: false,
    impostos: false,
    assinaturas: ['Entregou (fornecedor)', 'Recebeu (armazém)'],
  };
}

export function dadosContratoCompra(c: ContratoCompra): DadosDocumentoComercial {
  const consumo = c.consumo;
  return {
    tipo: 'Contrato de fornecimento',
    numero: c.referencia,
    estado: c.estado === 'CANCELADO' ? `CANCELADO${c.motivo_cancelamento ? ` — ${c.motivo_cancelamento}` : ''}` : null,
    entidade: fornecedor(c.fornecedor_id, c.fornecedor),
    meta: [
      ['Início', dataDoc(c.data_inicio)],
      ['Fim', dataDoc(c.data_fim)],
      ['Estado', rotuloEstado(c.estado)],
      ['Valor do contrato', `${formatarKz(c.valor_total)} Kz`],
      ['Encomendado', consumo ? `${formatarKz(consumo.encomendado)} Kz (${formatarNumero(consumo.percentagem)} %)` : null],
      ['Facturado / pago', consumo ? `${formatarKz(consumo.faturado)} / ${formatarKz(consumo.pago)} Kz` : null],
    ],
    colunas: [{ titulo: 'Marco / encomenda' }, { titulo: 'Data', alinhar: 'centro' }, { titulo: 'Situação' }, { titulo: 'Montante (Kz)', alinhar: 'direita' }],
    linhas: [
      ...(c.marcos ?? []).map((m) => [`Marco: ${m.titulo}`, m.data_prevista ? formatarData(m.data_prevista) : '', m.fatura_compra_id ? 'Facturado' : 'Por facturar', formatarKz(m.montante)]),
      ...(c.encomendas ?? []).map((e) => [`Encomenda ${numeroOuId(e.numero_encomenda, e.id)}`, e.data ? formatarData(e.data) : '', rotuloEstado(e.estado), formatarKz(e.total_com_imposto ?? e.montante_total)]),
    ],
    totais: { total: c.valor_total, rotuloTotal: 'Valor do contrato' },
    extenso: false,
    impostos: false,
    blocos: [['Objecto', c.descricao]],
    assinaturas: ['Pela empresa', 'Pelo fornecedor'],
  };
}
