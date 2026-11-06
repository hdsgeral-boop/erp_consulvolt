import { htmlTalaoGenerico, type CabecalhoTalao, type PreferenciasImpressao } from '@/modulos/pos/comum/impressao';
import { dataDoc, type DadosDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { GuiaSaida, ListaRecolha } from '../comum/tipos';
import type { ArtigoPos } from './logica';

/**
 * Impressos do POS de armazém (legado printGuiaSaidaPOS e printPOSWhReceipt, js/ui_pos_armazem.js:583-792): a «Guia de
 * Saída» em A4 e o «Talão de Levantamento / Picking» térmico, entregues ao cliente quando a saída se confirma. O sistema
 * novo corrige o legado: os dados da empresa são os reais (identidade da empresa, não telefone/email/morada de exemplo),
 * os textos saem sem acentos corrompidos e o preço mostrado é o de venda (nunca o custo).
 */

/** O que o operador acabou de expedir: guia do balcão (GE POS) ou guia de remessa do picking (GR). */
export interface ConclusaoPOS {
  tipo: 'BALCAO' | 'PICKING';
  numero: string;
  /** AAAA-MM-DD */
  data: string;
  /** HH:mm */
  hora: string | null;
  armazem: string | null;
  destinatario: string;
  nif?: string | null;
  operador?: string | null;
  /** Encomenda de origem (picking). */
  encomenda?: string | null;
  observacoes?: string | null;
  linhas: LinhaConclusao[];
}

export interface LinhaConclusao {
  codigo: string | null;
  descricao: string;
  quantidade: string;
  /** Preço de venda unitário (Kz), se conhecido. */
  preco: string | null;
}

export const CLIENTE_BALCAO = 'Cliente de balcão';

/** Total da linha = quantidade × preço (arredondado ao cêntimo); vazio sem preço. */
export function totalLinha(l: Pick<LinhaConclusao, 'quantidade' | 'preco'>): string | null {
  if (l.preco === null || l.preco === '') return null;
  return deCentimos(Math.round(Number(l.quantidade) * paraCentimos(l.preco)));
}

/** Soma das linhas com preço (Kz, duas casas); null se nenhuma linha tiver preço. */
export function totalConclusao(linhas: LinhaConclusao[]): string | null {
  const totais = linhas.map(totalLinha).filter((t): t is string => t !== null);
  return totais.length ? deCentimos(totais.reduce((s, t) => s + paraCentimos(t), 0)) : null;
}

const horaDe = (iso: string | null | undefined): string | null => {
  const m = iso ? /T(\d{2}:\d{2})/.exec(iso) : null;
  return m ? m[1] : null;
};

/** Conclusão da venda ao balcão a partir da guia devolvida pelo servidor (nomes e códigos vêm do catálogo do ecrã). */
export function conclusaoBalcao(
  g: GuiaSaida,
  artigos: Pick<ArtigoPos, 'produto_id' | 'codigo' | 'nome'>[],
  armazem: string | null,
  operador: string | null,
  cliente?: { nome: string; nif?: string | null } | null,
): ConclusaoPOS {
  const porId = new Map(artigos.map((a) => [a.produto_id, a]));
  return {
    tipo: 'BALCAO',
    numero: g.numero_documento,
    data: g.data,
    hora: horaDe(g.criado_em),
    armazem,
    destinatario: cliente?.nome.trim() || g.area_rececao || CLIENTE_BALCAO,
    nif: cliente?.nif ?? g.terceiro?.nif ?? null,
    operador: operador ?? g.criado_por,
    observacoes: g.observacoes,
    linhas: (g.linhas ?? []).map((l) => ({
      codigo: porId.get(l.produto_id)?.codigo ?? l.produto?.codigo ?? null,
      descricao: porId.get(l.produto_id)?.nome ?? l.produto?.nome ?? `Produto #${l.produto_id}`,
      quantidade: l.quantidade,
      preco: l.preco_unitario_kz ?? null,
    })),
  };
}

/** Guia de remessa devolvida pela expedição do picking (VendaPOSResource). */
export interface GuiaRemessaExpedida {
  numero_documento: string;
  data_emissao?: string | null;
  criado_em?: string | null;
  observacoes?: string | null;
  itens_venda?: { produto_id: number | null; descricao: string | null; quantidade: string; preco_unitario: string | null }[];
}

/** Conclusão do picking: GR emitida, com os códigos e nomes da lista de recolha e o cliente da fila. */
export function conclusaoPicking(
  gr: GuiaRemessaExpedida,
  lista: Pick<ListaRecolha, 'encomenda' | 'linhas'>,
  cliente: string | null,
  armazem: string | null,
  operador: string | null,
): ConclusaoPOS {
  const porProduto = new Map(lista.linhas.map((l) => [l.produto_id, l]));
  return {
    tipo: 'PICKING',
    numero: gr.numero_documento,
    data: (gr.data_emissao ?? gr.criado_em ?? new Date().toISOString()).slice(0, 10),
    hora: horaDe(gr.criado_em),
    armazem,
    destinatario: cliente?.trim() || 'Cliente Geral',
    operador,
    encomenda: lista.encomenda.numero_documento,
    observacoes: gr.observacoes,
    linhas: (gr.itens_venda ?? []).map((i) => {
      const l = i.produto_id ? porProduto.get(i.produto_id) : undefined;
      return { codigo: l?.codigo ?? null, descricao: l?.nome ?? i.descricao ?? 'Artigo', quantidade: i.quantidade, preco: i.preco_unitario };
    }),
  };
}

/** Frase da conclusão (legado: «Atendimento de X concluido com sucesso. Guia N gerada.» / «Picking concluido e expedido para X.»). */
export function fraseConclusao(c: ConclusaoPOS): string {
  return c.tipo === 'BALCAO'
    ? `Atendimento de ${c.destinatario} concluído com sucesso. Guia ${c.numero} gerada.`
    : `Picking concluído e expedido para ${c.destinatario}.`;
}

/** Mensagem de sucesso (legado: «Saída concluída com sucesso! Guia N emitida.» / «Picking e expedição validados com sucesso! Guia N emitida.»). */
export function mensagemConclusao(c: ConclusaoPOS): { titulo: string; texto: string } {
  return c.tipo === 'BALCAO'
    ? { titulo: 'Saída concluída com sucesso!', texto: `Guia ${c.numero} emitida.` }
    : { titulo: 'Picking e expedição validados com sucesso!', texto: `Guia ${c.numero} emitida.` };
}

const AVISO_GUIA = 'Este documento não serve de fatura. Serve exclusivamente como guia de transporte físico e saída física de mercadorias das nossas instalações.';

/**
 * Guia de Saída em A4 (printGuiaSaidaPOS): empresa no cabeçalho do motor comum (nome, NIF, morada, telefone, email reais),
 * destinatário, dados de expedição, tabela # | Código | Descrição do Artigo | Quantidade | Preço Unit. | Total Líquido,
 * «TOTAL GERAL» e assinaturas do operador e do recebedor. Não é documento fiscal: sem QR, ATCUD nem hash.
 */
export function dadosGuiaSaidaPOS(c: ConclusaoPOS): DadosDocumentoComercial {
  const comPreco = c.linhas.some((l) => l.preco !== null);
  const colunas: DadosDocumentoComercial['colunas'] = [
    { titulo: '#', alinhar: 'centro' },
    { titulo: 'Código' },
    { titulo: 'Descrição do Artigo' },
    { titulo: 'Quantidade', alinhar: 'direita' },
    ...(comPreco ? [{ titulo: 'Preço Unit.', alinhar: 'direita' as const }, { titulo: 'Total Líquido', alinhar: 'direita' as const }] : []),
  ];
  const total = totalConclusao(c.linhas);
  return {
    tipo: 'Guia de Saída',
    numero: c.numero,
    avisos: ['Documento interno de movimentação de stock'],
    entidade: { rotulo: 'Destinatário', nome: c.destinatario, nif: c.nif ?? null },
    meta: [
      ['Data de Emissão', dataDoc(c.data)],
      ['Hora', c.hora],
      ['Armazém de Origem', c.armazem],
      ['Encomenda', c.encomenda ?? null],
      ['Transporte', 'Logístico / Balcão'],
      ['Modalidade', 'Levantamento Imediato no POS'],
      ['Estado da Carga', 'Pronta a Despachar'],
      ['Emitida por', c.operador ?? null],
    ],
    colunas,
    linhas: c.linhas.map((l, i) => [
      i + 1,
      l.codigo ?? '',
      l.descricao,
      formatarNumero(l.quantidade),
      ...(comPreco ? [l.preco !== null ? formatarKz(l.preco) : '', formatarKz(totalLinha(l)).replace('—', '')] : []),
    ]),
    totais: total !== null ? { total, rotuloTotal: 'TOTAL GERAL' } : null,
    extenso: false,
    impostos: false,
    blocos: [['Observações', c.observacoes]],
    legal: [AVISO_GUIA],
    assinaturas: ['O Operador do Armazém', 'O Cliente / Recebedor'],
  };
}

/** Talão de Levantamento / Picking (printPOSWhReceipt): documento, data, destinatário, operação, artigos e total. */
export function htmlTalaoLevantamento(c: ConclusaoPOS, cabecalho: CabecalhoTalao, prefs: PreferenciasImpressao): string {
  const total = totalConclusao(c.linhas);
  return htmlTalaoGenerico(
    'Talão de Levantamento / Picking',
    {
      dados: [
        `Documento: ${c.numero}`,
        `Data: ${dataDoc(c.data) ?? ''}${c.hora ? ` ${c.hora}` : ''}`,
        c.encomenda ? `Encomenda: ${c.encomenda}` : null,
        `Destinatário: ${c.destinatario}`,
        c.armazem ? `Armazém: ${c.armazem}` : null,
        'Operação: Logística / Balcão',
      ],
      linhas: c.linhas.map((l) => {
        const total = totalLinha(l);
        return {
          descricao: `${l.codigo ? `${l.codigo} · ` : ''}${l.descricao}`,
          quantidade: l.quantidade,
          preco: l.preco,
          ...(total !== null ? { total } : { detalhe: `Quantidade: ${formatarNumero(l.quantidade)}` }),
        };
      }),
      totais: total !== null ? [['TOTAL GERAL', total, true]] : [],
      aviso: 'Documento de levantamento — não serve de fatura',
    },
    { ...cabecalho, terminal: null, operador: c.operador ?? cabecalho.operador },
    { ...prefs, rodape: 'Obrigado pelo seu atendimento no nosso Balcão de Armazém!' },
  );
}
