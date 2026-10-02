import { esc, type PedidoImpressao } from '@/componentes/impressao';
import { deCentimos, paraCentimos, type Valor } from '@/utilitarios/decimal';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';

/**
 * Documento comercial impresso (facturas, notas de crédito, guias, encomendas, propostas, recibos, documentos de
 * tesouraria), no formato do legado (js/shared/documento_comercial.js): entidade e dados do documento lado a lado,
 * tabela de linhas, resumo de impostos + totais, valor por extenso, observações, elementos fiscais (QR/hash AGT) e
 * assinaturas. O logótipo e o nome da empresa são postos pelo motor comum (`@/componentes/impressao`), nunca aqui.
 * Sai sempre em A4 retrato.
 */

export interface EntidadeDocumento {
  /** «Cliente», «Fornecedor», «Beneficiário»… */
  rotulo: string;
  nome: string;
  nif?: string | null;
  morada?: string | null;
  contactos?: (string | null | undefined)[];
}

export interface ItemDocumento {
  codigo?: string | null;
  descricao: string;
  notas?: string | null;
  unidade?: string | null;
  quantidade?: Valor;
  preco?: Valor;
  /** Desconto em % */
  desconto?: Valor;
  /** Taxa de IVA em % */
  taxa?: Valor;
  /** Base tributável da linha (sem IVA); por omissão `total`. */
  base?: Valor;
  /** IVA da linha; por omissão base × taxa. */
  iva?: Valor;
  /** Total da linha (como aparece na coluna «Total»). */
  total?: Valor;
}

/** Coluna própria (quando o documento não é uma lista de produtos: recibos, documentos de tesouraria…). */
export interface ColunaDocumento {
  titulo: string;
  alinhar?: 'esquerda' | 'direita' | 'centro';
}

export interface TotaisDocumento {
  subtotal?: Valor;
  desconto?: Valor;
  imposto?: Valor;
  retencao?: Valor;
  total?: Valor;
  rotuloTotal?: string;
  /** Linhas adicionais depois do total (ex.: Pago, Pendente). */
  extra?: [string, Valor][];
}

export interface DadosDocumentoComercial {
  /** Ex.: «Factura» */
  tipo: string;
  /** Ex.: «FT A/2026/12» */
  numero: string;
  /** «Original», «Duplicado»… */
  via?: string | null;
  /** Estado de destaque (ex.: «ANULADO»). */
  estado?: string | null;
  /** Avisos curtos por baixo do título (ex.: «Este documento não serve de factura»). */
  avisos?: (string | null | undefined | false)[];
  entidade?: EntidadeDocumento | null;
  /** Pares «rótulo: valor» do documento (data, vencimento, moeda, referências…). */
  meta?: [string, string | null | undefined][];
  itens?: ItemDocumento[];
  /** Esconde preços/taxas (guias de transporte, pedidos internos). */
  semPrecos?: boolean;
  /** Tabela livre (sobrepõe `itens`). */
  colunas?: ColunaDocumento[];
  linhas?: (string | number | null | undefined)[][];
  totais?: TotaisDocumento | null;
  /** Resumo de impostos (por omissão calculado a partir dos itens, se tiverem taxa). */
  impostos?: { imposto: string; base: Valor; valor: Valor }[] | false;
  /** Meios de pagamento usados (recibos, FR). */
  pagamentos?: { descricao: string; valor?: Valor }[];
  moeda?: string | null;
  /** Valor por extenso do total (por omissão: sim, quando há total). */
  extenso?: boolean;
  /** Blocos de texto (Condições de pagamento, Observações, Motivo…). */
  blocos?: [string, string | null | undefined][];
  /** Elementos fiscais (QR code de consulta e texto). */
  fiscal?: { qr?: string | null; titulo?: string | null; linhas?: (string | null | undefined)[]; url?: string | null } | null;
  /** Hash (excerto) e mensagens legais. */
  hash?: string | null;
  legal?: (string | null | undefined)[];
  assinaturas?: string[];
}

const SEM_DECIMAIS = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 4 });

function moeda(v: Valor, m?: string | null): string {
  const t = formatarKz(v);
  if (t === '—') return '';
  return `${t} ${m && m !== 'AOA' ? m : 'Kz'}`;
}

function qtd(v: Valor): string {
  if (v === null || v === undefined || v === '') return '';
  const n = Number(v);
  return Number.isNaN(n) ? String(v) : SEM_DECIMAIS.format(n);
}

function temValor(v: Valor): boolean {
  return v !== null && v !== undefined && v !== '' && paraCentimos(v) !== 0;
}

/** Resumo de IVA por taxa (base e imposto), a partir das linhas, com aritmética em cêntimos. */
export function resumoImpostos(itens: ItemDocumento[]): { imposto: string; base: string; valor: string }[] {
  const grupos = new Map<string, { taxa: number; base: number; valor: number }>();
  for (const it of itens) {
    if (it.taxa === null || it.taxa === undefined || it.taxa === '') continue;
    const taxa = Number(it.taxa) || 0;
    const base = paraCentimos(it.base ?? it.total);
    const valor = it.iva !== null && it.iva !== undefined && it.iva !== '' ? paraCentimos(it.iva) : Math.round((base * taxa) / 100);
    const k = taxa.toFixed(2);
    const g = grupos.get(k) ?? { taxa, base: 0, valor: 0 };
    g.base += base;
    g.valor += valor;
    grupos.set(k, g);
  }
  return [...grupos.values()]
    .sort((a, b) => b.taxa - a.taxa)
    .map((g) => ({ imposto: g.taxa > 0 ? `IVA ${qtd(g.taxa)} %` : 'IVA 0 % (isento)', base: deCentimos(g.base), valor: deCentimos(g.valor) }));
}

const MOEDAS: Record<string, [string, string, string, string]> = {
  AOA: ['kwanza', 'kwanzas', 'cêntimo', 'cêntimos'],
  USD: ['dólar', 'dólares', 'cêntimo', 'cêntimos'],
  EUR: ['euro', 'euros', 'cêntimo', 'cêntimos'],
  ZAR: ['rand', 'rands', 'cêntimo', 'cêntimos'],
  GBP: ['libra', 'libras', 'pêni', 'pence'],
  BRL: ['real', 'reais', 'centavo', 'centavos'],
  CNY: ['yuan', 'yuans', 'fen', 'fen'],
};
const U = ['zero', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'catorze', 'quinze', 'dezasseis', 'dezassete', 'dezoito', 'dezanove'];
const D = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
const C = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

function ate999(n: number): string {
  if (n === 100) return 'cem';
  const p: string[] = [];
  const ce = Math.floor(n / 100);
  const r = n % 100;
  if (ce) p.push(C[ce]);
  if (r) p.push(r < 20 ? U[r] : r % 10 ? `${D[Math.floor(r / 10)]} e ${U[r % 10]}` : D[r / 10]);
  return p.join(' e ');
}

/** Valor por extenso (português) na moeda indicada — mesma regra do legado. Ex.: 1250,50 → «Mil duzentos e cinquenta kwanzas e cinquenta cêntimos». */
export function valorPorExtenso(valor: Valor, codigoMoeda = 'AOA'): string {
  const [s1, sN, c1, cN] = MOEDAS[codigoMoeda] ?? [codigoMoeda, codigoMoeda, 'cêntimo', 'cêntimos'];
  const totalCent = Math.abs(paraCentimos(valor));
  const inteiro = Math.floor(totalCent / 100);
  const cent = totalCent % 100;
  const mm = Math.floor(inteiro / 1e9);
  const mi = Math.floor((inteiro % 1e9) / 1e6);
  const mil = Math.floor((inteiro % 1e6) / 1e3);
  const un = inteiro % 1e3;
  const g: { n: number; t: string }[] = [];
  if (mm) g.push({ n: mm, t: `${mm === 1 ? 'mil' : `${ate999(mm)} mil`} milhões` });
  if (mi) g.push({ n: mi, t: mi === 1 ? 'um milhão' : `${ate999(mi)} milhões` });
  if (mil) g.push({ n: mil, t: mil === 1 ? 'mil' : `${ate999(mil)} mil` });
  if (un) g.push({ n: un, t: ate999(un) });
  let txt = g.length ? g[0].t : 'zero';
  for (let i = 1; i < g.length; i++) txt += i === g.length - 1 && (g[i].n < 100 || g[i].n % 100 === 0) ? ` e ${g[i].t}` : ` ${g[i].t}`;
  txt += (mm || mi) && !mil && !un ? ` de ${sN}` : inteiro === 1 ? ` ${s1}` : ` ${sN}`;
  if (cent) txt += ` e ${ate999(cent)} ${cent === 1 ? c1 : cN}`;
  return txt.charAt(0).toUpperCase() + txt.slice(1);
}

/** CSS do documento comercial (acrescentado ao CSS base do motor). */
export const CSS_DOCUMENTO_COMERCIAL = `
.dc-topo { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; align-items: start; margin: 1mm 0 4mm; break-inside: avoid; }
.dc-ent { font-size: 8.4pt; line-height: 1.55; border-left: 0.6mm solid #1f1f1f; padding-left: 3mm; }
.dc-ent-rot { font-size: 7pt; font-weight: 700; letter-spacing: .06em; color: #555; text-transform: uppercase; }
.dc-ent-nome { font-weight: 700; font-size: 10pt; }
.dc-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5mm 5mm; margin: 0; font-size: 8.4pt; }
.dc-meta dt { font-size: 7pt; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: .04em; margin: 0; }
.dc-meta dd { margin: 0; overflow-wrap: anywhere; }
.dc-avisos { font-size: 7.5pt; color: #555; text-transform: uppercase; letter-spacing: .03em; margin: -2mm 0 3mm; }
.dc-estado { display: inline-block; padding: 0.3mm 2mm; border: 0.4mm solid #b42318; color: #b42318; font-weight: 700; font-size: 8pt; margin-bottom: 2mm; }
table.dc-itens { width: 100%; border-collapse: collapse; font-size: 8.4pt; font-variant-numeric: tabular-nums; }
table.dc-itens th { text-align: left; font-weight: 700; font-size: 7.8pt; text-transform: uppercase; padding: 1.4mm 1.6mm; border-top: 0.3mm solid #1f1f1f; border-bottom: 0.3mm solid #1f1f1f; white-space: nowrap; }
table.dc-itens td { padding: 1.2mm 1.6mm; border-bottom: 0.2mm solid #d9d9d9; vertical-align: top; }
table.dc-itens .r { text-align: right; white-space: nowrap; }
table.dc-itens .c { text-align: center; white-space: nowrap; }
table.dc-itens .desc { width: 100%; white-space: normal; overflow-wrap: anywhere; }
table.dc-itens .cod { display: block; font-size: 7pt; color: #666; }
table.dc-itens .nota { display: block; font-size: 7.2pt; color: #555; font-style: italic; }
.dc-meio { display: grid; grid-template-columns: minmax(0, 1fr) 72mm; gap: 6mm; margin-top: 4mm; align-items: start; break-inside: avoid; }
table.dc-mini { border-collapse: collapse; font-size: 8pt; font-variant-numeric: tabular-nums; width: 100%; }
table.dc-mini th { text-align: left; font-size: 7.4pt; text-transform: uppercase; border-bottom: 0.3mm solid #8c8c8c; padding: 0.8mm 2mm 0.8mm 0; }
table.dc-mini td { padding: 0.8mm 2mm 0.8mm 0; white-space: nowrap; }
table.dc-mini .r { text-align: right; }
table.dc-tot { width: 100%; border-collapse: collapse; font-size: 9pt; font-variant-numeric: tabular-nums; }
table.dc-tot td { padding: 0.9mm 1mm; border-top: 0.2mm solid #d9d9d9; }
table.dc-tot tr:first-child td { border-top: 0; }
table.dc-tot td:first-child { font-weight: 700; text-transform: uppercase; font-size: 8pt; }
table.dc-tot td:last-child { text-align: right; white-space: nowrap; }
table.dc-tot tr.geral td { border-top: 0.5mm solid #1f1f1f; font-size: 10.5pt; font-weight: 700; }
table.dc-tot tr.neg td:last-child { color: #b42318; }
.dc-extenso { margin-top: 1.5mm; font-size: 7.8pt; color: #333; }
.dc-bloco { margin-top: 4mm; break-inside: avoid; }
.dc-sub { font-weight: 700; font-size: 7.8pt; text-transform: uppercase; letter-spacing: .03em; }
.dc-caixa { border-left: 0.5mm solid #bfbfbf; padding: 0.8mm 0 0.8mm 2.5mm; font-size: 8.2pt; white-space: pre-wrap; margin-top: 1mm; }
.dc-fiscal { display: flex; gap: 4mm; align-items: center; margin-top: 5mm; break-inside: avoid; }
.dc-fiscal img { width: 28mm; height: 28mm; flex-shrink: 0; }
.dc-fiscal .txt { font-size: 7.4pt; line-height: 1.5; }
.dc-fiscal .url { font-size: 6.6pt; color: #555; overflow-wrap: anywhere; }
.dc-legal { margin-top: 4mm; font-size: 7.2pt; color: #333; line-height: 1.6; break-inside: avoid; }
.dc-assin { display: flex; justify-content: space-around; gap: 10mm; margin-top: 14mm; break-inside: avoid; }
.dc-assin div { flex: 0 1 38%; text-align: center; font-size: 8pt; border-top: 0.3mm solid #1f1f1f; padding-top: 1mm; }
`;

function htmlEntidade(e: EntidadeDocumento): string {
  const contactos = (e.contactos ?? []).filter(Boolean);
  return `<div class="dc-ent"><div class="dc-ent-rot">${esc(e.rotulo)}</div><div class="dc-ent-nome">${esc(e.nome || '—')}</div>${
    e.nif ? `<div>NIF: ${esc(e.nif)}</div>` : ''
  }${e.morada ? `<div>${esc(e.morada)}</div>` : ''}${contactos.length ? `<div>${contactos.map(esc).join(' · ')}</div>` : ''}</div>`;
}

function htmlItens(d: DadosDocumentoComercial): string {
  if (d.colunas && d.linhas) {
    const cls = (c: ColunaDocumento, i: number) => (c.alinhar === 'direita' ? 'r' : c.alinhar === 'centro' ? 'c' : i === 0 ? 'desc' : '');
    return `<table class="dc-itens"><thead><tr>${d.colunas.map((c, i) => `<th class="${cls(c, i) === 'desc' ? '' : cls(c, i)}">${esc(c.titulo)}</th>`).join('')}</tr></thead><tbody>${
      d.linhas.length
        ? d.linhas.map((l) => `<tr>${l.map((v, i) => `<td class="${cls(d.colunas![i] ?? { titulo: '' }, i)}">${esc(v ?? '')}</td>`).join('')}</tr>`).join('')
        : `<tr><td colspan="${d.colunas.length}" class="c">Sem linhas.</td></tr>`
    }</tbody></table>`;
  }
  const itens = d.itens ?? [];
  const precos = !d.semPrecos;
  const temUn = itens.some((i) => i.unidade);
  const temDesc = precos && itens.some((i) => temValor(i.desconto));
  const temTaxa = precos && itens.some((i) => i.taxa !== null && i.taxa !== undefined && i.taxa !== '');
  const cab = `<th>Descrição</th>${temUn ? '<th class="c">Un.</th>' : ''}<th class="r">Qtd.</th>${
    precos ? `<th class="r">Pr. unit.</th>${temDesc ? '<th class="r">Desc. %</th>' : ''}${temTaxa ? '<th class="r">IVA %</th>' : ''}<th class="r">Total</th>` : ''
  }`;
  const n = 2 + (temUn ? 1 : 0) + (precos ? 2 + (temDesc ? 1 : 0) + (temTaxa ? 1 : 0) : 0) - 1;
  const corpo = itens.length
    ? itens
        .map(
          (it) =>
            `<tr><td class="desc">${it.codigo ? `<span class="cod">${esc(it.codigo)}</span>` : ''}${esc(it.descricao)}${it.notas ? `<span class="nota">${esc(it.notas)}</span>` : ''}</td>${
              temUn ? `<td class="c">${esc(it.unidade ?? '')}</td>` : ''
            }<td class="r">${esc(qtd(it.quantidade))}</td>${
              precos
                ? `<td class="r">${esc(formatarKz(it.preco))}</td>${temDesc ? `<td class="r">${temValor(it.desconto) ? esc(qtd(it.desconto)) : ''}</td>` : ''}${
                    temTaxa ? `<td class="r">${esc(qtd(it.taxa))}</td>` : ''
                  }<td class="r">${esc(formatarKz(it.total))}</td>`
                : ''
            }</tr>`,
        )
        .join('')
    : `<tr><td colspan="${n}" class="c">Sem linhas.</td></tr>`;
  return `<table class="dc-itens"><thead><tr>${cab}</tr></thead><tbody>${corpo}</tbody></table>`;
}

/** HTML do corpo do documento comercial (sem cabeçalho da empresa: o motor põe-no). */
export function htmlDocumentoComercial(d: DadosDocumentoComercial): string {
  const m = d.moeda ?? 'AOA';
  const avisos = (d.avisos ?? []).filter(Boolean) as string[];
  const meta = (d.meta ?? []).filter(([, v]) => v !== null && v !== undefined && v !== '');
  const topo = `${d.estado ? `<div class="dc-estado">${esc(d.estado)}</div>` : ''}${avisos.length ? `<div class="dc-avisos">${avisos.map(esc).join(' | ')}</div>` : ''}
<section class="dc-topo">${d.entidade ? htmlEntidade(d.entidade) : '<div></div>'}<dl class="dc-meta">${meta
    .map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`)
    .join('')}</dl></section>`;

  const t = d.totais;
  const linhasTot: string[] = [];
  if (t) {
    if (t.subtotal !== undefined) linhasTot.push(`<tr><td>Subtotal</td><td>${esc(moeda(t.subtotal, m))}</td></tr>`);
    if (temValor(t.desconto)) linhasTot.push(`<tr class="neg"><td>Desconto</td><td>− ${esc(moeda(t.desconto, m))}</td></tr>`);
    if (t.imposto !== undefined) linhasTot.push(`<tr><td>IVA</td><td>${esc(moeda(t.imposto, m))}</td></tr>`);
    if (temValor(t.retencao)) linhasTot.push(`<tr class="neg"><td>Retenção</td><td>− ${esc(moeda(t.retencao, m))}</td></tr>`);
    if (t.total !== undefined) linhasTot.push(`<tr class="geral"><td>${esc(t.rotuloTotal ?? 'Total')}</td><td>${esc(moeda(t.total, m))}</td></tr>`);
    (t.extra ?? []).forEach(([k, v]) => linhasTot.push(`<tr><td>${esc(k)}</td><td>${esc(moeda(v, m))}</td></tr>`));
  }
  const extenso = d.extenso !== false && t?.total !== undefined && t.total !== null && t.total !== '' ? `<div class="dc-extenso">${esc(valorPorExtenso(t.total, m))}</div>` : '';
  const totHtml = linhasTot.length ? `<div><table class="dc-tot"><tbody>${linhasTot.join('')}</tbody></table>${extenso}</div>` : '';
  const impostos = d.impostos === false || d.semPrecos ? [] : d.impostos ?? resumoImpostos(d.itens ?? []);
  const impHtml = impostos.length
    ? `<table class="dc-mini"><thead><tr><th>Imposto</th><th class="r">Base</th><th class="r">Valor</th></tr></thead><tbody>${impostos
        .map((i) => `<tr><td>${esc(i.imposto)}</td><td class="r">${esc(moeda(i.base, m))}</td><td class="r">${esc(moeda(i.valor, m))}</td></tr>`)
        .join('')}</tbody></table>`
    : '';
  const pags = d.pagamentos ?? [];
  const pagHtml = pags.length
    ? `<table class="dc-mini" style="margin-top:3mm"><thead><tr><th>Meio de pagamento</th><th class="r">Valor</th></tr></thead><tbody>${pags
        .map((p) => `<tr><td>${esc(p.descricao)}</td><td class="r">${esc(p.valor !== undefined ? moeda(p.valor, m) : '')}</td></tr>`)
        .join('')}</tbody></table>`
    : '';
  const meio = totHtml || impHtml || pagHtml ? `<section class="dc-meio"><div>${impHtml}${pagHtml}</div>${totHtml || '<div></div>'}</section>` : '';
  const blocos = (d.blocos ?? [])
    .filter(([, v]) => v)
    .map(([k, v]) => `<div class="dc-bloco"><div class="dc-sub">${esc(k)}</div><div class="dc-caixa">${esc(v)}</div></div>`)
    .join('');
  const f = d.fiscal;
  const fiscal =
    f && (f.qr || f.titulo || f.url)
      ? `<div class="dc-fiscal">${f.qr ? `<img src="${esc(f.qr)}" alt="QR code de consulta do documento na AGT">` : ''}<div class="txt">${f.titulo ? `<div><b>${esc(f.titulo)}</b></div>` : ''}${(f.linhas ?? [])
          .filter(Boolean)
          .map((l) => `<div>${esc(l)}</div>`)
          .join('')}${f.url ? `<div class="url">${esc(f.url)}</div>` : ''}</div></div>`
      : '';
  const legal = (d.legal ?? []).filter(Boolean) as string[];
  const legalHtml = legal.length || d.hash ? `<div class="dc-legal">${d.hash ? `<div><b>${esc(d.hash)}</b></div>` : ''}${legal.map((l) => `<div>${esc(l)}</div>`).join('')}</div>` : '';
  const assin = d.assinaturas?.length ? `<div class="dc-assin">${d.assinaturas.map((a) => `<div>${esc(a)}</div>`).join('')}</div>` : '';
  return `<div class="dc-documento">${topo}${htmlItens(d)}${meio}${blocos}${fiscal}${legalHtml}${assin}</div>`;
}

/** Pedido de impressão (motor comum) de um documento comercial: A4 retrato, título «Tipo n.º». */
export function pedidoDocumentoComercial(d: DadosDocumentoComercial): PedidoImpressao {
  return {
    titulo: `${d.tipo} ${d.numero}`.trim(),
    subtitulo: d.via ?? null,
    conteudo: htmlDocumentoComercial(d),
    cssExtra: CSS_DOCUMENTO_COMERCIAL,
    orientacao: 'retrato',
    papel: 'A4',
  };
}

/** Atalho para datas no meta (DD/MM/AAAA ou nada). */
export const dataDoc = (v: string | null | undefined): string | null => (v ? formatarData(v) : null);
/** Quantidades/percentagens legíveis. */
export const numeroDoc = (v: Valor): string => (v === null || v === undefined || v === '' ? '' : formatarNumero(v));
