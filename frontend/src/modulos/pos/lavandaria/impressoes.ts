/**
 * M-16 — impressões operacionais da lavandaria (legado: lavImprimirOS, lavImprimirEtiquetas, lavImprimirRecibo,
 * js/lavandaria.js:325-395): talão da ordem de serviço em duas vias (cliente e loja) com as condições, etiquetas das
 * peças e recibo RC-LAV. As facturas imprimem-se com o talão de venda do POS (htmlTalaoVenda).
 */
import { formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { dataDoc, type DadosDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { htmlEtiquetas, htmlTalaoGenerico, type CabecalhoTalao, type PreferenciasImpressao } from '../comum/impressao';
import { UNIDADES_LAV } from './dados';
import type { DefinicoesLav, DetalheOrdem, PagamentoLav } from './tipos';

/** Condições do talão (condicoesTexto, lavandaria.js:325-334). */
export function condicoesLavandaria(def: DefinicoesLav | undefined): string[] {
  if (!def) return [];
  return [
    `Prazo de levantamento: ${def.dias_armazenagem_gratis} dias após a data em que as peças ficam prontas.`,
    def.taxa_armazenagem_ativa ? `Findo o prazo, é cobrada uma taxa de armazenagem de ${Number(def.percentagem_armazenagem_dia)}% do valor do serviço por cada dia.` : '',
    'O estado das peças à entrada foi verificado e registado nesta ordem na presença do cliente.',
    'Em caso de dano ou extravio, a indemnização corresponde ao valor da peça, mediante apresentação do comprovativo de custo.',
    `Reclamações até ${def.dias_reclamacao} dias após a entrega. O levantamento exige a apresentação deste talão ou a identificação do cliente.`,
    'Os orçamentos de alfaiataria só são executados depois de aprovados pelo cliente.',
  ].filter(Boolean);
}

const activos = (d: DetalheOrdem) => d.pedido.itens.filter((i) => i.estado !== 'ANULADA');

/** Talão da ordem de serviço (duas vias). */
export function htmlTalaoOS(d: DetalheOrdem, def: DefinicoesLav | undefined, c: CabecalhoTalao, p: PreferenciasImpressao): string {
  const o = d.pedido;
  return htmlTalaoGenerico(
    `Ordem de serviço ${o.numero_encomenda}`,
    {
      vias: ['Via do cliente', 'Via da loja'],
      dados: [
        d.cliente ? `Cliente: ${d.cliente.nome}` : null,
        d.cliente && (d.cliente.nif || d.cliente.telefone) ? `NIF / Tel.: ${[d.cliente.nif, d.cliente.telefone].filter(Boolean).join(' · ')}` : null,
        `Recebida: ${formatarDataHora(o.recebido_em)}`,
        o.data_prometida ? `Pronta a partir de: ${formatarData(o.data_prometida)}` : null,
        `Facturação: ${o.modo_faturacao === 'RECEPCAO' ? 'na recepção' : 'na entrega'}`,
        o.urgente ? 'URGENTE' : null,
        o.recolha?.ativa ? `Recolha: ${o.recolha.morada ?? ''} · ${formatarData(o.recolha.data ?? null)}` : null,
        o.entrega?.ativa ? `Entrega: ${o.entrega.morada ?? ''} · ${formatarData(o.entrega.data ?? null)}` : null,
      ],
      linhas: [
        ...activos(d).map((i) => ({
          descricao: `${i.nome_peca ? `${i.nome_peca} · ` : ''}${i.nome} · ${formatarNumero(i.quantidade)} ${UNIDADES_LAV[i.unidade] ?? ''}`,
          detalhe: [
            [i.cor, i.tecido].filter(Boolean).join(', '),
            `Estado à entrada: ${i.estado_entrada || '—'}${i.notas_entrada ? ` (${i.notas_entrada})` : ''}`,
            i.etiquetas?.length ? `Etiquetas: ${i.etiquetas.join(', ')}` : '',
            i.requer_orcamento && i.estado_orcamento !== 'APROVADO' ? 'Orçamento por aprovar' : '',
          ]
            .filter(Boolean)
            .join(' · '),
          total: i.valor,
        })),
        ...(o.extras ?? []).map((e) => ({ descricao: e.nome, total: String(e.valor) })),
      ],
      totais: [
        ['TOTAL', d.totais.total, true],
        ['Pago', d.totais.pago],
        ['Saldo', d.totais.saldo, true],
      ],
      notas: [o.observacoes ? `Obs.: ${o.observacoes}` : '', ...condicoesLavandaria(def)].filter(Boolean).join('\n'),
      assinatura: 'O cliente (confirmo o estado das peças e aceito as condições)',
    },
    c,
    p,
  );
}

/** Etiquetas das peças: uma por etiqueta (n.º da etiqueta, OS, cliente, peça, serviço, estado e data prometida). */
export function htmlEtiquetasOS(d: DetalheOrdem, c: CabecalhoTalao, p: PreferenciasImpressao): string {
  const o = d.pedido;
  const etiquetas = activos(d).flatMap((i) =>
    (i.etiquetas?.length ? i.etiquetas : [`${o.numero_encomenda}/${i.linha_id}`]).map((tag) => ({
      titulo: tag,
      linhas: [
        `${o.numero_encomenda} · ${d.cliente?.nome ?? ''}`,
        [i.nome_peca, i.cor, i.tecido].filter(Boolean).join(' · ') || null,
        i.nome,
        i.estado_entrada ? `Entrada: ${i.estado_entrada}` : null,
        `Pronta: ${formatarData(o.data_prometida)}${o.urgente ? ' · URGENTE' : ''}`,
      ],
    })),
  );
  return htmlEtiquetas(`Etiquetas ${o.numero_encomenda}`, etiquetas, c, p);
}

/** Recibo RC-LAV (pagamento ou adiantamento). */
export function htmlReciboLav(r: PagamentoLav, d: DetalheOrdem, c: CabecalhoTalao, p: PreferenciasImpressao): string {
  return htmlTalaoGenerico(
    `Recibo ${r.numero_recibo ?? ''}`,
    {
      dados: [
        `${r.natureza_registo === 'ADIANTAMENTO' ? 'Adiantamento' : 'Pagamento'} · ${d.pedido.numero_encomenda}`,
        d.cliente ? `Cliente: ${d.cliente.nome}` : null,
        `Data: ${formatarData(r.data)}`,
        r.estado === 'ANULADO' ? 'ANULADO' : null,
      ],
      linhas: (r.pos_pagamentos ?? []).map((m) => ({ descricao: `${m.nome ?? m.tipo}${m.referencia ? ` (${m.referencia})` : ''}`, total: String(m.valor) })),
      totais: [
        ['TOTAL RECEBIDO', r.montante, true],
        ...(Number(r.troco ?? 0) > 0 ? ([['Troco', r.troco]] as [string, string | null][]) : []),
      ],
      notas: 'Processado por computador',
    },
    c,
    p,
  );
}

/**
 * Recibo RC-LAV em A4, com original e duplicado na mesma folha (documento comercial em duas vias): cliente, ordem de
 * serviço, meios de pagamento, total recebido (por extenso) e troco. Complementa o talão térmico do posto.
 */
export function dadosReciboLavA4(r: PagamentoLav, d: DetalheOrdem): DadosDocumentoComercial {
  const adiantamento = r.natureza_registo === 'ADIANTAMENTO';
  const meios = r.pos_pagamentos ?? [];
  return {
    tipo: adiantamento ? 'Recibo de adiantamento' : 'Recibo',
    numero: r.numero_recibo ?? `#${r.id}`,
    estado: r.estado === 'ANULADO' ? 'ANULADO' : null,
    entidade: d.cliente ? { rotulo: 'Cliente', nome: d.cliente.nome, nif: d.cliente.nif, contactos: [d.cliente.telefone] } : { rotulo: 'Cliente', nome: 'Consumidor final' },
    meta: [
      ['Data', dataDoc(r.data)],
      ['Ordem de serviço', d.pedido.numero_encomenda],
      ['Natureza', adiantamento ? 'Adiantamento' : 'Pagamento'],
      ['Emitido por', r.criado_por],
      ['Saldo da ordem', `${formatarKz(d.totais.saldo)} Kz`],
    ],
    colunas: [{ titulo: 'Meio de pagamento' }, { titulo: 'Referência' }, { titulo: 'Valor (Kz)', alinhar: 'direita' }],
    linhas: meios.length ? meios.map((m) => [m.nome ?? m.tipo, m.referencia ?? '', formatarKz(m.valor)]) : [['—', '', formatarKz(r.montante)]],
    totais: { total: r.montante, rotuloTotal: 'Total recebido', extra: Number(r.troco ?? 0) > 0 ? [['Troco', r.troco ?? '0']] : [] },
    impostos: false,
    legal: ['Documento processado por computador.', adiantamento ? 'Adiantamento por conta da ordem de serviço.' : null],
    assinaturas: ['O Cliente', 'A Lavandaria'],
  };
}
