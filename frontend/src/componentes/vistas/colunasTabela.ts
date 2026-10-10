import type { ColumnGroupType, ColumnType, ColumnsType } from 'antd/es/table';
import type { ReactNode } from 'react';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { lerNumeroPt } from '../impressao/excel';
import type { ColunaImpressao, ValorCelula } from '../impressao/tabela';
import { textoDeNo } from '../impressao/texto';
import type { ExtrasColunaVista } from './derivarCartao';

/**
 * Colunas das listas — tipos e funções partilhados por TabelaApi, TabelaComModos e TabelaLegado (impressão, filtros por
 * coluna, totais e menu «Colunas ▾»). Tudo trabalha com TEXTO simples (nada de HTML): os valores vêm do `render` da
 * coluna convertido em texto, ou do valor de `dataIndex`.
 */

/** Extras por coluna para a impressão/PDF (opcionais; o resto é o ColumnType do Ant Design). */
export interface ExtrasColunaImpressao<T> {
  /** `false` = não vai para o papel (por omissão saem todas as colunas com título). */
  exportar?: boolean;
  /** Texto/valor a imprimir (por omissão: o texto do `render`, ou o valor de `dataIndex`). */
  valorImpressao?: (linha: T, indice: number) => ValorCelula;
  /** Total da coluna no rodapé do documento impresso (recebe todas as linhas impressas). */
  totalImpressao?: (linhas: T[]) => string;
}

/** Como se calcula o total de uma coluna na linha «TOTAL:» (G.3). */
export type TotalColuna<T> = true | 'soma' | 'media' | 'contagem' | ((linhas: T[]) => ReactNode);

/** Extras do padrão das listas do sistema anterior (G.1–G.3). Todos opcionais. */
export interface ExtrasColunaLegado<T> {
  /** `false` = sem caixa de filtro nesta coluna (as colunas de acções e sem título nunca têm). */
  filtravel?: boolean;
  /**
   * Nome do parâmetro enviado ao servidor (só TabelaApi): o texto da caixa de filtro desta coluna passa a ser um filtro
   * do endpoint (com atraso de 400 ms) em vez de filtrar só a página carregada.
   */
  filtroServidor?: string;
  /** Texto usado para filtrar (por omissão: o texto da célula). */
  valorFiltro?: (linha: T, indice: number) => string;
  /** Total na linha «TOTAL:» — `true`/`'soma'` (por omissão), `'media'`, `'contagem'` ou uma função própria. */
  total?: TotalColuna<T>;
  /** Formato do total calculado (por omissão: `formatarKz` — «1 234,50»). */
  formatoTotal?: (valor: number) => ReactNode;
  /** `false` = não aparece no menu «Colunas ▾» (fica sempre visível). */
  ocultavel?: boolean;
  /** Começa escondida (o utilizador pode mostrá-la em «Colunas ▾»). */
  ocultaPorOmissao?: boolean;
}

/** Coluna das listas: ColumnType do Ant Design + impressão + cartão da grade + padrão do sistema anterior. */
export type ColunaLegado<T> = ColumnsType<T>[number] & ExtrasColunaImpressao<T> & ExtrasColunaVista & ExtrasColunaLegado<T>;

export type FolhaLegado<T> = ColumnType<T> & ExtrasColunaImpressao<T> & ExtrasColunaVista & ExtrasColunaLegado<T>;

export function folhas<T>(colunas: readonly ColunaLegado<T>[]): FolhaLegado<T>[] {
  return colunas.flatMap((c) => {
    const filhos = (c as ColumnGroupType<T>).children;
    return filhos?.length ? folhas(filhos as ColunaLegado<T>[]) : [c as FolhaLegado<T>];
  });
}

export function valorDe<T>(linha: T, dataIndex: ColumnType<T>['dataIndex']): unknown {
  if (dataIndex === undefined || dataIndex === null) return undefined;
  const caminho = Array.isArray(dataIndex) ? dataIndex : [dataIndex];
  return caminho.reduce<unknown>((v, k) => (v && typeof v === 'object' ? (v as Record<string | number, unknown>)[k as string | number] : undefined), linha);
}

/** Título da coluna em texto simples ('' se não tiver). */
export function tituloTexto<T>(c: FolhaLegado<T>): string {
  return textoDeNo(typeof c.title === 'function' ? null : (c.title as ReactNode));
}

/** Identificador estável da coluna (key → dataIndex → título). */
export function chaveColuna<T>(c: FolhaLegado<T>, indice: number): string {
  if (c.key !== undefined && c.key !== null) return String(c.key);
  if (c.dataIndex !== undefined && c.dataIndex !== null) return Array.isArray(c.dataIndex) ? c.dataIndex.join('.') : String(c.dataIndex);
  return tituloTexto(c) || `#${indice}`;
}

const RE_ACCOES = /^(acoes|accoes|acao|accao|opcoes)$/;
const semAcentos = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '');

/** Coluna de acções (como no antigo: título «Ações»/«Acções»/«Opções», sem título com `render`, ou `accoes: true`). */
export function eColunaAccoes<T>(c: FolhaLegado<T>): boolean {
  if (c.accoes) return true;
  if (String(c.key ?? '') === 'accoes' || String(c.key ?? '') === '__accoes') return true;
  const t = semAcentos(tituloTexto(c)).toLowerCase().replace(/[^a-z]/g, '');
  return RE_ACCOES.test(t) || (!t && !!c.render && (c.dataIndex === undefined || c.dataIndex === null));
}

export function textoColuna<T>(c: ColumnType<T> & ExtrasColunaImpressao<T>, linha: T, i: number): ValorCelula {
  if (c.valorImpressao) return c.valorImpressao(linha, i);
  const bruto = valorDe(linha, c.dataIndex);
  if (!c.render) return bruto === null || bruto === undefined ? '' : typeof bruto === 'object' ? JSON.stringify(bruto) : (bruto as ValorCelula);
  const r = c.render(bruto, linha, i) as ReactNode | { children?: ReactNode };
  // render pode devolver { children, props } (RenderedCell).
  const no = r && typeof r === 'object' && !Array.isArray(r) && !('$$typeof' in r) && 'children' in r ? r.children : (r as ReactNode);
  return textoDeNo(no);
}

/**
 * Valor bruto de uma célula para o Excel, se for coerente com o texto mostrado: número (ou texto numérico da API, ex.
 * «1234.50») cujo texto formatado lê o mesmo valor, ou data ISO mostrada como dd/mm/aaaa. Caso contrário `undefined`
 * (o Excel lê o texto).
 */
export function brutoConsistente(bruto: unknown, texto: ValorCelula): ValorCelula {
  if (texto === null || texto === undefined || typeof texto === 'boolean') return undefined;
  const t = String(texto).trim();
  if ((typeof bruto === 'number' && Number.isFinite(bruto)) || (typeof bruto === 'string' && /^-?\d+(\.\d+)?$/.test(bruto.trim()))) {
    if (/%\s*$/.test(t)) return undefined;
    // inteiros sem separadores (NIF, códigos, n.º de documento) ficam com a regra do alinhamento do excel.ts
    const lido = lerNumeroPt(t, false);
    if (!lido) return undefined;
    const n = Number(bruto);
    return Math.abs(lido.valor - n) <= 0.5 * 10 ** -lido.casas + 1e-9 ? (typeof bruto === 'string' ? bruto.trim() : n) : undefined;
  }
  if (typeof bruto === 'string' && /^\d{4}-\d{2}-\d{2}/.test(bruto)) {
    const [a, m, d] = bruto.slice(0, 10).split('-');
    return t.startsWith(`${d}/${m}/${a}`) ? bruto : undefined;
  }
  return undefined;
}

/**
 * Colunas visíveis do Ant Design → colunas de impressão (texto simples dos `render`). Saem as colunas com título
 * (as de acções, sem título, ficam de fora) e as marcadas `exportar: true`; `linhas` calcula os `totalImpressao`.
 */
export function colunasParaImpressao<T>(colunas: readonly ColunaLegado<T>[], linhas?: T[]): ColunaImpressao<T>[] {
  return folhas(colunas)
    .map((c) => ({ c, titulo: tituloTexto(c) }))
    .filter(({ c, titulo }) => c.exportar !== false && !c.hidden && (titulo || c.exportar === true))
    .map(({ c, titulo }) => ({
      titulo,
      alinhamento: c.align === 'right' ? 'direita' : c.align === 'center' ? 'centro' : 'esquerda',
      total: c.totalImpressao && linhas ? c.totalImpressao(linhas) : c.total && typeof c.total !== 'function' && linhas ? textoTotal(c, linhas) : undefined,
      valor: (linha: T, i: number): ValorCelula => textoColuna(c, linha, i),
      // Excel: o valor bruto (precisão total) só quando o texto impresso o representa (nunca um id por trás de um nome)
      bruto: (linha: T, i: number): ValorCelula => brutoConsistente(valorDe(linha, c.dataIndex), textoColuna(c, linha, i)),
    }));
}

/** Número de uma célula: valor bruto numérico, senão o texto mostrado lido em pt-PT («1 234,50 Kz»). */
export function numeroCelula<T>(c: FolhaLegado<T>, linha: T, i: number): number | null {
  const bruto = valorDe(linha, c.dataIndex);
  if (typeof bruto === 'number' && Number.isFinite(bruto)) return bruto;
  if (typeof bruto === 'string' && /^-?\d+(\.\d+)?$/.test(bruto.trim())) return Number(bruto);
  const t = textoColuna(c, linha, i);
  if (typeof t === 'number') return Number.isFinite(t) ? t : null;
  if (typeof t !== 'string' || !t.trim()) return null;
  return lerNumeroPt(t)?.valor ?? null;
}


/** Valor numérico do total de uma coluna (`null` se a coluna não tiver total calculado). */
export function calcularTotal<T>(c: FolhaLegado<T>, linhas: readonly T[]): number | null {
  if (!c.total || typeof c.total === 'function') return null;
  if (c.total === 'contagem') return linhas.length;
  const nums = linhas.map((l, i) => numeroCelula(c, l, i)).filter((n): n is number => n !== null);
  const soma = nums.reduce((a, b) => a + b, 0);
  // arredondamento a 2 casas para não mostrar resíduos de vírgula flutuante (0,1 + 0,2)
  const r = c.total === 'media' ? (nums.length ? soma / nums.length : 0) : soma;
  return Math.round(r * 100) / 100;
}

/** Conteúdo da célula de total (ReactNode) de uma coluna. */
export function conteudoTotal<T>(c: FolhaLegado<T>, linhas: readonly T[]): ReactNode {
  if (!c.total) return null;
  if (typeof c.total === 'function') return c.total([...linhas]);
  const v = calcularTotal(c, linhas) ?? 0;
  if (c.formatoTotal) return c.formatoTotal(v);
  return c.total === 'contagem' ? formatarNumero(v) : formatarKz(v);
}

/** Texto do total (impressão). */
export function textoTotal<T>(c: FolhaLegado<T>, linhas: readonly T[]): string {
  return textoDeNo(conteudoTotal(c, linhas));
}
