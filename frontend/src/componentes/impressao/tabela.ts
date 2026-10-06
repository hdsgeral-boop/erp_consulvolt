import { somar } from '@/utilitarios/decimal';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { esc } from './documento';

/**
 * Dados tabulares → HTML de impressão limpo (tabela simples, cabeçalho repetido em cada página, números à direita,
 * subtotais por grupo e total geral). A maioria dos mapas deve usar isto em vez de clonar o DOM do Ant Design.
 */

export type FormatoColuna = 'texto' | 'moeda' | 'numero' | 'inteiro' | 'data' | 'percentagem';
export type ValorCelula = string | number | boolean | null | undefined;

export interface ColunaImpressao<T> {
  titulo: string;
  /** Valor bruto da célula (ex.: «1234.50» para moeda). */
  valor: (linha: T, indice: number) => ValorCelula;
  formato?: FormatoColuna;
  /** Formatação própria (sobrepõe `formato`). Também é usada nos totais. */
  formatar?: (valor: ValorCelula, linha: T | null) => string;
  alinhamento?: 'esquerda' | 'centro' | 'direita';
  /** Soma a coluna nos subtotais/total (valores numéricos, aritmética em cêntimos). */
  somar?: boolean;
  /** Texto fixo na linha de total (sobrepõe a soma). */
  total?: string;
  /** Largura CSS (ex.: '28mm'). */
  largura?: string;
  /** Permite quebra de linha (textos longos: descrições, observações). */
  quebrar?: boolean;
  /**
   * Valor bruto para a exportação Excel (número ou data ISO), quando o texto impresso não o deixa reconstituir
   * (ex.: taxas com mais casas do que as mostradas). Por omissão: `valor` nas colunas numéricas e de data.
   */
  bruto?: (linha: T, indice: number) => ValorCelula;
}

export interface OpcoesTabela<T> {
  colunas: ColunaImpressao<T>[];
  linhas: T[];
  /** Legenda por cima da tabela. */
  legenda?: string;
  /** Linha de total geral: `true` = «Total», ou o texto do rótulo. */
  totais?: boolean | string;
  agrupar?: {
    chave: (linha: T) => string;
    /** Título da linha de grupo (por omissão a própria chave). */
    titulo?: (chave: string, linhas: T[]) => string;
    /** Subtotal no fim de cada grupo (colunas com `somar`). */
    subtotais?: boolean;
  };
  vazio?: string;
}

const NUMERICOS: FormatoColuna[] = ['moeda', 'numero', 'inteiro', 'percentagem'];

function formatarValor<T>(c: ColunaImpressao<T>, v: ValorCelula, linha: T | null): string {
  if (c.formatar) return c.formatar(v, linha);
  if (v === null || v === undefined || v === '') return '';
  if (typeof v === 'boolean') return v ? 'Sim' : 'Não';
  switch (c.formato) {
    case 'moeda':
      return formatarKz(v);
    case 'numero':
      return formatarNumero(v);
    case 'inteiro':
      return formatarNumero(Math.round(Number(v)));
    case 'percentagem':
      return `${formatarNumero(v)} %`;
    case 'data':
      return formatarData(String(v));
    default:
      return String(v);
  }
}

function classe<T>(c: ColunaImpressao<T>): string {
  const a = c.alinhamento ?? (c.formato && NUMERICOS.includes(c.formato) ? 'direita' : 'esquerda');
  return [a === 'direita' ? 'imp-num' : a === 'centro' ? 'imp-centro' : '', c.quebrar ? 'imp-quebra' : ''].filter(Boolean).join(' ');
}

function attrClasse(c: string): string {
  return c ? ` class="${c}"` : '';
}

/**
 * Atributos `data-xv` (valor bruto) e `data-xt` (n = número, d = data) lidos pela exportação Excel (excel.ts) para
 * gravar números como números e datas como datas. Não afectam a impressão.
 */
export function attrBruto(v: ValorCelula): string {
  if (typeof v === 'number' && Number.isFinite(v)) return ` data-xv="${v}" data-xt="n"`;
  if (typeof v !== 'string') return '';
  const t = v.trim();
  if (/^-?\d+(\.\d+)?$/.test(t)) return ` data-xv="${t}" data-xt="n"`;
  if (/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?/.test(t)) return ` data-xv="${esc(t.slice(0, 16))}" data-xt="d"`;
  return '';
}

function brutoDe<T>(c: ColunaImpressao<T>, l: T, n: number): ValorCelula {
  if (c.bruto) return c.bruto(l, n);
  return c.formato && c.formato !== 'texto' && !c.formatar ? c.valor(l, n) : undefined;
}

function linhaTotais<T>(colunas: ColunaImpressao<T>[], linhas: T[], rotulo: string, cls: string): string {
  const celulas = colunas.map((c, i) => {
    let texto = '';
    if (c.total !== undefined) texto = c.total;
    else if (c.somar) {
      const soma = somar(linhas.map((l, n) => c.valor(l, n) as string | number | null | undefined));
      texto = c.formatar ? c.formatar(soma, null) : c.formato === 'inteiro' ? formatarNumero(Math.round(Number(soma))) : formatarKz(soma);
      return `<td${attrClasse(classe(c))}${c.formato !== 'percentagem' && !c.formatar ? attrBruto(soma) : ''}>${esc(texto)}</td>`;
    } else if (i === 0) texto = rotulo;
    return `<td${attrClasse(classe(c))}>${esc(texto)}</td>`;
  });
  return `<tr class="${cls}">${celulas.join('')}</tr>`;
}

export function tabelaHtml<T>({ colunas, linhas, legenda, totais, agrupar, vazio = 'Sem registos.' }: OpcoesTabela<T>): string {
  const cabecalho = `<thead><tr>${colunas
    .map((c) => `<th${attrClasse(classe(c).replace('imp-quebra', ''))}${c.largura ? ` style="width:${esc(c.largura)}"` : ''}>${esc(c.titulo)}</th>`)
    .join('')}</tr></thead>`;
  const linhaHtml = (l: T, n: number) =>
    `<tr>${colunas.map((c) => `<td${attrClasse(classe(c))}${c.formato === 'percentagem' ? '' : attrBruto(brutoDe(c, l, n))}>${esc(formatarValor(c, c.valor(l, n), l))}</td>`).join('')}</tr>`;

  let corpo = '';
  if (!linhas.length) corpo = `<tr><td class="imp-vazio" colspan="${colunas.length}">${esc(vazio)}</td></tr>`;
  else if (agrupar) {
    const grupos = new Map<string, T[]>();
    linhas.forEach((l) => {
      const k = agrupar.chave(l);
      grupos.set(k, [...(grupos.get(k) ?? []), l]);
    });
    let n = 0;
    grupos.forEach((ls, k) => {
      corpo += `<tr class="imp-grupo"><td colspan="${colunas.length}">${esc(agrupar.titulo ? agrupar.titulo(k, ls) : k)}</td></tr>`;
      corpo += ls.map((l) => linhaHtml(l, n++)).join('');
      if (agrupar.subtotais) corpo += linhaTotais(colunas, ls, `Subtotal ${k}`, 'imp-subtotal');
    });
  } else corpo = linhas.map(linhaHtml).join('');

  const rodape = totais && linhas.length ? `<tfoot>${linhaTotais(colunas, linhas, typeof totais === 'string' ? totais : 'Total', 'imp-total')}</tfoot>` : '';
  return `<table class="imp-tabela">${legenda ? `<caption>${esc(legenda)}</caption>` : ''}${cabecalho}<tbody>${corpo}</tbody>${rodape}</table>`;
}

/** Bloco de pares «rótulo: valor» (resumos, cabeçalhos de documentos). */
export function pares(itens: [string, ValorCelula][], colunas = 3): string {
  return `<table class="imp-tabela imp-pares" style="width:100%"><tbody>${Array.from({ length: Math.ceil(itens.length / colunas) }, (_, r) =>
    `<tr>${itens
      .slice(r * colunas, r * colunas + colunas)
      .map(([k, v]) => `<td style="white-space:normal"><span style="color:#555">${esc(k)}:</span> ${esc(v ?? '—')}</td>`)
      .join('')}</tr>`,
  ).join('')}</tbody></table>`;
}
